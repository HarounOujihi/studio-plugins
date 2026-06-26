<?php
/**
 * Admin articles controller — lists PS products, push to Studio.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSoldxArticlesController extends ModuleAdminController
{
    public $bootstrap = true;

    private $page_size = 25;

    public function init()
    {
        parent::init();
        if (!SoldxAuth::isConfigured()) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxSettings'));
        }
    }

    public function initContent()
    {
        parent::initContent();

        // Always bust options cache.
        $this->bustOptionsCache();

        $flash = $this->readFlash();

        $page = max(1, (int) Tools::getValue('paged', 1));
        $search = pSQL(Tools::getValue('q', ''));
        $id_lang = (int) Context::getContext()->language->id;

        // Fetch establishment options.
        $options = $this->getEstablishmentOptions();
        if (false === $options) {
            $this->context->smarty->assign([
                'options_error' => true,
                'settings_url' => $this->context->link->getAdminLink('AdminSoldxSettings'),
                'cats_url' => $this->context->link->getAdminLink('AdminSoldxCategories'),
                'flash' => $flash,
            ]);
            $this->addCSS(_PS_MODULE_DIR_ . 'soldxforprestashop/views/css/admin.css');
            $this->context->smarty->addTemplateDir(_PS_MODULE_DIR_ . 'soldxforprestashop/views/templates/admin/');
            $this->setTemplate('articles.tpl');
            return;
        }

        $units = isset($options['units']) && is_array($options['units']) ? $options['units'] : [];
        $deposits = isset($options['deposits']) && is_array($options['deposits']) ? $options['deposits'] : [];
        $defaults = $this->getDefaults($options);

        // Studio categories for badges.
        $studio_cats = [];
        if (isset($options['categories']) && is_array($options['categories'])) {
            foreach ($options['categories'] as $cat) {
                $id = isset($cat['id']) ? $cat['id'] : '';
                $studio_cats[$id] = !empty($cat['designation']) ? $cat['designation'] : (isset($cat['reference']) ? $cat['reference'] : $id);
            }
        }

        // Studio tags.
        $studio_tags = isset($options['tags']) && is_array($options['tags']) ? $options['tags'] : [];
        // Sort by nameFr/name.
        usort($studio_tags, function ($a, $b) {
            $na = !empty($a['nameFr']) ? $a['nameFr'] : (isset($a['name']) ? $a['name'] : '');
            $nb = !empty($b['nameFr']) ? $b['nameFr'] : (isset($b['name']) ? $b['name'] : '');
            return strcasecmp($na, $nb);
        });

        // Fall back to first available item.
        if (empty($defaults['saleUnitId']) && !empty($units[0]['id'])) {
            $defaults['saleUnitId'] = $units[0]['id'];
        }
        if (empty($defaults['purchaseUnitId']) && !empty($units[0]['id'])) {
            $defaults['purchaseUnitId'] = $units[0]['id'];
        }
        if (empty($defaults['depositId']) && !empty($deposits[0]['id'])) {
            $defaults['depositId'] = $deposits[0]['id'];
        }

        // Fetch PS products.
        list($items, $total) = $this->queryPsProducts($page, $this->page_size, $search, $id_lang);
        $pages = max(1, (int) ceil($total / $this->page_size));

        // Enrich items with image URL + resolved categories.
        $link = Context::getContext()->link;
        foreach ($items as &$item) {
            $cover = Image::getCover((int) $item['id_product']);
            $item['image_url'] = '';
            if ($cover && isset($cover['id_image'])) {
                $item['image_url'] = $link->getImageLink(
                    $item['link_rewrite'],
                    (int) $cover['id_image'],
                    'small_default'
                );
            }
            $ps_cats = Product::getProductCategories((int) $item['id_product']);
            $item['resolved_cats'] = SoldxCategoryResolver::resolve($ps_cats);
        }
        unset($item);

        // Enrich with discount info (specific prices).
        $this->enrichWithDiscountInfo($items);

        // Build lookup of existing mappings.
        $mappings = [];
        if (!empty($items)) {
            $ps_ids = [];
            foreach ($items as $p) {
                $ps_ids[] = $p['id_product'];
            }
            $store = Soldxforprestashop::getMappingStore();
            $mappings = $store->mapForPsIds($ps_ids);
        }

        // PS tags slug map for auto-match.
        $tag_slug_map = [];
        foreach ($studio_tags as $stag) {
            if (!empty($stag['slug'])) {
                $tag_slug_map[$stag['slug']] = $stag['id'];
            }
        }

        $base_url = $this->context->link->getAdminLink('AdminSoldxArticles');
        $cats_url = $this->context->link->getAdminLink('AdminSoldxCategories');

        $this->context->smarty->assign([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'units' => $units,
            'deposits' => $deposits,
            'defaults' => $defaults,
            'studio_cats' => $studio_cats,
            'studio_tags' => $studio_tags,
            'tag_slug_map' => $tag_slug_map,
            'mappings' => $mappings,
            'flash' => $flash,
            'flash_summary' => isset($flash['summary']) ? $flash['summary'] : null,
            'token' => $this->token,
            'post_url' => $this->context->link->getAdminLink('AdminSoldxArticles'),
            'base_url' => $base_url,
            'cats_url' => $cats_url,
            'page_size' => $this->page_size,
            'id_lang' => $id_lang,
            'ps_tags' => $this->buildPsTagLookup($items, $id_lang),
        ]);

        Media::addJsDef([
            'soldxArticles' => [
                'noSelection' => 'Please select at least one product.',
                'pushing' => 'Pushing…',
            ],
        ]);

        $this->addJS(_PS_MODULE_DIR_ . 'soldxforprestashop/views/js/admin-articles.js');
        $this->addCSS(_PS_MODULE_DIR_ . 'soldxforprestashop/views/css/admin.css');

        $this->context->smarty->addTemplateDir(_PS_MODULE_DIR_ . 'soldxforprestashop/views/templates/admin/');
        $this->setTemplate('articles.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('soldx_action') && 'sync_selected' === Tools::getValue('soldx_action')) {
            return $this->handleSync();
        }
        // Let the parent handle AJAX dispatch (ajaxProcess*) and other actions.
        return parent::postProcess();
    }

    private function handleSync()
    {
        $ids = Tools::getValue('product_ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_map('intval', $ids);

        if (empty($ids)) {
            $this->setFlash('No products selected.', 'warning');
            Tools::redirectAdmin($this->redirectBack());
        }

        $raw_overrides = Tools::getValue('overrides');
        if (!is_array($raw_overrides)) {
            $raw_overrides = [];
        }

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        $engine = SoldxSyncEngine::getInstance();

        foreach ($ids as $pid) {
            $ov = $this->extractOverrides($pid, $raw_overrides);

            if (empty($ov['saleUnitId'])) {
                $skipped++;
                $errors[] = '#' . $pid . ': missing sale unit';
                continue;
            }

            $result = $engine->syncProduct($pid, $ov);

            if (!empty($result['success'])) {
                $ok++;
            } else {
                $failed++;
                $msg = isset($result['error']) ? $result['error'] : 'Unknown error';
                $errors[] = '#' . $pid . ': ' . $msg;
            }
        }

        $summary = 'Synced ' . $ok . ' product(s) to Studio.';
        if ($failed > 0) {
            $summary .= ' ' . $failed . ' failed.';
        }
        if ($skipped > 0) {
            $summary .= ' ' . $skipped . ' skipped.';
        }
        $error_html = '';
        if (!empty($errors)) {
            $error_html .= '<br><details><summary>Show errors</summary><ul style="margin-top:6px">';
            foreach (array_slice($errors, 0, 20) as $err) {
                $error_html .= '<li><code>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</code></li>';
            }
            $error_html .= '</ul></details>';
        }

        $this->setFlash($summary . $error_html, $failed > 0 ? ($ok > 0 ? 'warning' : 'error') : 'success');

        Tools::redirectAdmin($this->redirectBack());
    }

    private function extractOverrides($pid, $raw)
    {
        $out = [];
        $key = (string) $pid;
        if (!isset($raw[$key]) || !is_array($raw[$key])) {
            $out['published'] = true;
            return $out;
        }
        foreach (['saleUnitId', 'purchaseUnitId', 'depositId', 'reference'] as $field) {
            if (isset($raw[$key][$field])) {
                $val = trim($raw[$key][$field]);
                if ('' !== $val) {
                    $out[$field] = $val;
                }
            }
        }
        if (isset($raw[$key]['tagIds']) && is_array($raw[$key]['tagIds'])) {
            $out['tagIds'] = array_values(array_filter(array_map('trim', $raw[$key]['tagIds'])));
        }
        $out['published'] = isset($raw[$key]['published']);
        return $out;
    }

    private function redirectBack()
    {
        $page = max(1, (int) Tools::getValue('return_page', 1));
        $q = pSQL(Tools::getValue('return_q', ''));
        $url = $this->context->link->getAdminLink('AdminSoldxArticles');
        $params = [];
        if ($page > 1) {
            $params['paged'] = $page;
        }
        if ('' !== $q) {
            $params['q'] = $q;
        }
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }
        return $url;
    }

    // ------------------------------------------------------------------
    // PS product query
    // ------------------------------------------------------------------

    private function queryPsProducts($page, $page_size, $search, $id_lang)
    {
        $offset = ($page - 1) * $page_size;

        $where = '';
        $params = [];
        if ('' !== $search) {
            $where = ' AND (pl.name LIKE "%' . pSQL($search) . '%" OR p.reference LIKE "%' . pSQL($search) . '%")';
        }

        $sql = 'SELECT p.id_product, p.reference, p.price, p.ean13, p.weight, p.is_virtual, p.active,
                       pl.name, pl.link_rewrite, pl.description, pl.description_short
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                    ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int) $id_lang . ')
                WHERE 1=1' . $where . '
                ORDER BY p.id_product DESC
                LIMIT ' . (int) $offset . ', ' . (int) $page_size;
        $items = Db::getInstance()->executeS($sql);

        $count_sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product p
                      INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                          ON (p.id_product = pl.id_product AND pl.id_lang = ' . (int) $id_lang . ')
                      WHERE 1=1' . $where;
        $total = (int) Db::getInstance()->getValue($count_sql);

        return [$items, $total];
    }

    /**
     * Build a per-product tag slug lookup: ps_product_id → [tag slugs]
     */
    private function buildPsTagLookup($items, $id_lang)
    {
        if (empty($items)) {
            return [];
        }
        $ids = [];
        foreach ($items as $p) {
            $ids[] = (int) $p['id_product'];
        }
        $id_list = implode(',', $ids);

        $sql = 'SELECT pt.id_product, t.name
                FROM ' . _DB_PREFIX_ . 'product_tag pt
                INNER JOIN ' . _DB_PREFIX_ . 'tag t ON (pt.id_tag = t.id_tag AND t.id_lang = ' . (int) $id_lang . ')
                WHERE pt.id_product IN (' . $id_list . ')';
        $rows = Db::getInstance()->executeS($sql);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row['id_product'];
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            // PS tags don't have slugs by default — use the name lowercased.
            $out[$pid][] = Tools::link_rewrite($row['name']);
        }
        return $out;
    }

    /**
     * Batch-enrich product items with discount info from ps_specific_price.
     * Computes: has_discount, sale_price, discount_percent per product.
     * Uses the same logic as SoldxSyncEngine::getSpecificPrice().
     */
    private function enrichWithDiscountInfo(&$items)
    {
        if (empty($items)) {
            return;
        }
        $ids = [];
        foreach ($items as $p) {
            $ids[] = (int) $p['id_product'];
        }
        $id_list = implode(',', $ids);
        $id_shop = (int) Context::getContext()->shop->id;

        $sql = 'SELECT sp.id_product, sp.reduction, sp.reduction_type, sp.price, sp.from, sp.to
                FROM ' . _DB_PREFIX_ . 'specific_price sp
                WHERE sp.id_product IN (' . $id_list . ')
                  AND sp.id_product_attribute = 0
                  AND sp.id_cart = 0
                  AND sp.id_customer = 0
                  AND (sp.id_shop = 0 OR sp.id_shop = ' . $id_shop . ')
                  AND sp.id_country = 0
                  AND sp.id_currency = 0
                  AND sp.id_group = 0
                  AND (sp.from = "0000-00-00 00:00:00" OR sp.from <= NOW())
                  AND (sp.to = "0000-00-00 00:00:00" OR sp.to >= NOW())
                ORDER BY sp.id_product, sp.id_specific_price ASC';
        $rows = Db::getInstance()->executeS($sql);

        // Index by product — first match wins (highest priority).
        $by_product = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['id_product'];
                if (!isset($by_product[$pid])) {
                    $by_product[$pid] = $row;
                }
            }
        }

        foreach ($items as &$item) {
            $pid = (int) $item['id_product'];
            $item['has_discount'] = false;
            $item['sale_price'] = null;
            $item['discount_percent'] = 0.0;

            if (!isset($by_product[$pid])) {
                continue;
            }

            $sp = $by_product[$pid];
            $base = (float) $item['price'];

            // Compute effective price — same logic as SoldxSyncEngine.
            $effective = $base;
            if (!empty($sp['price']) && (float) $sp['price'] > 0 && (float) $sp['price'] < $base) {
                $effective = (float) $sp['price'];
            } elseif (!empty($sp['reduction']) && (float) $sp['reduction'] > 0) {
                if ($sp['reduction_type'] === 'percentage') {
                    $effective = $base * (1 - (float) $sp['reduction']);
                } else {
                    $effective = $base - (float) $sp['reduction'];
                }
            }

            if ($effective < $base && $base > 0) {
                $item['has_discount'] = true;
                $item['sale_price'] = round($effective, 6);
                $item['discount_percent'] = round((1 - $effective / $base) * 100, 1);
            }
        }
        unset($item);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getDefaults($options)
    {
        $config = is_array($options) && isset($options['config']) && is_array($options['config'])
            ? $options['config']
            : [];
        return [
            'saleUnitId' => isset($config['defaultSaleUnitId']) ? $config['defaultSaleUnitId'] : '',
            'purchaseUnitId' => isset($config['defaultPurchaseUnitId']) ? $config['defaultPurchaseUnitId'] : '',
            'depositId' => isset($config['defaultDepositId']) ? $config['defaultDepositId'] : '',
        ];
    }

    private function getEstablishmentOptions()
    {
        $cache_key = 'SOLDX_EST_OPTIONS';
        $cache_ts_key = 'SOLDX_EST_OPTIONS_TS';
        $ttl = 300;

        $ts = (int) Configuration::get($cache_ts_key);
        if ($ts > 0 && (time() - $ts) < $ttl) {
            $cached = Configuration::get($cache_key);
            if ($cached) {
                $data = json_decode($cached, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        try {
            $options = Soldxforprestashop::getApiClient()->getOptions();
            if (is_array($options)) {
                Configuration::updateValue($cache_key, json_encode($options));
                Configuration::updateValue($cache_ts_key, time());
                return $options;
            }
        } catch (SoldxApiException $e) {
            return false;
        }
        return false;
    }

    private function bustOptionsCache()
    {
        Configuration::updateValue('SOLDX_EST_OPTIONS_TS', 0);
    }

    private function setFlash($msg, $type = 'info')
    {
        $this->context->cookie->soldx_flash = json_encode(['msg' => $msg, 'type' => $type]);
    }

    private function readFlash()
    {
        if (isset($this->context->cookie->soldx_flash)) {
            $flash = json_decode($this->context->cookie->soldx_flash, true);
            unset($this->context->cookie->soldx_flash);
            return $flash;
        }
        return null;
    }
}
