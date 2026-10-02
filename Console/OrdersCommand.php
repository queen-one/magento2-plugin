<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Console;

use Rejoiner\Acr\Model\Backend\Config;
use Rejoiner\Acr\Model\Backend\Outbox;
use Rejoiner\Acr\Model\Backend\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrdersCommand extends Command
{
    public function __construct(private Config $config, private Outbox $outbox, private Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('queenone:orders')->setDescription('Operate the Connect backend order outbox')
            ->addArgument('action', InputArgument::REQUIRED, 'activate | run | status | retry | backfill')
            ->addArgument('store', InputArgument::REQUIRED, 'Magento store ID (0 includes Admin store orders)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Failed outbox row ID for retry')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First order entity ID for explicit backfill')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last order entity ID for explicit backfill');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $store = (string) $input->getArgument('store');
            if (!ctype_digit($store)) {
                throw new \RuntimeException('Store must be a nonnegative integer');
            }
            $storeId = (int) $store;
            $action = $input->getArgument('action');
            if ($action === 'status') {
                $output->writeln(json_encode(['boundary' => $this->outbox->boundary($storeId),
                    'recent_records' => $this->outbox->status($storeId)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                return 0;
            }
            if (!$this->config->isEnabled($storeId)) {
                throw new \RuntimeException('Backend delivery is disabled for this store');
            }
            if ($action === 'run') {
                $this->runner->runStore($storeId);
                return 0;
            }
            $config = $this->config->get($storeId);
            $this->runner->withStoreLock($storeId, function () use ($action, $storeId, $config, $input, $output): void {
                if ($action === 'activate') {
                    $this->outbox->activate($storeId, $config);
                    return;
                }
                if ($action === 'retry' && ctype_digit((string) $input->getOption('id'))) {
                    $this->outbox->retry($storeId, (int) $input->getOption('id'), $config);
                    return;
                }
                $from = (string) $input->getOption('from');
                $to = (string) $input->getOption('to');
                if ($action === 'backfill' && ctype_digit($from) && ctype_digit($to)
                    && (int) $from > 0 && (int) $to >= (int) $from && (int) $to - (int) $from < 1000) {
                    do {
                        $count = $this->outbox->discover($storeId, $config, [(int) $from, (int) $to]);
                    } while ($count === 100);
                    $output->writeln('Backfill recorded; run cron to deliver.');
                    return;
                }
                throw new \RuntimeException('Invalid action/options; backfill range is limited to 1000 entity IDs');
            });
            $output->writeln('Done.');
            return 0;
        } catch (\Throwable $error) {
            $output->writeln(
                '<error>Operation failed. Check configuration, activation and outbox state; '
                . 'no payload or secret is logged.</error>'
            );
            return 1;
        }
    }
}
