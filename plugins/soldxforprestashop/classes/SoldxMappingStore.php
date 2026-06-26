<?php
/**
 * Local mapping table — mirrors Studio's ArticleExternalMapping so the
 * module keeps working when Studio is unreachable.
 *
 * Table: ps_soldx_mappings
 *   studio_article_id, integration_id, ps_product_id, ps_reference,
 *   sync_status, is_enabled, last_sync_at, last_error, payload_hash
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SoldxMappingStore
{
    private static $instance = null;
    private $table;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->table = _DB_PREFIX_ . 'soldx_mappings';
    }

    /**
     * Table name without prefix — for Db::insert/update/delete which auto-prepend _DB_PREFIX_.
     */
    private function shortTable()
    {
        return 'soldx_mappings';
    }

    public function tableName()
    {
        return $this->table;
    }

    /**
     * Get a mapping by Studio article id (scoped to current integration).
     *
     * @param string $studio_article_id
     * @return array|null
     */
    public function getByStudioId($studio_article_id)
    {
        $integration_id = SoldxAuth::integrationId();
        if (!$integration_id) {
            return null;
        }
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE integration_id = "' . pSQL($integration_id) . '"
                  AND studio_article_id = "' . pSQL($studio_article_id) . '"';
        $row = Db::getInstance()->getRow($sql);
        return $row ?: null;
    }

    /**
     * Get a mapping by PS product id.
     *
     * @param int $ps_product_id
     * @return array|null
     */
    public function getByPsId($ps_product_id)
    {
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE ps_product_id = ' . (int) $ps_product_id;
        $row = Db::getInstance()->getRow($sql);
        return $row ?: null;
    }

    /**
     * Get all mappings for the current integration.
     *
     * @param array $args { enabled_only, status, limit, offset }
     * @return array
     */
    public function listMappings($args = [])
    {
        $integration_id = SoldxAuth::integrationId();
        if (!$integration_id) {
            return [];
        }

        $where = 'WHERE integration_id = "' . pSQL($integration_id) . '"';

        if (!empty($args['enabled_only'])) {
            $where .= ' AND is_enabled = 1';
        }
        if (!empty($args['status'])) {
            $where .= ' AND sync_status = "' . pSQL($args['status']) . '"';
        }

        $limit = !empty($args['limit']) ? (int) $args['limit'] : 100;
        $offset = !empty($args['offset']) ? (int) $args['offset'] : 0;

        $sql = 'SELECT * FROM `' . $this->table . '` ' . $where . '
                ORDER BY updated_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Build a lookup map: ps_product_id → mapping row, for a list of ids.
     *
     * @param array $ps_ids
     * @return array associative
     */
    public function mapForPsIds($ps_ids)
    {
        if (empty($ps_ids)) {
            return [];
        }
        $ids = array_map('intval', $ps_ids);
        $id_list = implode(',', $ids);
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE ps_product_id IN (' . $id_list . ')';
        $rows = Db::getInstance()->executeS($sql);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['ps_product_id']] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Mutations
    // ------------------------------------------------------------------

    /**
     * Insert or update a mapping after a successful sync.
     *
     * @param array $data
     * @return bool
     */
    public function upsert($data)
    {
        $integration_id = SoldxAuth::integrationId();
        if (!$integration_id || empty($data['studio_article_id']) || empty($data['ps_product_id'])) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $existing = $this->getByStudioId($data['studio_article_id']);

        $row = [
            'integration_id' => pSQL($integration_id),
            'studio_article_id' => pSQL($data['studio_article_id']),
            'ps_product_id' => (int) $data['ps_product_id'],
            'ps_reference' => isset($data['ps_reference']) ? pSQL($data['ps_reference']) : null,
            'sync_status' => 'SYNCED',
            'is_enabled' => 1,
            'last_sync_at' => $now,
            'last_error' => null,
            'payload_hash' => isset($data['payload_hash']) ? pSQL($data['payload_hash']) : null,
            'updated_at' => $now,
        ];

        if ($existing) {
            return Db::getInstance()->update(
                $this->shortTable(),
                $row,
                'id = ' . (int) $existing['id']
            );
        }

        $row['created_at'] = $now;
        return Db::getInstance()->insert($this->shortTable(), $row);
    }

    /**
     * Delete a mapping by PS product id (cleans up stale entries).
     *
     * @param int $ps_product_id
     * @return bool
     */
    public function deleteByPsId($ps_product_id)
    {
        return Db::getInstance()->delete(
            $this->shortTable(),
            'ps_product_id = ' . (int) $ps_product_id
        );
    }
}
