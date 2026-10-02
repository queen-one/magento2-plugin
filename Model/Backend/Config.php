<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const PREFIX = 'checkout/queen_one_connect/';

    public function __construct(private ScopeConfigInterface $scope, private EncryptorInterface $encryptor)
    {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scope->isSetFlag(self::PREFIX . 'backend_enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function get(int $storeId): array
    {
        $read = fn (string $key): string => trim((string) $this->scope->getValue(
            self::PREFIX . $key,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        $url = rtrim($read('bridge_url'), '/');
        $integrationId = $read('integration_id');
        $siteId = $read('site_id');
        $secret = $this->encryptor->decrypt($read('hmac_secret'));
        $parts = parse_url($url);
        if (!$parts || strlen($url) > 900 || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('/^[a-f0-9]{24}$/D', $integrationId) || $siteId === '' || strlen($siteId) > 128
            || !preg_match('/^[a-f0-9]{64}$/D', $secret)
        ) {
            throw new \RuntimeException('Incomplete or invalid Queen One backend configuration');
        }
        return ['integration_id' => $integrationId, 'site_id' => $siteId, 'secret' => $secret,
            'endpoint' => $url . '/webhooks/events/magento2/' . $integrationId];
    }
}
