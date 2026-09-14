<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Block;

use Magento\Cookie\Helper\Cookie;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Rejoiner\Acr\Model\TrackingConfig;
use Throwable;

class StorefrontTracking extends Template
{
    public function __construct(
        Context $context,
        private TrackingConfig $config,
        private Cookie $cookie,
        private Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getInitJson(): string
    {
        try {
            if (!$this->config->isEnabled()) {
                return '';
            }
            $product = $this->registry->registry('current_product');
            $options = [
                'siteId' => $this->config->getSiteId(),
                'tagUrl' => $this->config->getTagUrl(),
                'storeId' => (string) $this->_storeManager->getStore()->getId(),
                'websiteId' => (string) $this->_storeManager->getWebsite()->getId(),
                'cookieRestriction' => (bool) $this->cookie->isCookieRestrictionModeEnabled(),
                'cookieName' => Cookie::IS_USER_ALLOWED_SAVE_COOKIE,
                'debug' => $this->config->isDebug(),
                'product' => $product && $product->getId() ? ['product_id' => (string) $product->getId()] : null
            ];
            return json_encode(['*' => ['Rejoiner_Acr/js/storefront-tracking' => $options]],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            return '';
        }
    }
}
