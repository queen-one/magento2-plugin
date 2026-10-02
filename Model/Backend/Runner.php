<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model\Backend;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Runner
{
    public function __construct(
        private Config $config,
        private Outbox $outbox,
        private Transport $transport,
        private LockManagerInterface $locks,
        private StoreManagerInterface $stores,
        private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            foreach ($this->stores->getStores(true) as $store) {
                try {
                    $this->runStore((int) $store->getId());
                } catch (\Throwable $error) {
                    try {
                        $this->logger->error('Queen One order cron failed', ['store_id' => $store->getId()]);
                    } catch (\Throwable $ignored) {
                        // Cron must remain isolated even if logging fails.
                    }
                }
            }
        } catch (\Throwable $error) {
            // Background integration is isolated even when stores or logging are unavailable.
        }
    }

    public function withStoreLock(int $storeId, callable $work): mixed
    {
        $name = 'queen_one_orders_' . $storeId;
        if (!$this->locks->lock($name, 0)) {
            throw new \RuntimeException('Store order worker is already running');
        }
        try {
            return $work();
        } finally {
            $this->locks->unlock($name);
        }
    }

    public function runStore(int $storeId): void
    {
        if (!$this->config->isEnabled($storeId)) {
            return;
        }
        $this->withStoreLock($storeId, function () use ($storeId): void {
            $config = $this->config->get($storeId);
            $this->outbox->discover($storeId, $config);
            foreach ($this->outbox->pending($storeId) as $row) {
                $id = (int) $row['entity_id'];
                if ($row['integration_id'] !== $config['integration_id'] || $row['site_id'] !== $config['site_id']
                    || $row['endpoint'] !== $config['endpoint']) {
                    $this->outbox->update($id, ['status' => 'failed', 'last_error' => 'configuration_changed']);
                    continue;
                }
                $attempt = (int) $row['attempts'] + 1;
                // Persist before network IO: a crash leaves a retryable row, with bounded attempts.
                if ($attempt > 10) {
                    $this->outbox->update($id, ['status' => 'failed', 'last_error' => 'attempts_exhausted']);
                    continue;
                }
                $this->outbox->update($id, ['attempts' => $attempt,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + min(3600, 30 * 2 ** ($attempt - 1)))]);
                try {
                    $status = $this->transport->send($row['endpoint'], $row['payload'], $config['secret']);
                } catch (\Throwable $error) {
                    $status = 0;
                }
                if ($status === 202) {
                    $this->outbox->update($id, [
                        'status' => 'accepted',
                        'accepted_at' => gmdate('Y-m-d H:i:s'),
                        'last_error' => null,
                    ]);
                } else {
                    $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;
                    $this->outbox->update($id, ['status' => $retryable && $attempt < 10 ? 'pending' : 'failed',
                        'last_error' => $status ? 'http_' . $status : 'transport_failed']);
                }
            }
        });
    }
}
