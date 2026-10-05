<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Test\Unit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rejoiner\Acr\Model\Backend\Config;
use Rejoiner\Acr\Model\Backend\Envelope;
use Rejoiner\Acr\Model\Backend\Outbox;
use Rejoiner\Acr\Model\Backend\Runner;
use Rejoiner\Acr\Model\Backend\Transport;

class BackendTest extends TestCase
{
    private function configValues(): array
    {
        return ['integration_id' => '507f1f77bcf86cd799439011', 'site_id' => 'magento-contract-fixture',
            'endpoint' => 'https://bridge.example/webhooks/events/magento2/507f1f77bcf86cd799439011', 'secret' => str_repeat('a', 64)];
    }

    public function testStableContractIdentityAndExactByteSignature(): void
    {
        $config = $this->configValues();
        self::assertSame(
            '9cc48dcd691b790220b93f4d04352f8928cd744b6ac7c09a63c8b0bc4f4436a6',
            Envelope::eventId($config['integration_id'], $config['site_id'], '42')
        );
        $body = '{"order":"42"}';
        self::assertSame(
            hash_hmac('sha256', '1789552800.' . $body, $config['secret']),
            Transport::signature($body, $config['secret'], 1789552800)
        );
        self::assertNotSame(
            Transport::signature($body, $config['secret'], 1789552800),
            Transport::signature($body . ' ', $config['secret'], 1789552800)
        );
    }

