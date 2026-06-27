<?php
/**
 * Soldx MappingStore — CRUD access to the soldx_mappings table.
 *
 * Equivalent of WC class-mapping-store.php.
 * Uses Magento's ResourceConnection for direct DB access.
 */
declare(strict_types=1);

namespace Soldx\Integration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class MappingStore
{
    private const TABLE_NAME = 'soldx_mappings';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var AdapterInterface|null
     */
    private ?AdapterInterface $connection = null;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Get the DB connection (lazy-loaded).
     *
     * @return AdapterInterface
     */
    private function getConnection(): AdapterInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->resourceConnection->getConnection();
        }
        return $this->connection;
    }

    /**
     * Get the full table name (with prefix).
     *
     * @return string
     */
    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE_NAME);
    }

    /**
     * Get a mapping by Studio article ID.
     *
     * @param string $studioArticleId
     * @return array|null
     */
    public function getByStudioId(string $studioArticleId): ?array
    {
        $row = $this->getConnection()->select()
            ->from($this->getTable())
            ->where('studio_article_id = ?', $studioArticleId)
            ->query()
            ->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Get a mapping by Magento product ID.
     *
     * @param int $productId
     * @return array|null
     */
    public function getByProductId(int $productId): ?array
    {
        $row = $this->getConnection()->select()
            ->from($this->getTable())
            ->where('magento_product_id = ?', $productId)
            ->query()
            ->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * List all mappings with optional filters and pagination.
     *
     * @param array $filters e.g. ['sync_status' => 'error', 'is_enabled' => 1]
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function listMappings(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable())
            ->order('updated_at DESC')
            ->limit($limit, $offset);

        foreach ($filters as $column => $value) {
            $select->where($column . ' = ?', $value);
        }

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Count mappings matching the given filters.
     *
     * @param array $filters
     * @return int
     */
    public function countMappings(array $filters = []): int
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable(), 'COUNT(*)');

        foreach ($filters as $column => $value) {
            $select->where($column . ' = ?', $value);
        }

        return (int) $this->getConnection()->fetchOne($select);
    }

    /**
     * Insert or update a mapping row.
     *
     * @param array $data
     * @return int The row ID.
     */
    public function upsert(array $data): int
    {
        $conn = $this->getConnection();
        $table = $this->getTable();
        $now = date('Y-m-d H:i:s');

        $row = [
            'studio_article_id' => $data['studio_article_id'] ?? '',
            'integration_id' => $data['integration_id'] ?? null,
            'magento_product_id' => $data['magento_product_id'] ?? 0,
            'magento_sku' => $data['magento_sku'] ?? null,
            'sync_status' => $data['sync_status'] ?? 'pending',
            'is_enabled' => $data['is_enabled'] ?? 1,
            'last_sync_at' => $data['last_sync_at'] ?? null,
            'last_error' => $data['last_error'] ?? null,
            'payload_hash' => $data['payload_hash'] ?? null,
            'updated_at' => $now,
        ];

        // Check if mapping exists by studio_article_id or magento_product_id
        $existing = null;
        if (!empty($data['studio_article_id'])) {
            $existing = $this->getByStudioId($data['studio_article_id']);
        }
        if (!$existing && !empty($data['magento_product_id'])) {
            $existing = $this->getByProductId((int) $data['magento_product_id']);
        }

        if ($existing) {
            $conn->update($table, $row, ['id = ?' => $existing['id']]);
            return (int) $existing['id'];
        }

        $row['created_at'] = $now;
        $conn->insert($table, $row);
        return (int) $conn->lastInsertId($table);
    }

    /**
     * Mark a mapping as synced successfully.
     *
     * @param int $productId
     * @param string $studioArticleId
     * @param string|null $payloadHash
     * @return void
     */
    public function markSynced(int $productId, string $studioArticleId, ?string $payloadHash = null): void
    {
        $this->upsert([
            'studio_article_id' => $studioArticleId,
            'magento_product_id' => $productId,
            'sync_status' => 'synced',
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
            'payload_hash' => $payloadHash,
        ]);
    }

    /**
     * Record an error on a mapping.
     *
     * @param int $productId
     * @param string $errorMessage
     * @param string|null $studioArticleId
     * @return void
     */
    public function setError(
        int $productId,
        string $errorMessage,
        ?string $studioArticleId = null
    ): void {
        $existing = $this->getByProductId($productId);

        if ($existing) {
            $this->getConnection()->update(
                $this->getTable(),
                [
                    'sync_status' => 'error',
                    'last_error' => $errorMessage,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                ['id = ?' => $existing['id']]
            );
        } else {
            $this->upsert([
                'studio_article_id' => $studioArticleId ?? ('pending_' . $productId),
                'magento_product_id' => $productId,
                'sync_status' => 'error',
                'last_error' => $errorMessage,
            ]);
        }
    }

    /**
     * Toggle the enabled flag on a mapping.
     *
     * @param int $productId
     * @param bool $enabled
     * @return void
     */
    public function setEnabled(int $productId, bool $enabled): void
    {
        $existing = $this->getByProductId($productId);
        if (!$existing) {
            return;
        }

        $this->getConnection()->update(
            $this->getTable(),
            [
                'is_enabled' => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            ['magento_product_id = ?' => $productId]
        );
    }

    /**
     * Delete a mapping by Magento product ID.
     *
     * @param int $productId
     * @return void
     */
    public function deleteByProductId(int $productId): void
    {
        $this->getConnection()->delete(
            $this->getTable(),
            ['magento_product_id = ?' => $productId]
        );
    }

    /**
     * Resolve Studio article IDs for a set of Magento product IDs.
     *
     * @param array $productIds
     * @return array productId => studioArticleId
     */
    public function mapForProductIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $select = $this->getConnection()->select()
            ->from($this->getTable(), ['magento_product_id', 'studio_article_id'])
            ->where('magento_product_id IN (?)', $productIds);

        $rows = $this->getConnection()->fetchPairs($select);
        $result = [];
        foreach ($rows as $productId => $studioId) {
            $result[(int) $productId] = $studioId;
        }
        return $result;
    }
}
