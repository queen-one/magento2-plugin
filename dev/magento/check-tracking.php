<?php
declare(strict_types=1);

// Run from the Magento root: php app/code/Rejoiner/Acr/dev/magento/check-tracking.php
require getcwd() . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('frontend'));
$module = BP . '/app/code/Rejoiner/Acr';
$resolver = new \Magento\Framework\Config\Dom\UrnResolver();
libxml_set_external_entity_loader([$resolver, 'registerEntityLoader']);

foreach (['etc', 'view/frontend/layout'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module . '/' . $directory));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'xml') { continue; }
        $document = new DOMDocument();
        $document->load($file->getPathname());
        $urn = $document->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
        if (!$document->schemaValidate($resolver->getRealPath($urn))) {
            throw new RuntimeException('Invalid XML: ' . $file->getFilename());
        }
    }
}
echo "PASS: Magento XML schemas\n";

$jobs = $om->get(\Magento\Cron\Model\ConfigInterface::class)->getJobs();
foreach ($jobs as $group) {
    if (isset($group['track_order_success_conversion'])) {
        throw new RuntimeException('Legacy conversion cron is still registered');
    }
}
$events = $om->get(\Magento\Framework\Event\ConfigInterface::class);
foreach (['sales_order_save_after', 'checkout_onepage_controller_success_action', 'controller_action_predispatch',
    'sales_quote_remove_item', 'customer_data_object_login'] as $event) {
    foreach ($events->getObservers($event) as $observer) {
        if (str_starts_with($observer['instance'] ?? '', 'Rejoiner\\Acr\\')) {
            throw new RuntimeException('Legacy observer is still registered');
        }
    }
}
echo "PASS: no legacy cron or observers\n";

$scope = new class($om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)) implements \Magento\Framework\App\Config\ScopeConfigInterface {
    private array $values = [];
    public function __construct(private \Magento\Framework\App\Config\ScopeConfigInterface $fallback) {}
    public function setValue(string $path, string $value): void { $this->values[$path] = $value; }
    public function getValue($path = null, $scopeType = 'default', $scopeCode = null) {
        return $this->values[$path] ?? $this->fallback->getValue($path, $scopeType, $scopeCode);
    }
    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null) {
        return (bool) $this->getValue($path, $scopeType, $scopeCode);
    }
};
// Request-local overrides only: no config is saved and no external Tag is executed.
foreach (['enabled' => '1', 'site_id' => 'magento-local-fixture', 'tag_url' => 'https://tag.example/queen-one.js'] as $key => $value) {
    $scope->setValue('checkout/queen_one_connect/' . $key, $value);
}
$config = new \Rejoiner\Acr\Model\TrackingConfig($scope);
if (!$config->isEnabled()) { throw new RuntimeException('Connect configuration not enabled'); }
$block = $om->create(\Rejoiner\Acr\Block\StorefrontTracking::class, ['config' => $config]);
$json = $block->getInitJson();
$options = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['*']['Rejoiner_Acr/js/storefront-tracking'];
if ($options['siteId'] !== 'magento-local-fixture' || isset($options['identity'])) {
    throw new RuntimeException('Invalid public configuration');
}
$section = $om->get(\Magento\Customer\CustomerData\SectionPoolInterface::class)->getSectionsData(['queen-one-connect']);
if (($section['queen-one-connect']['version'] ?? null) !== 1) {
    throw new RuntimeException('Queen One section missing');
}
$source = $om->create(\Rejoiner\Acr\CustomerData\QueenOne::class, ['config' => $config]);
$data = $source->getSectionData();
if ($data['status'] === 'error') { throw new RuntimeException('Live section extraction failed'); }
$policy = new \Rejoiner\Acr\Model\TagOriginPolicy($config);
if ($policy->collect()[0]->getHostSources() !== ['https://tag.example']) {
    throw new RuntimeException('Configured Tag origin missing from CSP');
}
echo "PASS: enabled public block, private customer-data section and Tag CSP origin\n";
$scope->setValue('checkout/queen_one_connect/enabled', '0');
if ($block->getInitJson() !== '') { throw new RuntimeException('Disabled tracking still renders'); }
echo "PASS: disabled tracking renders no initialization\n";