    public function testConfigIsStoreScopedAndDecryptsWithoutLegacyCredentials(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects(self::once())->method('isSetFlag')->with('checkout/queen_one_connect/backend_enabled', 'store', 2)->willReturn(true);
        $values = ['bridge_url' => 'https://bridge.example', 'integration_id' => $this->configValues()['integration_id'],
            'site_id' => 'magento-contract-fixture', 'hmac_secret' => 'encrypted'];
        $scope->method('getValue')->willReturnCallback(function ($path, $type, $store) use ($values) {
            self::assertSame('store', $type);
            self::assertSame(2, $store);
            return $values[substr($path, strlen('checkout/queen_one_connect/'))] ?? throw new \RuntimeException('Unexpected setting');
        });
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())->method('decrypt')->with('encrypted')->willReturn(str_repeat('a', 64));
        $config = new Config($scope, $encryptor);
        self::assertTrue($config->isEnabled(2));
        self::assertEquals($this->configValues(), $config->get(2));
    }

    public function testEnvelopePreservesNativeParentChildItemsAndDecimalQuantities(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getAllItems'])->getMock();
        $order->setData(['entity_id' => 42, 'increment_id' => '000000042', 'quote_id' => 51,
            'created_at' => '2026-09-16 10:00:00', 'store_id' => 1, 'customer_is_guest' => 1,
            'customer_email' => 'guest@example.test', 'order_currency_code' => 'USD',
            'subtotal' => '35.0000', 'grand_total' => '38.0000', 'tax_amount' => '4.0000',
            'shipping_amount' => '2.0000', 'discount_amount' => '-3.0000', 'coupon_code' => 'SAVE3']);
        $items = [];
        foreach ([[81, null, 10, 'simple', '0.5'], [82, null, 20, 'configurable', '2.0000'], [83, 82, 21, 'simple', '2.0000']] as $data) {
            $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
            $item->expects(self::once())->method('getId')->willReturn($data[0]);
            $item->setData(['item_id' => $data[0], 'parent_item_id' => $data[1], 'product_id' => $data[2],
                'product_type' => $data[3], 'qty_ordered' => $data[4], 'sku' => 'sku', 'name' => 'Product',
                'price_incl_tax' => '14.0000', 'row_total_incl_tax' => '28.0000', 'discount_amount' => '2.0000']);
            $items[] = $item;
        }
        $order->expects(self::once())->method('getAllItems')->willReturn($items);
        $envelope = (new Envelope())->build($order, $this->configValues());
        self::assertSame('2026-09-16T10:00:00Z', $envelope['order']['created_at']);
        self::assertSame('0.5', $envelope['order']['items'][0]['qty_ordered']);
        self::assertSame('20', $envelope['order']['items'][1]['product_id']);
        self::assertSame('21', $envelope['order']['items'][2]['product_id']);
        self::assertSame('82', $envelope['order']['items'][2]['parent_item_id']);
        self::assertSame('-3.0000', $envelope['order']['discount_amount']);
    }

    public function testUnsafeBridgeEndpointsAreRejected(): void
    {
        foreach (['http://bridge.example', 'https://user:pass@bridge.example',
            'https://bridge.example?token=secret', 'https://bridge.example#fragment'] as $url) {
            $scope = $this->createStub(ScopeConfigInterface::class);
            $scope->method('getValue')->willReturnCallback(fn ($path) => match (basename($path)) {
                'bridge_url' => $url, 'integration_id' => '507f1f77bcf86cd799439011',
                'site_id' => 'site', 'hmac_secret' => 'encrypted',
                default => ''
            });
            $encryptor = $this->createStub(EncryptorInterface::class);
            $encryptor->method('decrypt')->willReturn(str_repeat('a', 64));
            try {
                (new Config($scope, $encryptor))->get(1);
                self::fail('Unsafe endpoint accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('Incomplete or invalid Queen One backend configuration', $error->getMessage());
            }
        }
    }

    public function testDeliveryRetainsFailureAndAcceptsOnly202(): void
    {
        foreach ([0 => 'pending', 503 => 'pending', 429 => 'pending', 401 => 'failed', 400 => 'failed', 301 => 'failed', 200 => 'failed', 202 => 'accepted'] as $http => $expected) {
            $config = $this->createStub(Config::class);
            $config->method('isEnabled')->willReturn(true);
            $config->method('get')->willReturn($this->configValues());
            $outbox = $this->createMock(Outbox::class);
            $outbox->method('pending')->willReturn([array_merge($this->configValues(), [
                'entity_id' => 1, 'attempts' => 0, 'payload' => '{"immutable":true}',
            ])]);
            $updates = [];
            $outbox->expects(self::exactly(2))->method('update')->willReturnCallback(function ($id, $data) use (&$updates): void {
                $updates[] = $data;
            });
            $transport = $this->createMock(Transport::class);
            $transport->expects(self::once())->method('send')->with($this->configValues()['endpoint'], '{"immutable":true}', str_repeat('a', 64))->willReturn($http);
            $locks = $this->createMock(LockManagerInterface::class);
            $locks->method('lock')->willReturn(true);
            $locks->expects(self::once())->method('unlock');
            $runner = new Runner($config, $outbox, $transport, $locks, $this->createStub(StoreManagerInterface::class), $this->createStub(LoggerInterface::class));
            $runner->runStore(1);
            self::assertSame(1, $updates[0]['attempts']);
            self::assertSame($expected, $updates[1]['status']);
        }
    }

    public function testBackgroundFailuresNeverEscape(): void
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willThrowException(new \RuntimeException('Unavailable'));
        $runner = new Runner(
            $this->createStub(Config::class),
            $this->createStub(Outbox::class),
            $this->createStub(Transport::class),
            $this->createStub(LockManagerInterface::class),
            $stores,
            $this->createStub(LoggerInterface::class)
        );
        $runner->execute();
        self::assertTrue(true);
    }

    public function testSharedBridgeFixtureMatchesPhpEnvelopeExactly(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/order.json'), true, 512, JSON_THROW_ON_ERROR);
        $data = $fixture['order'];
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getAllItems', 'getId'])->getMock();
        $order->setData(array_merge($data, ['created_at' => '2026-09-16 10:00:00']));
        $order->expects(self::exactly(2))->method('getId')->willReturn($data['entity_id']);
        $items = [];
        foreach ($data['items'] as $row) {
            $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
            $item->setData($row);
            $item->expects(self::once())->method('getId')->willReturn($row['item_id']);
            $items[] = $item;
        }
        $order->expects(self::once())->method('getAllItems')->willReturn($items);
        self::assertSame($fixture, (new Envelope())->build($order, $this->configValues()));
        $body = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertSame(
            '88860d8f0a5f06184c1fda659216fe9143025f1a3cec5f70acd2d4e631ff934b',
            Transport::signature($body, str_repeat('a', 64), 1789552800)
        );
    }
}
