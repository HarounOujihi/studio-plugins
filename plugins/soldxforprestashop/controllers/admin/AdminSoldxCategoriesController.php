<?php
/**
 * Admin categories controller — maps PS categories to Studio categories.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSoldxCategoriesController extends ModuleAdminController
{
    public $bootstrap = true;

    public function init()
    {
        parent::init();
        // Redirect to settings if not configured.
        if (!SoldxAuth::isConfigured()) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxSettings'));
        }
    }

    public function initContent()
    {
        parent::initContent();

        // Always bust cache so fresh data shows.
        $this->bustOptionsCache();

        // Read & clear flash.
        $flash = $this->readFlash();

        $id_lang = (int) Context::getContext()->language->id;

        // Fetch Studio categories from cached options.
        $options = $this->getEstablishmentOptions();
        $studio_cats = [];
        if (is_array($options) && isset($options['categories']) && is_array($options['categories'])) {
            $studio_cats = $options['categories'];
        }

        // Fetch PS categories.
        $ps_cats = $this->getPsCategories($id_lang);

        // Load stored mapping.
        $mapping = $this->getMapping();

        $this->context->smarty->assign([
            'ps_cats' => $ps_cats,
            'studio_cats' => $studio_cats,
            'mapping' => $mapping,
            'flash' => $flash,
            'token' => $this->token,
            'post_url' => $this->context->link->getAdminLink('AdminSoldxCategories'),
            'refresh_url' => $this->context->link->getAdminLink('AdminSoldxCategories') . '&refresh=1',
            'ajax_url' => $this->context->link->getAdminLink('AdminSoldxCategories') . '&ajax=1',
        ]);

        Media::addJsDef([
            'soldxCatsAjaxUrl' => $this->context->link->getAdminLink('AdminSoldxCategories') . '&ajax=1',
            'soldxToken' => $this->token,
        ]);

        $this->addJS(_PS_MODULE_DIR_ . 'soldxforprestashop/views/js/admin-categories.js');
        $this->addCSS(_PS_MODULE_DIR_ . 'soldxforprestashop/views/css/admin.css');

        $this->context->smarty->addTemplateDir(_PS_MODULE_DIR_ . 'soldxforprestashop/views/templates/admin/');
        $this->setTemplate('categories.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('soldx_action') && 'save_categories' === Tools::getValue('soldx_action')) {
            return $this->handleSave();
        }
        // Let the parent handle AJAX dispatch (ajaxProcess*) and other actions.
        return parent::postProcess();
    }

    private function handleSave()
    {
        $raw = Tools::getValue('mapping');
        $clean = [];
        if (is_array($raw)) {
            foreach ($raw as $ps_cat_id => $studio_cat_id) {
                $ps_cat_id = (int) $ps_cat_id;
                $studio_cat_id = pSQL($studio_cat_id);
                if ($ps_cat_id && '' !== $studio_cat_id) {
                    $clean[$ps_cat_id] = $studio_cat_id;
                }
            }
        }

        Configuration::updateValue('SOLDX_CATEGORY_MAP', json_encode($clean));

        // Sync PS category images to Studio for all mapped categories.
        $image_synced = 0;
        $id_lang = (int) Context::getContext()->language->id;
        foreach ($clean as $ps_cat_id => $studio_cat_id) {
            $image_key = $this->uploadCategoryImage($ps_cat_id);
            if ('' !== $image_key) {
                try {
                    Soldxforprestashop::getApiClient()->updateCategoryImage($studio_cat_id, $image_key);
                    $image_synced++;
                } catch (SoldxApiException $e) {
                    // Best-effort.
                }
            }
        }

        $this->bustOptionsCache();

        $msg = count($clean) . ' category mapping(s) saved.';
        if ($image_synced > 0) {
            $msg .= ' ' . $image_synced . ' category image(s) synced.';
        }
        $this->setFlash($msg, 'success');

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxCategories'));
    }

    // ------------------------------------------------------------------
    // AJAX: Create Studio category
    // ------------------------------------------------------------------

    public function ajaxProcessCreateCategory()
    {
        $designation = pSQL(Tools::getValue('designation'));
        $id_parent = pSQL(Tools::getValue('idParent'));
        $ps_cat_id = (int) Tools::getValue('wcTermId'); // PS category id

        if ('' === $designation) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => 'Designation is required.',
            ]));
        }

        // Auto-resolve parent: if no explicit Studio parent was selected,
        // check whether this PS category's parent is already mapped to a
        // Studio category and use that as the parent.
        if ('' === $id_parent && $ps_cat_id) {
            $ps_parent_id = (int) Db::getInstance()->getValue(
                'SELECT id_parent FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . $ps_cat_id
            );
            if ($ps_parent_id > 1) { // 1 = root, skip
                $mapping = self::getMapping();
                $ps_parent_key = (string) $ps_parent_id;
                if (isset($mapping[$ps_parent_key]) && '' !== $mapping[$ps_parent_key]) {
                    $id_parent = $mapping[$ps_parent_key];
                }
            }
        }

        // Validate that the resolved parent actually exists in Studio.
        // Stale mappings (e.g. after Studio categories were deleted) would
        // cause a foreign-key constraint violation on create.
        if ('' !== $id_parent) {
            $studio_cat_ids = $this->getStudioCategoryIds();
            if (!in_array($id_parent, $studio_cat_ids)) {
                $id_parent = ''; // Parent no longer exists, create as root.
            }
        }

        $image_key = '';
        if ($ps_cat_id) {
            $image_key = $this->uploadCategoryImage($ps_cat_id);
        }

        try {
            $result = Soldxforprestashop::getApiClient()->createCategory($designation, $id_parent, $image_key);

            // Immediately update the local mapping so that subsequent
            // subcategory imports can resolve the correct parent.
            if ($ps_cat_id && !empty($result['id'])) {
                $this->updateMappingEntry($ps_cat_id, $result['id']);
            }

            $this->bustOptionsCache();
            $this->ajaxDie(json_encode([
                'success' => true,
                'category' => $result,
            ]));
        } catch (SoldxApiException $e) {
            $this->ajaxDie(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getPsCategories($id_lang)
    {
        // Order by nleft to get proper nested-set hierarchy.
        // Parent categories always appear before their children.
        $sql = 'SELECT c.id_category, c.id_parent, c.level_depth, c.nleft, c.nright,
                       cl.name, cl.link_rewrite,
                       clp.name AS parent_name
                FROM ' . _DB_PREFIX_ . 'category c
                INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                    ON (c.id_category = cl.id_category AND cl.id_lang = ' . (int) $id_lang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'category_lang clp
                    ON (c.id_parent = clp.id_category AND clp.id_lang = ' . (int) $id_lang . ')
                WHERE c.active = 1 AND c.id_category > 1
                ORDER BY c.nleft';
        return Db::getInstance()->executeS($sql);
    }

    public static function getMapping()
    {
        $json = Configuration::get('SOLDX_CATEGORY_MAP');
        $mapping = $json ? json_decode($json, true) : [];
        return is_array($mapping) ? $mapping : [];
    }

    public static function resolve($ps_cat_ids)
    {
        if (empty($ps_cat_ids)) {
            return [];
        }
        $mapping = self::getMapping();
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

    /**
     * Return the list of valid Studio category IDs from cached options.
     * Used to validate that a mapped parent still exists before passing
     * it to the Studio API (avoids FK constraint violations).
     */
    private function getStudioCategoryIds()
    {
        $options = $this->getEstablishmentOptions();
        if (!is_array($options) || !isset($options['categories'])) {
            return [];
        }
        $ids = [];
        foreach ($options['categories'] as $cat) {
            if (!empty($cat['id'])) {
                $ids[] = $cat['id'];
            }
        }
        return $ids;
    }

    /**
     * Update a single PS→Studio category mapping entry in-place.
     * Called right after a category is created in Studio so that
     * subsequent child imports can resolve the correct parent.
     */
    private function updateMappingEntry($ps_cat_id, $studio_cat_id)
    {
        $ps_cat_id = (int) $ps_cat_id;
        if (!$ps_cat_id || empty($studio_cat_id)) {
            return;
        }
        $mapping = self::getMapping();
        $mapping[$ps_cat_id] = $studio_cat_id;
        Configuration::updateValue('SOLDX_CATEGORY_MAP', json_encode($mapping));
    }

    /**
     * Upload a PS category's image to Studio.
     * PrestaShop stores category images flat in img/c/{id}.jpg
     * (NOT in split subdirectories like product images).
     */
    private function uploadCategoryImage($id_category)
    {
        $id_category = (int) $id_category;
        $path = _PS_CAT_IMG_DIR_ . $id_category . '.jpg';

        if (!file_exists($path)) {
            return '';
        }

        $org_id = SoldxAuth::orgId();
        if ('' === $org_id) {
            return '';
        }

        try {
            return Soldxforprestashop::getApiClient()->uploadImage($path, $org_id, 'cat-' . $id_category . '.jpg');
        } catch (SoldxApiException $e) {
            return '';
        }
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
