<?php
declare(strict_types=1);

// Explicit local-only acceptance harness. Creates a fixture product/order and retains its outbox evidence.
require getcwd() . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $stores->getStore(1);
if (getenv('QO_LOCAL_BACKEND_TEST') !== '1'
    || parse_url($store->getBaseUrl(), PHP_URL_HOST) !== 'queenone-magento.test') {
    throw new RuntimeException('Run only in the queenone-magento.test lab with QO_LOCAL_BACKEND_TEST=1');
}
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$config = new class extends \Rejoiner\Acr\Model\Backend\Config {
    public function __construct()
    {
    }
    public function isEnabled(int $storeId): bool
    {
        return $storeId === 1;
    }
    public function get(int $storeId): array
    {
        return ['integration_id' => '507f1f77bcf86cd799439011', 'site_id' => 'magento-local-backend-only',
            'secret' => str_repeat('a', 64), 'endpoint' => 'https://127.0.0.1:1/webhooks/events/magento2/507f1f77bcf86cd799439011'];
    }
};
$outbox = $om->get(\Rejoiner\Acr\Model\Backend\Outbox::class);
if (!$outbox->boundary(1)) {
    $outbox->activate(1, $config->get(1));
}
$assert($outbox->boundary(1)['site_id'] === $config->get(1)['site_id'], 'Existing non-test boundary; do not overwrite');

$products = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
try {
    $product = $products->get('qo-backend-fixture');
} catch (\Magento\Framework\Exception\NoSuchEntityException $error) {
    $product = $om->get(\Magento\Catalog\Model\ProductFactory::class)->create();
    $product->setSku('qo-backend-fixture')->setName('Queen One local backend fixture')
        ->setTypeId('virtual')->setAttributeSetId(4)->setStatus(1)->setVisibility(4)
        ->setPrice(12.50)->setTaxClassId(0)->setWebsiteIds([(int) $store->getWebsiteId()])
        ->setStockData(['manage_stock' => 0, 'use_config_manage_stock' => 0, 'is_in_stock' => 1]);
    $product = $products->save($product);
}
$quote = $om->get(\Magento\Quote\Model\QuoteFactory::class)->create()->setStore($store);
$quote->setCustomerIsGuest(true)->setCustomerGroupId(0)->setCustomerEmail('qo-local@example.test');
$quote->getBillingAddress()->addData(['firstname' => 'Local', 'lastname' => 'Fixture',
    'street' => '1 Test Street', 'city' => 'Los Angeles', 'country_id' => 'US', 'region_id' => 12,
    'postcode' => '90001', 'telephone' => '5550100000', 'email' => 'qo-local@example.test']);
$quote->addProduct($product, 1);
$quote->getPayment()->setMethod('checkmo');
$quote->collectTotals();
$om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
$orderId = $om->get(\Magento\Quote\Api\CartManagementInterface::class)->placeOrder($quote->getId());
$assert($orderId > 0, 'Order creation failed');
echo "PASS: real Magento placeOrder succeeded with Bridge unavailable; order {$orderId}\n";
$before = array_filter($outbox->status(1), static fn ($row) => (int) $row['order_id'] === (int) $orderId);
$assert(count($before) === 0, 'Outbox work ran inside order creation');

$runner = $om->create(\Rejoiner\Acr\Model\Backend\Runner::class, ['config' => $config]);
$runner->runStore(1);
$find = static function () use ($outbox, $orderId): array {
    $rows = array_values(array_filter($outbox->status(1), static fn ($row) => (int) $row['order_id'] === (int) $orderId));
    if (count($rows) !== 1) {
        throw new RuntimeException('Expected one durable logical order');
    }
    return $rows[0];
};
$row = $find();
$assert($row['status'] === 'pending' && $row['last_error'] === 'transport_failed', 'Outage was not retained for retry');
echo "PASS: cron retained order after real HTTPS connection failure\n";
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $resource->getConnection();
$payload = $db->fetchOne($db->select()->from($resource->getTableName('queen_one_order_outbox'), ['payload'])
    ->where('entity_id = ?', $row['entity_id']));
$assert(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['order']['entity_id'] === (string) $orderId, 'Wrong native order');
// The recovery receiver is deliberately simulated: this proves outbox state, not staging EC delivery.
$transport = new class extends \Rejoiner\Acr\Model\Backend\Transport {
    public int $calls = 0;
    public function send(string $endpoint, string $body, string $secret): int
    {
        json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->calls++;
        return 202;
    }
};
$outbox->update((int) $row['entity_id'], ['next_attempt_at' => '2000-01-01 00:00:00']);
$recovery = $om->create(\Rejoiner\Acr\Model\Backend\Runner::class, ['config' => $config, 'transport' => $transport]);
$recovery->runStore(1);
$recovery->runStore(1);
$assert($find()['status'] === 'accepted' && $transport->calls === 1, 'Recovery or dedup failed');
$after = $db->fetchOne($db->select()->from($resource->getTableName('queen_one_order_outbox'), ['payload'])
    ->where('entity_id = ?', $row['entity_id']));
$assert($payload === $after, 'Retry changed immutable payload');
echo "PASS: simulated 202 receiver recovered once; repeat scan did not duplicate; payload unchanged\n";
echo "Evidence retained: order {$orderId}, outbox {$row['entity_id']}, event {$row['event_id']}\n";
echo "Staging ingress/queue/Collector acceptance is NOT established by this local harness.\n";
