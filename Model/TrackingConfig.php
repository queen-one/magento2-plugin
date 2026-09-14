<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class TrackingConfig
{
    private const PATH = 'checkout/queen_one_connect/';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function getSiteId(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::PATH . 'site_id', ScopeInterface::SCOPE_STORE));
    }

    public function getTagUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::PATH . 'tag_url', ScopeInterface::SCOPE_STORE));
    }

    public static function isValidTagUrl(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . 'enabled', ScopeInterface::SCOPE_STORE)
            && $this->getSiteId() !== '' && self::isValidTagUrl($this->getTagUrl());
    }

    public function isDebug(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . 'debug', ScopeInterface::SCOPE_STORE);
    }
}
