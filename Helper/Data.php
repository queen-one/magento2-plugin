<?php
/**
 * Copyright © 2017 Rejoiner. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Rejoiner\Acr\Helper;

use Laminas\Http\Client;
use Laminas\Http\ClientFactory;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManager;
use Magento\Sales\Model\Order;
use Magento\SalesRule\Model\Coupon\CodegeneratorFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\RuleFactory;
use \Magento\Store\Model\ScopeInterface;
use Monolog\Logger;
use Rejoiner\Acr\Model\System\Config\Source\FrontendTrackingMode;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    private const XML_PATH_REJOINER_ENABLED                         = 'checkout/rejoiner_acr/enabled';
    private const XML_PATH_REJOINER_SITE_ID                        = 'checkout/rejoiner_acr/site_id';
    private const XML_PATH_FRONTEND_TRACKING_MODE                   = 'checkout/rejoiner_acr/frontend_tracking_mode';
    private const XML_PATH_QUEEN_ONE_TAG_URL                        = 'checkout/rejoiner_acr/queen_one_tag_url';
    private const XML_PATH_REJOINER_DOMAIN                         = 'checkout/rejoiner_acr/domain';
    private const XML_PATH_REJOINER_TRACK_NUMBERS                  = 'checkout/rejoiner_acr/track_numbers';
    private const XML_PATH_REJOINER_TRACK_PRICE_WITH_TAX           = 'checkout/rejoiner_acr/track_price_with_tax';
    private const XML_PATH_REJOINER_PERSIST_FORMS                  = 'checkout/rejoiner_acr/persist_forms';
    private const XML_PATH_REJOINER_DEBUG_ENABLED                  = 'checkout/rejoiner_acr/debug_enabled';
    private const XML_PATH_REJOINER_API_KEY                        = 'checkout/rejoiner_acr/api_key';
    private const XML_PATH_REJOINER_API_SECRET                     = 'checkout/rejoiner_acr/api_secret';
    private const XML_PATH_REJOINER_API_SITE_ID                    = 'checkout/rejoiner_acr/site_id';
    private const XML_PATH_REJOINER_PROCESS_BY_CRON                = 'checkout/rejoiner_acr/process_by_cron';
    private const XML_PATH_REJOINER_COUPON_GENERATION              = 'checkout/rejoiner_acr/coupon_code';
    private const XML_PATH_REJOINER_COUPON_RULE                    = 'checkout/rejoiner_acr/salesrule_model';
    private const XML_PATH_REJOINER_THUMBNAIL_WIDTH                = 'checkout/rejoiner_acr/thumbnail_size_width';
    private const XML_PATH_REJOINER_THUMBNAIL_HEIGHT               = 'checkout/rejoiner_acr/thumbnail_size_height';
    private const XML_PATH_REJOINER_PASS_NEW_CUSTOMERS             = 'checkout/rejoiner_acr/passing_new_customers';
    private const XML_PATH_REJOINER_LIST_ID                        = 'checkout/rejoiner_acr/list_id';
    private const XML_PATH_REJOINER_MARKETING_PERMISSIONS          = 'checkout/rejoiner_acr/marketing_permissions';
    private const XML_PATH_REJOINER_MARKETING_LIST_ID              = 'checkout/rejoiner_acr/marketing_list_id';
    private const XML_PATH_REJOINER_SUBSCRIBE_GUEST_CHECKOUT       = 'checkout/rejoiner_acr/subscribe_checkout_onepage_index';
    private const XML_PATH_REJOINER_SUBSCRIBE_ACCOUNT_REGISTRATION = 'checkout/rejoiner_acr/subscribe_customer_account_create';
    private const XML_PATH_REJOINER_SUBSCRIBE_LOGIN_CHECKOUT       = 'checkout/rejoiner_acr/subscribe_customer_account_login';
    private const XML_PATH_REJOINER_SUBSCRIBE_CUSTOMER_ACCOUNT     = 'checkout/rejoiner_acr/subscribe_newsletter_manage_index';
    private const XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_DEFAULT     = 'checkout/rejoiner_acr/subscribe_checkbox_default';
    private const XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_LABEL       = 'checkout/rejoiner_acr/subscribe_checkbox_label';
    private const XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_SELECTOR    = 'checkout/rejoiner_acr/subscribe_checkbox_selector';
    private const XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_STYLE       = 'checkout/rejoiner_acr/subscribe_checkbox_style';

    public const STATUS_SUBSCRIBED                                = 1;
    public const STATUS_UNSUBSCRIBED                              = 2;

    private const REJOINER2_SITE_ID_LENGTH                         = 7;

    private const REJOINER_VERSION_1                               = 'v1';
    private const REJOINER_VERSION_2                               = 'v2';

    public const REMOVED_CART_ITEM_SKU_VARIABLE                   = 'rejoiner_sku';

    private const SUCCESS_RESPONSE_CODE = 0;

    private const ERROR_RESPONSE_CODE = 1;

    /**
     * @param CouponFactory $couponFactory
     * @param CodegeneratorFactory $codegeneratorFactory
     * @param RuleFactory $ruleFactory
     * @param Session $checkoutSession
     * @param SessionManager $sessionManager
     * @param ClientFactory $httpClient
     * @param Http $request
     * @param Logger $logger
     * @param Serializer $serializer
     * @param Context $context
     */
    public function __construct(
        private CouponFactory $couponFactory,
        private CodegeneratorFactory $codegeneratorFactory,
        private RuleFactory $ruleFactory,
        private Session $checkoutSession,
        private SessionManager $sessionManager,
        private ClientFactory $httpClient,
        private Http $request,
        private Logger $logger,
        private Serializer $serializer,
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     * @return string
     */
    public function getRejoinerApiUri()
    {
        switch ($this->getRejoinerVersion()) {
            case self::REJOINER_VERSION_2:
                return 'https://rj2.rejoiner.com';
            default:
                return 'https://app.rejoiner.com';
        }
    }

    /**
     * @return string
     */
    public function getRejoinerApiPath()
    {
        switch ($this->getRejoinerVersion()) {
            case self::REJOINER_VERSION_2:
                return '/api/v1/%s';
            default:
                return '/api/1.0/site/%s';
        }
    }

    /**
     * @return string
     */
    public function getRejoinerApiConvertPath()
    {
        return match ($this->getRejoinerVersion()) {
            self::REJOINER_VERSION_2 => $this->getRejoinerApiPath() . '/customer/convert/',
            default => $this->getRejoinerApiPath() . '/lead/convert',
        };
    }

    /**
     * @return string
     */
    public function getRejoinerApiAddToListPath($listId)
    {
        return match ($this->getRejoinerVersion()) {
            self::REJOINER_VERSION_2 => $this->getRejoinerApiPath() . "/lists/$listId/contacts/",
            default => $this->getRejoinerApiPath() . '/contact_add',
        };
    }

    /**
     * @return string
     */
    public function getRejoinerApiUnSubscribePath()
    {
        return match ($this->getRejoinerVersion()) {
            self::REJOINER_VERSION_2 => $this->getRejoinerApiPath() . '/customer/unsubscribe/',
            default => $this->getRejoinerApiPath() . '/lead/unsubscribe',
        };
    }

    /**
     * @param $price int
     * @return float
     */
    public function convertPriceToCents($price): float
    {
        return round($price*100);
    }

    /**
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getRestoreUrl(): string
    {
        $product = [];
        if ($items = $this->checkoutSession->getQuote()->getAllVisibleItems()) {
            foreach ($items as $item) {
                $options = $this->serializer->decode($item->getOptionByCode('info_buyRequest')->getValue());
                $options['qty'] = $item->getQty();
                $options['product'] = $item->getProductId();
                $product[] = $options;
            }
        }
        $googleAttributesArray = $this->returnGoogleAttributes();
        $customAttributesArray = $this->returnCustomAttributes();
        $params = array_merge($product, $googleAttributesArray, $customAttributesArray);
        $url = $this->_urlBuilder->getUrl('rejoiner/addtocart', [
            '_query'  => $params,
            '_secure' => true
        ]);

        return $url;
    }

    /**
     * @return array
     */
    public function returnGoogleAttributes(): array
    {
        $result = [];
        if ($googleAnalitics = $this->scopeConfig->getValue('checkout/rejoiner_acr/google_attributes', ScopeInterface::SCOPE_STORE)) {
            foreach (unserialize($googleAnalitics) as $attr) {
                if ($attr['attr_name'] && $attr['value']) {
                    $result[$attr['attr_name']] = $attr['value'];
                }
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    public function returnCustomAttributes(): array
    {
        $result = [];
        if ($customAttr = $this->scopeConfig->getValue('checkout/rejoiner_acr/custom_attributes', ScopeInterface::SCOPE_STORE)) {
            foreach (unserialize($customAttr) as $attr) {
                if ($attr['attr_name'] && $attr['value']) {
                    $result[$attr['attr_name']] = $attr['value'];
                }
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getExtraCodes(): array
    {
        $result = [];
        if ($extraCodes = $this->scopeConfig->getValue('checkout/rejoiner_acr/extra_codes', ScopeInterface::SCOPE_STORE)) {
            foreach (unserialize($extraCodes) as $extraCode) {
                if ($extraCode['promo_param'] && $extraCode['promo_salesrule']) {
                    $result[$extraCode['promo_param']] = $extraCode['promo_salesrule'];
                }
            }
        }

        return $result;
    }

    /**
     * @param $rule_id
     * @param string $param
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function generateCouponCode($rule_id, string $param = 'promo'): string
    {
        $quote = $this->checkoutSession->getQuote();
        $serializedCodes = (string) $quote->getPromo();
        $codes = $serializedCodes !== ''
            ? unserialize($serializedCodes, ['allowed_classes' => false])
            : [];
        $codes = is_array($codes) ? $codes : [];
        $couponCode = $codes[$param] ?? '';

        $ruleItem = $this->ruleFactory->create()->load($rule_id);
        if ($ruleItem->getUseAutoGeneration() && !$couponCode) {
            $couponCode = $this->codegeneratorFactory->create()->generateCode();
            $salesRuleModel = $this->couponFactory->create();
            $salesRuleModel->setRuleId($rule_id)
                ->setCode($couponCode)
                ->setUsageLimit(1)
                ->setCreatedAt(time())
                ->setType(\Magento\SalesRule\Helper\Coupon::COUPON_TYPE_SPECIFIC_AUTOGENERATED)
                ->save();

            $codes[$param] = $couponCode;
            $quote->setPromo(serialize($codes))->save();
        }

        return $couponCode;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_ENABLED);
    }

    /**
     * @return string
     */
    public function getDomain(): string
    {
        $domain = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_REJOINER_DOMAIN,
            ScopeInterface::SCOPE_STORE
        ));

        if ($domain === '') {
            return '';
        }

        return ($domain[0] === '.') ? $domain : '.' . $domain;
    }

    /**
     * @return string
     */
    public function getRejoinerSiteId(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_SITE_ID, ScopeInterface::SCOPE_STORE);
    }

    public function getFrontendTrackingMode(): string
    {
        $mode = (string) $this->scopeConfig->getValue(
            self::XML_PATH_FRONTEND_TRACKING_MODE,
            ScopeInterface::SCOPE_STORE
        );

        return in_array(
            $mode,
            [FrontendTrackingMode::REJOINER, FrontendTrackingMode::DUAL, FrontendTrackingMode::QUEEN_ONE],
            true
        ) ? $mode : FrontendTrackingMode::REJOINER;
    }

    public function isRejoinerFrontendEnabled(): bool
    {
        return in_array(
            $this->getFrontendTrackingMode(),
            [FrontendTrackingMode::REJOINER, FrontendTrackingMode::DUAL],
            true
        );
    }

    public function isQueenOneFrontendEnabled(): bool
    {
        return in_array(
            $this->getFrontendTrackingMode(),
            [FrontendTrackingMode::DUAL, FrontendTrackingMode::QUEEN_ONE],
            true
        );
    }

    public function getQueenOneTagUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_QUEEN_ONE_TAG_URL,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * @return string
     */
    public function getRejoinerVersion(): string
    {
        $siteId = $this->getRejoinerSiteId();
        $siteIdLength = strlen($siteId);

        if ($siteIdLength == self::REJOINER2_SITE_ID_LENGTH) {
            return self::REJOINER_VERSION_2;
        }

        return self::REJOINER_VERSION_1;
    }

    /**
     * @return string
     */
    public function getRejoinerScriptUri(): string
    {
        switch ($this->getRejoinerVersion()) {
            case self::REJOINER_VERSION_2:
                return 'https://cdn.rejoiner.com/js/v4/rj2.lib.js';
            default:
                return 'https://cdn.rejoiner.com/js/v4/rejoiner.lib.js';
        }
    }

    /**
     * @return int
     */
    public function getTrackNumberEnabled(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_TRACK_NUMBERS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getTrackPriceWithTax(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_TRACK_PRICE_WITH_TAX, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getPersistFormsEnabled(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_PERSIST_FORMS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getIsEnabledCouponCodeGeneration(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_COUPON_GENERATION, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getCouponCodeRuleId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_COUPON_RULE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getImageWidth(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_THUMBNAIL_WIDTH, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getImageHeight(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_THUMBNAIL_HEIGHT);
    }

    /**
     * @return string
     */
    public function getShouldBeProcessedByCron(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_PROCESS_BY_CRON);
    }

    /**
     * @return bool|string
     */
    public function getRejoinerApiSecret(): bool|string
    {
        return match ($this->getRejoinerVersion()) {
            self::REJOINER_VERSION_2 => true,
            default => (string)$this->scopeConfig->getValue(self::XML_PATH_REJOINER_API_SECRET),
        };
    }

    /**
     * @return string
     */
    public function getRejoinerApiKey(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_API_KEY);
    }

    /**
     * @return bool
     */
    public function getRejoinerMarketingPermissions(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_MARKETING_PERMISSIONS);
    }

    /**
     * @return string
     */
    public function getRejoinerMarketingListID(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_MARKETING_LIST_ID);
    }

    /**
     * @return bool
     */
    public function getRejoinerSubscribeGuestCheckout(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_SUBSCRIBE_GUEST_CHECKOUT);
    }

    /**
     * @return bool
     */
    public function getRejoinerSubscribeAccountRegistration(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_SUBSCRIBE_ACCOUNT_REGISTRATION);
    }

    /**
     * @return bool
     */
    public function getRejoinerSubscribeLoginCheckout(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_SUBSCRIBE_LOGIN_CHECKOUT);
    }

    /**
     * @return bool
     */
    public function getRejoinerSubscribeCustomerAccount(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_SUBSCRIBE_CUSTOMER_ACCOUNT);
    }

    /**
     * @return bool
     */
    public function getRejoinerSubscribeCheckedDefault(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_DEFAULT);
    }

    /**
     * @return string
     */
    public function getRejoinerSubscribeCheckboxLabel(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_LABEL);
    }

    /**
     * @return string
     */
    public function getRejoinerSubscribeCheckboxSelector(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_SELECTOR);
    }

    /**
     * @return string
     */
    public function getRejoinerSubscribeCheckboxStyle(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_SUBSCRIBE_CHECKBOX_STYLE);
    }

    /**
     * @param string $message
     * @param bool $force
     */
    public function log(string $message, bool $force = false): void
    {
        if ($this->isDebugEnabled() || $force) {
            if ($force) {
                $this->logger->critical($message);
            } else {
                $this->logger->info($message);
            }
        }
    }

    protected function isDebugEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_REJOINER_DEBUG_ENABLED, ScopeInterface::SCOPE_STORE);
    }


    /**
     * If shopping cart information should be sent to Rejoiner service on current page
     * @return int
     */
    public function getShoppingCartDataOnThisPage(): int
    {
        return (int) in_array(
            $this->getCurrentPageName(),
            [
                'checkout_cart_index',
                'checkout_index_index',
                'multishipping_checkout_login',
                'multishipping_checkout_addresses'
            ]
        );
    }

    /**
     * @return string
     */
    public function getCurrentPageName(): string
    {
        return $this->request->getFullActionName();
    }

    /**
     * @return bool|mixed
     */
    public function checkRemovedItem(): mixed
    {
        $session = $this->sessionManager;
        $removedItems = $session->getData(self::REMOVED_CART_ITEM_SKU_VARIABLE);
        $session->unsetData(self::REMOVED_CART_ITEM_SKU_VARIABLE);
        return $removedItems;
    }

    /**
     * @param Order $orderModel
     * @return int
     */
    public function sendInfoToRejoiner(Order $orderModel): int
    {
        try {
            $customerEmail = $orderModel->getCustomerEmail();
            $this->convert($customerEmail);

            $passNewCustomers = $this->scopeConfig->getValue(self::XML_PATH_REJOINER_PASS_NEW_CUSTOMERS);
            $listId = $this->scopeConfig->getValue(self::XML_PATH_REJOINER_LIST_ID);

            if ($passNewCustomers && $listId) {
                $customerName = $orderModel->getCustomerFirstname();
                $this->addToList($listId, $customerEmail, $customerName);
            }

            return self::SUCCESS_RESPONSE_CODE;
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }

        return self::ERROR_RESPONSE_CODE;
    }

    /**
     * @param string $email
     * @param string|null $customerName
     * @return $this
     */
    public function subscribe(string $email, ?string $customerName = null): static
    {
        $this->addToList($this->getRejoinerMarketingListID(), $email, $customerName);

        return $this;
    }

    /**
     * @param $email
     * @return $this
     * @throws \Exception
     */
    public function unSubscribe($email): static
    {
        $apiUnSubscribePath = $this->getRejoinerApiUnSubscribePath();
        $client = $this->prepareClient($apiUnSubscribePath, ['email' => $email]);
        $this->sendRequest($client);

        return $this;
    }

    /**
     * @param string $email
     * @return void
     */
    private function convert(string $email): void
    {
        try {
            $apiConvertPath = $this->getRejoinerApiConvertPath();
            $client = $this->prepareClient($apiConvertPath, ['email' => $email]);
            $this->sendRequest($client);
        } catch (\Exception $e) {
            $this->log($e->getMessage());
        }

    }

    /**
     * @param $listId
     * @param $email
     * @param $customerName
     * @return void
     * @throws \Exception
     */
    private function addToList($listId, $email, $customerName = null): void
    {
        if (!$listId) {
            return;
        }

        $data = [
            'email'      => $email,
            'list_id'    => $listId
        ];

        if ($customerName) {
            $data['first_name'] = $customerName;
        }

        $apiAddToListPath = $this->getRejoinerApiAddToListPath($listId);
        $client = $this->prepareClient($apiAddToListPath, $data);
        $this->sendRequest($client);

    }

    /**
     * @param $path
     * @param array $data
     * @return Client
     * @throws \Exception
     */
    private function prepareClient($path, array $data): Client
    {
        $apiKey          = $this->scopeConfig->getValue(self::XML_PATH_REJOINER_API_KEY);
        $siteId          = $this->scopeConfig->getValue(self::XML_PATH_REJOINER_API_SITE_ID);
        $rejoinerVersion = $this->getRejoinerVersion();

        if (!$apiKey || !$siteId || empty($data)) {
            $error = 'Missing API credentials';
            $this->log($error, true);
            throw new \Exception($error);
        }

        $requestBody   = mb_convert_encoding(json_encode($data), 'UTF-8', 'ISO-8859-1');
        $requestPath   = sprintf($path, $siteId);
        $authorization = sprintf('Rejoiner %s', $apiKey);

        if ($rejoinerVersion == self::REJOINER_VERSION_1) {
            $apiSecret = $this->scopeConfig->getValue(self::XML_PATH_REJOINER_API_SECRET);

            if (!$apiSecret) {
                $error = 'Missing API secret';
                $this->log($error, true);
                throw new \Exception($error);
            }

            $apiSecret = mb_convert_encoding((string) $apiSecret, 'UTF-8', 'ISO-8859-1');

            $hmacData       = mb_convert_encoding(implode("\n", [\Laminas\Http\Request::METHOD_POST, $requestPath, $requestBody]), 'UTF-8', 'ISO-8859-1');
            $codedApiSecret = base64_encode(hash_hmac('sha1', $hmacData, $apiSecret, true));
            $authorization  = sprintf('Rejoiner %s:%s', $apiKey, $codedApiSecret);
        }

        /** @var Client $client */
        $rejoinerApiUri = $this->getRejoinerApiUri();
        $client = $this->httpClient->create(['uri' => $rejoinerApiUri . $requestPath]);
        $client->setRawBody($requestBody);
        $client->setHeaders(['Authorization' => $authorization, 'Content-type' => 'application/json;']);

        return $client;
    }

    /**
     * @param Client $client
     * @return int
     * @throws \Exception
     */
    private function sendRequest(Client $client): int
    {
        try {
            $req = $client->setMethod(\Laminas\Http\Request::METHOD_POST);
            $res = $req->send();
            $responseCode = $res->getStatusCode();
        } catch (\Exception $e) {
            $this->log($e->getMessage());
            $responseCode = 000;
        }

        switch ($responseCode) {
            case '200':
                $this->log($responseCode . ': Everything is alright.');
                break;
            case '400':
                $error = $responseCode . ': required params were not specified and/or the body was malformed';
                $this->log($error, true);
                $this->log($res->getBody(), true);
                throw new \Exception($error);
                break;
            case '403':
                $error = $responseCode . ': failed authentication and/or incorrect signature';
                $this->log($error, true);
                throw new \Exception($error);
                break;
            case '500':
                $error = $responseCode . ': internal error, contact us for details';
                $this->log($error, true);
                throw new \Exception($error);
                break;
            default:
                $error = $responseCode . ': unexpected response code';
                $this->log($error, true);
                throw new \Exception($error);
                break;
        }

        return $responseCode;
    }


    /**
     * @param Product $product
     * @param $categoriesArray
     * @return array
     */
    public function getProductCategories(Product $product, $categoriesArray): array
    {
        $result = [];
        foreach ($product->getCategoryIds() as $catId) {
            if (isset($categoriesArray[$catId])) {
                /** @var \Magento\Catalog\Model\Category $category */
                $category = $categoriesArray[$catId];
                $result[] = $category->getName();
            }
        }

        return $result;
    }
}
