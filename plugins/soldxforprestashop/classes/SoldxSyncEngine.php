<?php
/**
 * Sync engine — reads a PrestaShop product and pushes it to Studio.
 *
 * Direction: PrestaShop → Studio.
 *
 * Reads PS Product (name, reference, descriptions, weight, price, images).
 * Builds a WcProductImportDTO-compatible payload from it + user overrides.
 * Pushes via the API client: POST on first sync, PUT on re-sync.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SoldxSyncEngine
{
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Sync a single PS product to Studio.
     *
     * @param int   $ps_product_id
     * @param array $overrides { saleUnitId, purchaseUnitId, depositId, tagIds, published }
     * @return array { success, ps_product_id, studio_article_id, reference, created, ... }
     */
    public function syncProduct($ps_product_id, $overrides = [])
    {
        $id_lang = (int) Context::getContext()->language->id;
        $product = new Product($ps_product_id, true, $id_lang);
        if (!Validate::isLoadedObject($product)) {
            return $this->fail('PS product ' . (int) $ps_product_id . ' not found.');
        }

        $sale_unit_id = isset($overrides['saleUnitId']) ? $overrides['saleUnitId'] : '';
        if ('' === $sale_unit_id) {
            return $this->fail('saleUnitId is required.');
        }

        $api = Soldxforprestashop::getApiClient();
        $org_id = SoldxAuth::orgId();

        // Upload images.
        list($media_key, $gallery_keys) = $this->resolveImages($ps_product_id, $api, $org_id);

        $dto = $this->buildDto($product, $overrides, $media_key, $gallery_keys, $id_lang);
        $store = Soldxforprestashop::getMappingStore();
        $hash = $dto['hash'];
        $created = false;
        $price_changed = false;
        $studio_article_id = '';
        $reference = '';

        try {
            $existing = $store->getByPsId($ps_product_id);

            if ($existing && !empty($existing['studio_article_id'])) {
                // Local mapping exists — try PUT.
                try {
                    $result = $api->updateProduct($ps_product_id, $dto);
                    $created = false;
                    $price_changed = !empty($result['priceChanged']);
                    $studio_article_id = isset($result['idArticle']) ? $result['idArticle'] : $existing['studio_article_id'];
                    $reference = isset($result['reference']) ? $result['reference'] : '';
                } catch (SoldxApiException $e) {
                    if (404 === (int) $e->getCode()) {
                        $store->deleteByPsId($ps_product_id);
                        $result = $api->pushProduct($dto);
                        $created = !empty($result['created']);
                        $studio_article_id = isset($result['idArticle']) ? $result['idArticle'] : '';
                        $reference = isset($result['reference']) ? $result['reference'] : '';
                    } else {
                        throw $e;
                    }
                }
            } else {
                // Not yet imported — POST. 409 means race; fall back to PUT.
                try {
                    $result = $api->pushProduct($dto);
                } catch (SoldxApiException $e) {
                    if (409 === (int) $e->getCode()) {
                        $result = $api->updateProduct($ps_product_id, $dto);
                        $price_changed = !empty($result['priceChanged']);
                    } else {
                        throw $e;
                    }
                }
                $created = !empty($result['created']);
                $studio_article_id = isset($result['idArticle']) ? $result['idArticle'] : '';
                $reference = isset($result['reference']) ? $result['reference'] : '';
            }
        } catch (SoldxApiException $e) {
            return $this->fail($e->getMessage());
        }

        // Mirror locally.
        $store->upsert([
            'studio_article_id' => $studio_article_id,
            'ps_product_id' => $ps_product_id,
            'ps_reference' => $product->reference,
            'payload_hash' => $hash,
        ]);

        return [
            'success' => true,
            'ps_product_id' => $ps_product_id,
            'studio_article_id' => $studio_article_id,
            'reference' => $reference,
            'created' => $created,
            'price_changed' => $price_changed,
            'payload_hash' => $hash,
        ];
    }

    // ------------------------------------------------------------------
    // DTO construction
    // ------------------------------------------------------------------

    /**
     * Build the import DTO from a PS Product + user overrides.
     *
     * @param Product $product
     * @param array   $overrides
     * @param string|null $media_key
     * @param array       $gallery_keys
     * @param int         $id_lang
     * @return array
     */
    private function buildDto($product, $overrides, $media_key = null, $gallery_keys = [], $id_lang = null)
    {
        $id = (string) $product->id;
        $reference = $product->reference;

        // Multilingual fields — use the current language.
        $name = is_array($product->name) ? (isset($product->name[$id_lang]) ? $product->name[$id_lang] : reset($product->name)) : (string) $product->name;

        $description = '';
        if (is_array($product->description)) {
            $description = isset($product->description[$id_lang]) ? $product->description[$id_lang] : '';
        } else {
            $description = (string) $product->description;
        }

        $description_short = '';
        if (is_array($product->description_short)) {
            $description_short = isset($product->description_short[$id_lang]) ? $product->description_short[$id_lang] : '';
        } else {
            $description_short = (string) $product->description_short;
        }

        $link_rewrite = '';
        if (is_array($product->link_rewrite)) {
            $link_rewrite = isset($product->link_rewrite[$id_lang]) ? $product->link_rewrite[$id_lang] : '';
        } else {
            $link_rewrite = (string) $product->link_rewrite;
        }

        // Pricing — PS stores price tax-excl in ps_product.price.
        $base_price = (float) $product->price;
        // Specific price (sale price) — check if a specific price exists.
        $specific_price = $this->getSpecificPrice($product->id);
        $sale_price = $specific_price;

        // Tax rate hint.
        $tax_rate = $this->estimateTaxRate($product->id);

        $published = isset($overrides['published']) ? (bool) $overrides['published'] : true;

        $gallery_keys = is_array($gallery_keys) ? $gallery_keys : [];

        // Discount: specific price < base price → percent discount.
        $discount_percent = 0.0;
        $discount_start = null;
        $discount_end = null;
        if ($sale_price > 0 && $sale_price < $base_price && $base_price > 0) {
            $discount_percent = round((1.0 - $sale_price / $base_price) * 100, 4);
            $sp = $this->getSpecificPriceDates($product->id);
            if ($sp) {
                $discount_start = $sp['from'];
                $discount_end = $sp['to'];
            }
        }

        // Categories: resolve PS category IDs → Studio category IDs.
        $ps_cat_ids = Product::getProductCategories((int) $product->id);
        $category_ids = SoldxCategoryResolver::resolve($ps_cat_ids);

        // Weight.
        $weight = (float) $product->weight;
        if ($weight <= 0) {
            $weight = null;
        }

        // EAN.
        $ean = !empty($product->ean13) ? $product->ean13 : null;

        // Product type.
        $is_virtual = (bool) $product->is_virtual;
        $product_type = $is_virtual ? 'SERVICE' : 'PHYSICAL_PRODUCT';

        $dto = [
            'externalId' => $id,
            'externalSlug' => '' !== $reference ? $reference : null,
            'designation' => $name,
            'published' => $published,
            'slug' => $link_rewrite ?: null,
            'shortDescription' => $description_short ?: null,
            'description' => $description ?: null,
            'ean' => $ean,
            'weight' => $weight,
            'productType' => $product_type,
            'isService' => $is_virtual && 'SERVICE' === $product_type,
            'isDigitalProduct' => false,
            'media' => $media_key,
            'gallery' => $gallery_keys,
            'pricing' => [
                'salePrice' => $base_price,
                'purchasePrice' => 0,
                'taxRate' => $tax_rate,
            ],
            'discountPercent' => $discount_percent > 0 ? $discount_percent : null,
            'discountStartDate' => $discount_start,
            'discountEndDate' => $discount_end,
            'saleUnitId' => isset($overrides['saleUnitId']) ? $overrides['saleUnitId'] : '',
            'purchaseUnitId' => isset($overrides['purchaseUnitId']) ? $overrides['purchaseUnitId'] : null,
            'depositId' => isset($overrides['depositId']) ? $overrides['depositId'] : null,
            'categoryIds' => $category_ids,
            'tagIds' => isset($overrides['tagIds']) ? $overrides['tagIds'] : [],
            'hash' => '',
        ];

        $dto['hash'] = $this->payloadHash($dto);
        return $dto;
    }

    /**
     * Get the specific (sale) price for a product, if any.
     *
     * @param int $id_product
     * @return float 0 if no specific price.
     */
    private function getSpecificPrice($id_product)
    {
        $specific = SpecificPrice::getSpecificPrice(
            (int) $id_product,
            (int) Shop::getContextShopID(),
            null, // id_currency
            null, // id_country
            null, // id_group
            1,    // quantity
            null, // id_product_attribute
            null, // id_customer
            null, // id_cart
            0     // real_quantity
        );

        if ($specific && isset($specific['price']) && $specific['price'] >= 0) {
            $base = (float) Db::getInstance()->getValue(
                'SELECT price FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . (int) $id_product
            );
            if ((float) $specific['price'] > 0 && (float) $specific['price'] < $base) {
                return (float) $specific['price'];
            }
            if (!empty($specific['reduction']) && $specific['reduction'] > 0) {
                if ($specific['reduction_type'] === 'percentage') {
                    return $base * (1 - (float) $specific['reduction']);
                }
                return $base - (float) $specific['reduction'];
            }
        }
        return 0.0;
    }

    /**
     * Get the from/to dates of the specific price.
     *
     * @param int $id_product
     * @return array|null { from, to } ISO strings or null.
     */
    private function getSpecificPriceDates($id_product)
    {
        $sql = 'SELECT `from`, `to` FROM ' . _DB_PREFIX_ . 'specific_price
                WHERE id_product = ' . (int) $id_product . '
                  AND (`from` != "0000-00-00 00:00:00" OR `to` != "0000-00-00 00:00:00")
                ORDER BY id_specific_price DESC';
        $row = Db::getInstance()->getRow($sql);
        if (!$row) {
            return null;
        }
        $from = null;
        $to = null;
        if (!empty($row['from']) && $row['from'] !== '0000-00-00 00:00:00') {
            $from = date('c', strtotime($row['from']));
        }
        if (!empty($row['to']) && $row['to'] !== '0000-00-00 00:00:00') {
            $to = date('c', strtotime($row['to']));
        }
        return ['from' => $from, 'to' => $to];
    }

    /**
     * Resolve main + gallery images to S3 keys, caching by image id.
     *
     * @param int $ps_product_id
     * @param SoldxApiClient $api
     * @param string $org_id
     * @return array { string|null, string[] }
     */
    private function resolveImages($ps_product_id, $api, $org_id)
    {
        $id_lang = (int) Context::getContext()->language->id;
        $images = Image::getImages($id_lang, (int) $ps_product_id);
        if (empty($images)) {
            return [null, []];
        }

        // Cache key in ps_configuration (JSON).
        $cache_key = 'SOLDX_IMG_CACHE_' . (int) $ps_product_id;
        $cache_json = Configuration::get($cache_key);
        $cache = $cache_json ? json_decode($cache_json, true) : [];
        if (!is_array($cache)) {
            $cache = [];
        }

        $media_key = null;
        $gallery_keys = [];

        foreach ($images as $idx => $img) {
            $id_image = (int) $img['id_image'];
            $is_cover = $img['cover'] == 1;

            // Cache hit?
            if (isset($cache[$id_image]) && !empty($cache[$id_image])) {
                $key = $cache[$id_image];
            } else {
                // Build path: img/p/{folder}/{id}.{ext}
                $image_obj = new Image($id_image);
                $file_path = _PS_PROD_IMG_DIR_ . $image_obj->getImgFolder() . $id_image . '.' . $image_obj->image_format;

                if (!file_exists($file_path)) {
                    continue;
                }

                try {
                    $key = $api->uploadImage($file_path, $org_id, basename($file_path));
                    $cache[$id_image] = $key;
                } catch (SoldxApiException $e) {
                    continue;
                }
            }

            if ($is_cover && $idx === 0) {
                $media_key = $key;
            } else {
                $gallery_keys[] = $key;
            }
        }

        // Persist cache.
        Configuration::updateValue($cache_key, json_encode($cache));

        // If no cover was found but media_key is null, use first image.
        if ($media_key === null && !empty($gallery_keys)) {
            $media_key = array_shift($gallery_keys);
        }

        return [$media_key, $gallery_keys];
    }

    /**
     * Best-effort tax rate for a product.
     *
     * @param int $id_product
     * @return float|null
     */
    private function estimateTaxRate($id_product)
    {
        $id_lang = (int) Context::getContext()->language->id;
        $rate = Tax::getProductTaxRate((int) $id_product, null, Context::getContext());
        if (isset($rate['rate']) && $rate['rate'] > 0) {
            return (float) $rate['rate'];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Hashing
    // ------------------------------------------------------------------

    private function payloadHash($dto)
    {
        $relevant = [
            'designation' => isset($dto['designation']) ? $dto['designation'] : null,
            'slug' => isset($dto['slug']) ? $dto['slug'] : null,
            'shortDescription' => isset($dto['shortDescription']) ? $dto['shortDescription'] : null,
            'description' => isset($dto['description']) ? $dto['description'] : null,
            'weight' => isset($dto['weight']) ? $dto['weight'] : null,
            'pricing' => isset($dto['pricing']) ? $dto['pricing'] : null,
            'discountPercent' => isset($dto['discountPercent']) ? $dto['discountPercent'] : null,
            'discountStartDate' => isset($dto['discountStartDate']) ? $dto['discountStartDate'] : null,
            'discountEndDate' => isset($dto['discountEndDate']) ? $dto['discountEndDate'] : null,
            'media' => isset($dto['media']) ? $dto['media'] : null,
            'gallery' => isset($dto['gallery']) ? $dto['gallery'] : null,
            'saleUnitId' => isset($dto['saleUnitId']) ? $dto['saleUnitId'] : null,
            'purchaseUnitId' => isset($dto['purchaseUnitId']) ? $dto['purchaseUnitId'] : null,
            'depositId' => isset($dto['depositId']) ? $dto['depositId'] : null,
            'categoryIds' => isset($dto['categoryIds']) ? $dto['categoryIds'] : null,
            'tagIds' => isset($dto['tagIds']) ? $dto['tagIds'] : null,
        ];
        return hash('sha256', json_encode($relevant));
    }

    private function fail($message)
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }
}

/**
 * Resolve PS category IDs → Studio category IDs using stored mapping.
 * Simple standalone class to avoid circular dependencies.
 */
class SoldxCategoryResolver
{
    public static function resolve($ps_cat_ids)
    {
        if (empty($ps_cat_ids)) {
            return [];
        }
        $mapping_json = Configuration::get('SOLDX_CATEGORY_MAP');
        $mapping = $mapping_json ? json_decode($mapping_json, true) : [];
        if (!is_array($mapping)) {
            $mapping = [];
        }

        $resolved = [];
        $seen = [];
        foreach ($ps_cat_ids as $ps_id) {
            $ps_id = (string) $ps_id;
            if (!isset($mapping[$ps_id])) {
                continue;
            }
            $studio_id = $mapping[$ps_id];
            if (!isset($seen[$studio_id])) {
                $seen[$studio_id] = true;
                $resolved[] = $studio_id;
            }
        }
        return $resolved;
    }
}
