<?php
/**
 * Sync engine — reads a WC product and pushes it to Studio.
 *
 * Direction: WooCommerce → Studio.
 *
 * MVP scope (per PLAN.md):
 *   - Reads WC_Product (title, sku, descriptions, weight, price, images).
 *   - Builds a WcProductImportDTO from it + the user-chosen overrides
 *     (saleUnitId, purchaseUnitId, depositId).
 *   - Resolves taxRate from the WC product's tax class when possible (D10).
 *   - Pushes via the API client: POST on first sync, PUT on re-sync.
 *   - Computes a sha256 hash of synced fields for no-op skip (D15).
 *   - Mirrors the result in the local mapping table.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Sync_Engine {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Sync a single WC product to Studio.
	 *
	 * @param int   $wc_product_id WC post id.
	 * @param array $overrides {
	 *     @type string $saleUnitId     Required (D8: Article needs a sale unit).
	 *     @type string $purchaseUnitId Optional.
	 *     @type string $depositId      Optional.
	 *     @type bool   $published      Whether to publish in Studio (default: true).
	 * }
	 * @return array {
	 *     @type bool   $success
	 *     @type int    $wc_product_id
	 *     @type string $studio_article_id  Present on success.
	 *     @type string $reference          Present on success.
	 *     @type bool   $created            True on first sync, false on update.
	 *     @type bool   $price_changed      False on create.
	 *     @type string $payload_hash
	 *     @type string $error              Present only on failure.
	 * }
	 */
	public function sync_product( $wc_product_id, $overrides = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->fail( 'WooCommerce not active.' );
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product ) {
			return $this->fail( sprintf( 'WC product %d not found.', $wc_product_id ) );
		}

		$sale_unit_id = isset( $overrides['saleUnitId'] ) ? $overrides['saleUnitId'] : '';
		if ( '' === $sale_unit_id ) {
			return $this->fail( 'saleUnitId is required (D8: Article needs a sale unit).' );
		}

		$api   = soldx_api();
		$org_id = Soldx_Auth::org_id();

		// Upload images to Studio's S3 before building the DTO.
		// Caches by attachment ID so re-syncing the same image doesn't
		// create a duplicate S3 object (Studio appends a random suffix).
		// Non-fatal: if upload fails, we continue without images.
		list( $media_key, $gallery_keys ) = $this->resolve_images( $product, $api, $org_id );

		$dto     = $this->build_dto( $product, $overrides, $media_key, $gallery_keys );
		$store   = soldx_store();
		$hash    = $dto['hash'];
		$created = false;
		$price_changed = false;
		$studio_article_id = '';
		$reference = '';

		try {
			// Is this product already synced? Decide POST vs PUT (D15).
			$existing = $store->get_by_wc_id( $wc_product_id );

			if ( $existing && ! empty( $existing->studio_article_id ) ) {
				// Local mapping exists — try PUT (idempotent update).
				// If Studio has no mapping (stale local entry, DB reset,
				// etc.), fall back to POST to re-create.
				try {
					$result = $api->update_product( $wc_product_id, $dto );
					$created = false;
					$price_changed = ! empty( $result['priceChanged'] );
					$studio_article_id = isset( $result['idArticle'] ) ? $result['idArticle'] : $existing->studio_article_id;
					$reference = isset( $result['reference'] ) ? $result['reference'] : '';
				} catch ( Soldx_Api_Exception $e ) {
					if ( 404 === (int) $e->getCode() ) {
						// Stale local mapping — Studio doesn't know this product.
						// Delete the stale entry and re-create via POST.
						$store->delete_by_wc_id( $wc_product_id );
						$result = $api->push_product( $dto );
						$created = ! empty( $result['created'] );
						$studio_article_id = isset( $result['idArticle'] ) ? $result['idArticle'] : '';
						$reference = isset( $result['reference'] ) ? $result['reference'] : '';
					} else {
						throw $e;
					}
				}
			} else {
				// Not yet imported — POST (atomic create). 409 means someone else
				// raced us; fall back to PUT to recover.
				try {
					$result = $api->push_product( $dto );
				} catch ( Soldx_Api_Exception $e ) {
					if ( 409 === (int) $e->getCode() ) {
						$result = $api->update_product( $wc_product_id, $dto );
						$price_changed = ! empty( $result['priceChanged'] );
					} else {
						throw $e;
					}
				}
				$created = ! empty( $result['created'] );
				$studio_article_id = isset( $result['idArticle'] ) ? $result['idArticle'] : '';
				$reference = isset( $result['reference'] ) ? $result['reference'] : '';
			}
		} catch ( Soldx_Api_Exception $e ) {
			return $this->fail( $e->getMessage() );
		}

		// Mirror locally so the UI can show "Synced" without a round-trip.
		$store->upsert( array(
			'studio_article_id' => $studio_article_id,
			'wc_product_id'     => $wc_product_id,
			'wc_sku'            => $product->get_sku(),
			'payload_hash'      => $hash,
		) );

		return array(
			'success'           => true,
			'wc_product_id'     => $wc_product_id,
			'studio_article_id' => $studio_article_id,
			'reference'         => $reference,
			'created'           => $created,
			'price_changed'     => $price_changed,
			'payload_hash'      => $hash,
		);
	}

	// ------------------------------------------------------------------
	// DTO construction
	// ------------------------------------------------------------------

	/**
	 * Build the WcProductImportDTO from a WC_Product + user overrides.
	 *
	 * @param WC_Product $product
	 * @param array      $overrides
	 * @param string|null $media_key    S3 key for the main image, or null.
	 * @param array       $gallery_keys S3 keys for gallery images.
	 * @return array WcProductImportDTO (see PLAN.md §7).
	 */
	private function build_dto( $product, $overrides, $media_key = null, $gallery_keys = array() ) {
		$id    = (string) $product->get_id();
		$sku   = $product->get_sku();
		$title = $product->get_name();

		// Pricing — WC regular_price is the base price for Studio.
		// WC sale_price (if set and lower) becomes a Studio Discount.
		$regular_raw   = $product->get_regular_price();
		$regular_price = ( '' !== $regular_raw && is_numeric( $regular_raw ) ) ? (float) $regular_raw : 0.0;
		$sale_raw      = $product->get_sale_price();
		$sale_price    = ( '' !== $sale_raw && is_numeric( $sale_raw ) ) ? (float) $sale_raw : 0.0;

		// Fall back to get_price() if regular is empty (e.g. variations).
		$base_price = $regular_price > 0 ? $regular_price : (float) $product->get_price();

		// Tax rate hint (D10).
		$tax_rate = $this->estimate_tax_rate( $product );

		// Published: defaults to true unless explicitly unchecked.
		$published = isset( $overrides['published'] ) ? (bool) $overrides['published'] : true;

		// Media: S3 keys uploaded before DTO construction.
		$gallery_keys = is_array( $gallery_keys ) ? $gallery_keys : array();

		// Discount: WC sale_price < regular_price → percent discount.
		$discount_percent = 0.0;
		$discount_start   = null;
		$discount_end     = null;
		if ( $sale_price > 0 && $sale_price < $base_price && $base_price > 0 ) {
			$discount_percent = round( ( 1.0 - $sale_price / $base_price ) * 100, 4 );
			$from = $product->get_date_on_sale_from();
			$to   = $product->get_date_on_sale_to();
			$discount_start = $from ? $from->format( 'c' ) : null;
			$discount_end   = $to ? $to->format( 'c' ) : null;
		}

		// Categories: resolve WC product_cat IDs → Studio category IDs.
		$wc_cat_ids  = $product->get_category_ids();
		$category_ids = Soldx_Admin_Categories::resolve( $wc_cat_ids );

		$dto = array(
			'externalId'        => $id,
			'externalSlug'      => '' !== $sku ? $sku : null,
			'designation'       => $title,
			'published'         => $published,
			'slug'              => $product->get_slug(),
			'shortDescription'  => $product->get_short_description() ?: null,
			'description'       => $product->get_description() ?: null,
			'ean'               => $this->extract_ean( $product ),
			'weight'            => $this->weight_as_number( $product->get_weight() ),
			'productType'       => $this->map_product_type( $product ),
			'isService'         => $product->is_virtual() && 'SERVICE' === $this->map_product_type( $product ),
			'isDigitalProduct'  => $product->is_downloadable(),
			'media'             => $media_key,
			'gallery'           => $gallery_keys,
			'pricing'           => array(
				'salePrice'     => $base_price,
				'purchasePrice' => 0,
				'taxRate'       => $tax_rate,
			),
			'discountPercent'   => $discount_percent > 0 ? $discount_percent : null,
			'discountStartDate' => $discount_start,
			'discountEndDate'   => $discount_end,
			'saleUnitId'        => isset( $overrides['saleUnitId'] ) ? $overrides['saleUnitId'] : '',
			'purchaseUnitId'    => isset( $overrides['purchaseUnitId'] ) ? $overrides['purchaseUnitId'] : null,
			'depositId'         => isset( $overrides['depositId'] ) ? $overrides['depositId'] : null,
			'categoryIds'       => $category_ids,
			'hash'              => '', // filled below
		);

		$dto['hash'] = $this->payload_hash( $dto );
		return $dto;
	}

	/**
	 * Map a WC_Product to a Studio productType enum.
	 *
	 * @param WC_Product $product
	 * @return string 'PHYSICAL_PRODUCT' | 'SERVICE' | 'DIGITAL_PRODUCT'
	 */
	private function map_product_type( $product ) {
		if ( $product->is_downloadable() ) {
			return 'DIGITAL_PRODUCT';
		}
		if ( $product->is_virtual() ) {
			return 'SERVICE';
		}
		return 'PHYSICAL_PRODUCT';
	}

	/**
	 * Extract the EAN from common WC meta keys (stored by various plugins).
	 *
	 * @param WC_Product $product
	 * @return string|null
	 */
	private function extract_ean( $product ) {
		foreach ( array( '_ean', '_global_unique_id', '_gtin', '_mpn' ) as $key ) {
			$val = get_post_meta( $product->get_id(), $key, true );
			if ( is_string( $val ) && '' !== trim( $val ) ) {
				return trim( $val );
			}
		}
		return null;
	}

	/**
	 * Convert WC's stored weight (string, may be empty) to a float or null.
	 *
	 * @param string $weight
	 * @return float|null
	 */
	private function weight_as_number( $weight ) {
		if ( '' === $weight || null === $weight ) {
			return null;
		}
		$float = (float) $weight;
		return $float > 0 ? $float : null;
	}

	/**
	 * Resolve main + gallery images to S3 keys, caching by attachment ID.
	 *
	 * Studio appends a random suffix to every upload, so re-uploading the
	 * same image on every sync creates orphaned S3 objects. We cache the
	 * attachment ID → S3 key mapping in post_meta so unchanged images are
	 * never re-uploaded.
	 *
	 * Non-fatal: upload failures return null/empty so the sync continues.
	 *
	 * @param WC_Product       $product
	 * @param Soldx_Api_Client $api
	 * @param string           $org_id
	 * @return array {
	 *     @type string|null 0  Main image S3 key, or null.
	 *     @type string[]    1  Gallery S3 keys (preserves order).
	 * }
	 */
	private function resolve_images( $product, $api, $org_id ) {
		$product_id = $product->get_id();
		$cache      = get_post_meta( $product_id, '_soldx_image_cache', true );
		if ( ! is_array( $cache ) ) {
			$cache = array( 'main' => array(), 'gallery' => array() );
		}
		if ( ! isset( $cache['gallery'] ) || ! is_array( $cache['gallery'] ) ) {
			$cache['gallery'] = array();
		}

		// --- Main image ---
		$media_key = null;
		$thumb_id  = $product->get_image_id();
		if ( $thumb_id ) {
			$thumb_str = (string) $thumb_id;
			if ( isset( $cache['main']['attachment_id'] )
				&& (string) $cache['main']['attachment_id'] === $thumb_str
				&& ! empty( $cache['main']['s3_key'] )
			) {
				// Cache hit — reuse the previously uploaded S3 key.
				$media_key = $cache['main']['s3_key'];
			} else {
				// New or changed image — upload and cache the key.
				$file_path = get_attached_file( $thumb_id );
				if ( $file_path && file_exists( $file_path ) ) {
					try {
						$media_key = $api->upload_image( $file_path, $org_id, basename( $file_path ) );
						$cache['main'] = array(
							'attachment_id' => $thumb_id,
							's3_key'        => $media_key,
						);
					} catch ( Soldx_Api_Exception $e ) {
						// Non-fatal; continue without main image.
					}
				}
			}
		}

		// --- Gallery images ---
		$gallery_keys = array();
		$ids          = $product->get_gallery_image_ids();
		if ( is_array( $ids ) ) {
			foreach ( $ids as $attachment_id ) {
				$id_str = (string) $attachment_id;
				if ( isset( $cache['gallery'][ $id_str ] ) && ! empty( $cache['gallery'][ $id_str ] ) ) {
					// Cache hit — reuse.
					$gallery_keys[ $id_str ] = $cache['gallery'][ $id_str ];
				} else {
					// New image — upload.
					$file_path = get_attached_file( $attachment_id );
					if ( ! $file_path || ! file_exists( $file_path ) ) {
						continue;
					}
					try {
						$key = $api->upload_image( $file_path, $org_id, basename( $file_path ) );
						$gallery_keys[ $id_str ] = $key;
					} catch ( Soldx_Api_Exception $e ) {
						// Skip this image, continue with the rest.
					}
				}
			}
			// Update gallery cache to match current set (drops removed images).
			$cache['gallery'] = $gallery_keys;
			// Re-index to sequential array for the DTO.
			$gallery_keys = array_values( $gallery_keys );
		}

		// Persist cache so future syncs skip unchanged images.
		update_post_meta( $product_id, '_soldx_image_cache', $cache );

		return array( $media_key, $gallery_keys );
	}

	/**
	 * Best-effort tax rate from the WC product's tax class (D10).
	 *
	 * Returns the rate percentage as a float, or null when unknown.
	 * Studio's resolveTaxId() will try to match Tax.value against this.
	 *
	 * @param WC_Product $product
	 * @return float|null
	 */
	private function estimate_tax_rate( $product ) {
		if ( ! function_exists( 'WC' ) || ! WC()->tax ) {
			return null;
		}
		$tax_class = $product->get_tax_class();
		// WC_Tax::get_rates_from_location is heavy; we use the simpler
		// get_rates for the default (shop) location. This is only a hint.
		$rates = WC_Tax::get_rates( $tax_class );
		if ( empty( $rates ) ) {
			return null;
		}
		$first = reset( $rates );
		return isset( $first['rate'] ) ? (float) $first['rate'] : null;
	}

	// ------------------------------------------------------------------
	// Hashing
	// ------------------------------------------------------------------

	/**
	 * Compute a stable sha256 of the fields that participate in the push,
	 * so later syncs can skip when nothing changed (D15).
	 *
	 * @param array $dto
	 * @return string 64-char hex
	 */
	private function payload_hash( $dto ) {
		$relevant = array(
			'designation'       => isset( $dto['designation'] ) ? $dto['designation'] : null,
			'slug'              => isset( $dto['slug'] ) ? $dto['slug'] : null,
			'shortDescription'  => isset( $dto['shortDescription'] ) ? $dto['shortDescription'] : null,
			'description'       => isset( $dto['description'] ) ? $dto['description'] : null,
			'weight'            => isset( $dto['weight'] ) ? $dto['weight'] : null,
			'pricing'           => isset( $dto['pricing'] ) ? $dto['pricing'] : null,
			'discountPercent'   => isset( $dto['discountPercent'] ) ? $dto['discountPercent'] : null,
			'discountStartDate' => isset( $dto['discountStartDate'] ) ? $dto['discountStartDate'] : null,
			'discountEndDate'   => isset( $dto['discountEndDate'] ) ? $dto['discountEndDate'] : null,
			'media'             => isset( $dto['media'] ) ? $dto['media'] : null,
			'gallery'           => isset( $dto['gallery'] ) ? $dto['gallery'] : null,
			'saleUnitId'        => isset( $dto['saleUnitId'] ) ? $dto['saleUnitId'] : null,
			'purchaseUnitId'    => isset( $dto['purchaseUnitId'] ) ? $dto['purchaseUnitId'] : null,
			'depositId'         => isset( $dto['depositId'] ) ? $dto['depositId'] : null,
			'categoryIds'       => isset( $dto['categoryIds'] ) ? $dto['categoryIds'] : null,
		);
		return hash( 'sha256', wp_json_encode( $relevant ) );
	}

	private function fail( $message ) {
		return array(
			'success' => false,
			'error'   => $message,
		);
	}
}
