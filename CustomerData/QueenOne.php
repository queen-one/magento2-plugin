<?php
declare(strict_types=1);

namespace Rejoiner\Acr\CustomerData;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Cookie\Helper\Cookie;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Rejoiner\Acr\Model\CartSnapshot;
use Rejoiner\Acr\Model\TrackingConfig;
use Throwable;

class QueenOne implements SectionSourceInterface
{
    public function __construct(
        private TrackingConfig $config,
        private CheckoutSession $checkoutSession,
        private CustomerSession $customerSession,
        private StoreManagerInterface $storeManager,
        private Cookie $cookie,
        private CartSnapshot $cartSnapshot,
        private LoggerInterface $logger
    ) {
    }

    public function getSectionData(): array
    {
        $result = ['version' => 1, 'status' => 'unavailable'];
        try {
            $result['store_id'] = (string) $this->storeManager->getStore()->getId();
            $result['site_id'] = $this->config->getSiteId();
            if (!$this->config->isEnabled() || $this->cookie->isUserNotAllowSaveCookie()) {
                return $result;
            }
            $email = $this->customerSession->isLoggedIn()
                ? trim((string) $this->customerSession->getCustomerData()->getEmail()) : '';
            $result['identity'] = $email !== '' ? ['email' => $email] : null;
            $result['cart'] = $this->cartSnapshot->build($this->checkoutSession->getQuote());
            $result['status'] = 'ready';
        } catch (Throwable $error) {
            // Do not let tracking break the entire Magento customer-data response.
            $result['status'] = 'error';
            try {
                if ($this->config->isDebug()) {
                    $this->logger->warning('Queen One storefront section unavailable', ['error_type' => get_class($error)]);
                }
            } catch (Throwable $loggingError) {
                // Diagnostics must not turn a tracking failure into a customer-data failure.
            }
        }
        return $result;
    }
}
