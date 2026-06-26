<?php
/**
 * Soldx for PrestaShop — push products into Soldx Studio.
 *
 * @author    Soldx
 * @copyright Soldx
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   0.1.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Soldxforprestashop extends Module
{
    const TAB_CLASS_PARENT = 'AdminSoldx';
    const TAB_CLASS_SETTINGS = 'AdminSoldxSettings';
    const TAB_CLASS_CATEGORIES = 'AdminSoldxCategories';
    const TAB_CLASS_ARTICLES = 'AdminSoldxArticles';

    public function __construct()
    {
        $this->name = 'soldxforprestashop';
        $this->tab = 'front_office_features';
        $this->version = '0.1.0';
        $this->author = 'Soldx';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];

        parent::__construct();

        // Load module classes (PrestaShop doesn't autoload module classes).
        require_once _PS_MODULE_DIR_ . 'soldxforprestashop/classes/SoldxApiClient.php';
        require_once _PS_MODULE_DIR_ . 'soldxforprestashop/classes/SoldxAuth.php';
        require_once _PS_MODULE_DIR_ . 'soldxforprestashop/classes/SoldxMappingStore.php';
        require_once _PS_MODULE_DIR_ . 'soldxforprestashop/classes/SoldxSyncEngine.php';

        $this->displayName = $this->l('Soldx for PrestaShop');
        $this->description = $this->l('Push PrestaShop products into Soldx Studio. Manual selection, per-article units/deposit, no auto-sync.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? All mappings will be lost.');
    }

    // ------------------------------------------------------------------
    // Install / Uninstall
    // ------------------------------------------------------------------

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Configuration defaults
        Configuration::updateValue('SOLDX_STUDIO_URL', '');
        Configuration::updateValue('SOLDX_API_KEY', '');
        Configuration::updateValue('SOLDX_INTEGRATION_ID', '');
        Configuration::updateValue('SOLDX_ESTABLISHMENT_NAME', '');
        Configuration::updateValue('SOLDX_ORG_ID', '');
        Configuration::updateValue('SOLDX_CATEGORY_MAP', '{}');

        // Create mapping table
        $this->createMappingTable();

        // Install tabs
        if (!$this->installTabs()) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // Remove tabs
        $this->uninstallTabs();

        // Remove configuration
        Configuration::deleteByName('SOLDX_STUDIO_URL');
        Configuration::deleteByName('SOLDX_API_KEY');
        Configuration::deleteByName('SOLDX_INTEGRATION_ID');
        Configuration::deleteByName('SOLDX_ESTABLISHMENT_NAME');
        Configuration::deleteByName('SOLDX_ORG_ID');
        Configuration::deleteByName('SOLDX_CATEGORY_MAP');

        // Drop mapping table
        Db::getInstance()->execute('DROP TABLE IF EXISTS ' . _DB_PREFIX_ . 'soldx_mappings');

        return parent::uninstall();
    }

    private function installTabs()
    {
        // Parent tab
        $parent = new Tab();
        $parent->class_name = self::TAB_CLASS_PARENT;
        $parent->id_parent = 0;
        $parent->module = $this->name;
        $parent->icon = 'cloud_upload';
        $parent->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $parent->name[$lang['id_lang']] = 'Soldx';
        }
        if (!$parent->add()) {
            return false;
        }

        $tabs = [
            self::TAB_CLASS_SETTINGS => 'Settings',
            self::TAB_CLASS_CATEGORIES => 'Categories',
            self::TAB_CLASS_ARTICLES => 'Articles',
        ];

        foreach ($tabs as $class_name => $label) {
            $tab = new Tab();
            $tab->class_name = $class_name;
            $tab->id_parent = $parent->id;
            $tab->module = $this->name;
            $tab->name = [];
            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[$lang['id_lang']] = $label;
            }
            $tab->add();
        }

        return true;
    }

    private function uninstallTabs()
    {
        $classes = [self::TAB_CLASS_PARENT, self::TAB_CLASS_SETTINGS, self::TAB_CLASS_CATEGORIES, self::TAB_CLASS_ARTICLES];
        foreach ($classes as $class_name) {
            $id_tab = (int) Tab::getIdFromClassName($class_name);
            if ($id_tab) {
                $tab = new Tab($id_tab);
                $tab->delete();
            }
        }
    }

    private function createMappingTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'soldx_mappings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `studio_article_id` VARCHAR(64) NOT NULL,
            `integration_id` VARCHAR(64) NOT NULL,
            `ps_product_id` INT UNSIGNED NOT NULL,
            `ps_reference` VARCHAR(255) NULL,
            `sync_status` VARCHAR(32) NOT NULL DEFAULT "PENDING",
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `last_sync_at` DATETIME NULL,
            `last_error` TEXT NULL,
            `payload_hash` CHAR(64) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            UNIQUE KEY `integration_article` (`integration_id`, `studio_article_id`),
            UNIQUE KEY `ps_product` (`ps_product_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

        return Db::getInstance()->execute($sql);
    }

    // ------------------------------------------------------------------
    // Convenience accessors
    // ------------------------------------------------------------------

    public static function getApiClient()
    {
        static $client = null;
        if ($client === null) {
            $client = new SoldxApiClient();
        }
        return $client;
    }

    public static function getMappingStore()
    {
        return SoldxMappingStore::getInstance();
    }

    // ------------------------------------------------------------------
    // Admin header hook (inject CSS on our pages)
    // ------------------------------------------------------------------

    public function hookDisplayBackOfficeHeader()
    {
        $controller = Tools::getValue('controller');
        if (strpos($controller, 'AdminSoldx') === 0) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }
}
