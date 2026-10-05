<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model\Backend;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderRepositoryInterface;

class Outbox
{
    public function __construct(
        private ResourceConnection $resource,
        private OrderRepositoryInterface $orders,
        private Envelope $envelope
    ) {
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    public function boundary(int $storeId): ?array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->table('queen_one_order_boundary'))
            ->where('store_id = ?', $storeId)) ?: null;
    }

    public function activate(int $storeId, array $config): void
    {
        if ($this->boundary($storeId)) {
            throw new \RuntimeException('Store already activated; boundary is immutable');
        }
        $db = $this->resource->getConnection();
        $snapshot = $db->fetchRow($db->select()->from(
            $this->table('sales_order'),
            ['max_id' => new \Zend_Db_Expr('MAX(entity_id)'), 'at' => new \Zend_Db_Expr('UTC_TIMESTAMP()')]
        ));
        $db->insert($this->table('queen_one_order_boundary'), [
            'store_id' => $storeId, 'integration_id' => $config['integration_id'], 'site_id' => $config['site_id'],
            'min_order_id' => (int) $snapshot['max_id'] + 1, 'started_at' => $snapshot['at'],
        ]);
    }

    public function discover(int $storeId, array $config, ?array $backfill = null): int
    {
        $boundary = $this->boundary($storeId);
        if (!$boundary || $boundary['integration_id'] !== $config['integration_id']
            || $boundary['site_id'] !== $config['site_id']) {
            throw new \RuntimeException('Store not activated or integration changed');
        }
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['o' => $this->table('sales_order')], ['entity_id'])
            ->joinLeft(
                ['q' => $this->table('queen_one_order_outbox')],
                'q.order_id = o.entity_id AND ' . $db->quoteInto('q.integration_id = ?', $config['integration_id'])
                . ' AND ' . $db->quoteInto('q.site_id = ?', $config['site_id']),
                []
            )
            ->where('o.store_id = ?', $storeId)->where('q.entity_id IS NULL')
            ->order('o.entity_id ASC')->limit(100);
        if ($backfill) {
            $select->where('o.entity_id >= ?', $backfill[0])->where('o.entity_id <= ?', $backfill[1]);
        } else {
            $select->where('o.entity_id >= ?', $boundary['min_order_id'])
                ->where('o.created_at >= ?', $boundary['started_at']);
        }
        // No advancing high-water mark: a later commit with a lower ID remains discoverable.
        $ids = $db->fetchCol($select);
        foreach ($ids as $id) {
            $record = ['order_id' => (int) $id, 'store_id' => $storeId,
                'integration_id' => $config['integration_id'], 'site_id' => $config['site_id'],
                'event_id' => Envelope::eventId($config['integration_id'], $config['site_id'], (string) $id),
                'endpoint' => $config['endpoint'], 'status' => 'pending'];
            try {
                $record['payload'] = $this->buildPayload((int) $id, $config);
            } catch (\Throwable $error) {
                $record['status'] = 'failed';
                $record['last_error'] = 'envelope_failed';
            }
            $db->insert($this->table('queen_one_order_outbox'), $record);
        }
        return count($ids);
    }

    public function buildPayload(int $orderId, array $config): string
    {
        $payload = json_encode(
            $this->envelope->build($this->orders->get($orderId), $config),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        // Keep below the shared ingress JSON limit, including multi-byte names.
        if (strlen($payload) > 90000) {
            throw new \RuntimeException('Envelope exceeds ingress size');
        }
        return $payload;
    }

    public function pending(int $storeId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->table('queen_one_order_outbox'))
            ->where('store_id = ?', $storeId)->where('status = ?', 'pending')
            ->where('next_attempt_at <= UTC_TIMESTAMP()')->order('entity_id ASC')->limit(20));
    }

    public function update(int $id, array $data): void
    {
        $this->resource->getConnection()->update(
            $this->table('queen_one_order_outbox'),
            $data,
            ['entity_id = ?' => $id]
        );
    }

    public function retry(int $storeId, int $id, array $config): void
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('queen_one_order_outbox'))
            ->where('entity_id = ?', $id)->where('store_id = ?', $storeId));
        if (!$row || $row['status'] !== 'failed' || $row['integration_id'] !== $config['integration_id']
            || $row['site_id'] !== $config['site_id'] || $row['endpoint'] !== $config['endpoint']) {
            throw new \RuntimeException('Only failed records for the same configured installation can be retried');
        }
        $this->update($id, ['payload' => $row['payload'] ?? $this->buildPayload((int) $row['order_id'], $config),
            'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null]);
    }

    public function status(int $storeId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from(
            $this->table('queen_one_order_outbox'),
            ['entity_id', 'event_id', 'order_id', 'status', 'attempts', 'last_error', 'accepted_at']
        )
            ->where('store_id = ?', $storeId)->order('entity_id DESC')->limit(100));
    }
}
