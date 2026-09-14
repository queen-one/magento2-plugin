<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Test\Unit;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Cookie\Helper\Cookie;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Rejoiner\Acr\CustomerData\QueenOne;
use Rejoiner\Acr\Model\CartSnapshot;
use Rejoiner\Acr\Model\TrackingConfig;

class QueenOneSectionTest extends TestCase
{
    public function testLoggedInCustomerPublishesOnlyApprovedIdentityFields(): void
    {
        $config = $this->createStub(TrackingConfig::class);
        $config->method('getSiteId')->willReturn('site'); $config->method('isEnabled')->willReturn(true);
        $checkout = $this->createStub(CheckoutSession::class);
        $checkout->method('getQuote')->willReturn($this->createStub(\Magento\Quote\Model\Quote::class));
        $customer = $this->createStub(CustomerSession::class);
        $customer->method('isLoggedIn')->willReturn(true);
        $profile = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $profile->method('getEmail')->willReturn(' buyer@example.test ');
        $customer->method('getCustomerData')->willReturn($profile);
        $cookie = $this->createStub(Cookie::class);
        $cookie->method('isUserNotAllowSaveCookie')->willReturn(false);
        $store = $this->createStub(Store::class); $store->method('getId')->willReturn('1');
        $stores = $this->createStub(StoreManagerInterface::class); $stores->method('getStore')->willReturn($store);
        $cart = $this->createStub(CartSnapshot::class);
        $cart->method('build')->willReturn(['items' => [], 'cart_item_count' => 0, 'cart_value' => 0.0]);
        $section = new QueenOne($config, $checkout, $customer, $stores, $cookie, $cart, new NullLogger());
        $data = $section->getSectionData();
        self::assertSame('ready', $data['status']);
        self::assertSame(['email' => 'buyer@example.test'], $data['identity']);
    }

    public function testDeniedConsentDoesNotReadCustomerOrQuote(): void
    {
        $config = $this->createStub(TrackingConfig::class);
        $config->method('getSiteId')->willReturn('site'); $config->method('isEnabled')->willReturn(true);
        $checkout = $this->createMock(CheckoutSession::class);
        $checkout->expects(self::never())->method('getQuote');
        $customer = $this->createMock(CustomerSession::class);
        $customer->expects(self::never())->method('isLoggedIn');
        $cookie = $this->createStub(Cookie::class);
        $cookie->method('isUserNotAllowSaveCookie')->willReturn(true);
        $store = $this->createStub(Store::class); $store->method('getId')->willReturn('1');
        $stores = $this->createStub(StoreManagerInterface::class); $stores->method('getStore')->willReturn($store);
        $section = new QueenOne($config, $checkout, $customer, $stores, $cookie, new CartSnapshot(), new NullLogger());
        self::assertSame(['version' => 1, 'status' => 'unavailable', 'store_id' => '1', 'site_id' => 'site'], $section->getSectionData());
    }

    public function testExtractionFailureReturnsErrorRatherThanAnEmptyCart(): void
    {
        $config = $this->createStub(TrackingConfig::class);
        $config->method('getSiteId')->willReturn('site'); $config->method('isEnabled')->willReturn(true);
        $checkout = $this->createStub(CheckoutSession::class);
        $checkout->method('getQuote')->willThrowException(new \RuntimeException('private details'));
        $customer = $this->createStub(CustomerSession::class);
        $customer->method('isLoggedIn')->willReturn(false);
        $cookie = $this->createStub(Cookie::class);
        $cookie->method('isUserNotAllowSaveCookie')->willReturn(false);
        $store = $this->createStub(Store::class); $store->method('getId')->willReturn('1');
        $stores = $this->createStub(StoreManagerInterface::class); $stores->method('getStore')->willReturn($store);
        $section = new QueenOne($config, $checkout, $customer, $stores, $cookie, new CartSnapshot(), new NullLogger());
        $data = $section->getSectionData();
        self::assertSame('error', $data['status']);
        self::assertArrayNotHasKey('cart', $data);
        self::assertStringNotContainsString('private details', json_encode($data));
    }
}
