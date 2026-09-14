<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Test\Unit;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use PHPUnit\Framework\TestCase;
use Rejoiner\Acr\Model\CartSnapshot;
use UnexpectedValueException;

class CartSnapshotTest extends TestCase
{
    private function quote(array $items): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems'])->getMock();
        $quote->expects(self::atLeastOnce())->method('getAllVisibleItems')->willReturn($items);
        $quote->setData(['entity_id' => '42', 'quote_currency_code' => 'EUR', 'coupon_code' => 'SAVE']);
        $quote->setId('42');
        return $quote;
    }

    private function item(string $type = 'simple', float $qty = 2.0): Item
    {
        $parent = $this->createStub(Product::class);
        $parent->method('getTypeId')->willReturn($type);
        $parent->method('getData')->willReturn('PARENT');
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getOptionByCode'])->getMock();
        $item->setData(['item_id' => '9', 'product_id' => '100', 'product_type' => $type, 'qty' => $qty,
            'price_incl_tax' => 12.5, 'row_total_incl_tax' => 12.5 * $qty]);
        $item->expects(self::atLeastOnce())->method('getProduct')->willReturn($parent);
        if ($type === 'configurable') {
            $child = $this->createStub(Product::class);
            $child->method('getId')->willReturn('101');
            $child->method('getData')->willReturn('CHILD');
            $option = $this->getMockBuilder(Option::class)->disableOriginalConstructor()->onlyMethods(['getProduct'])->getMock();
            $option->expects(self::once())->method('getProduct')->willReturn($child);
            $item->expects(self::atLeastOnce())->method('getOptionByCode')->willReturnCallback(
                fn ($code) => $code === 'simple_product' ? $option : null
            );
        }
        return $item;
    }

    public function testSimpleCartUsesEntityIdsAndQuoteMoney(): void
    {
        $cart = (new CartSnapshot())->build($this->quote([$this->item()]));
        self::assertSame('42', $cart['cart_id']);
        self::assertSame('EUR', $cart['currency_code']);
        self::assertSame(25.0, $cart['cart_value']);
        self::assertSame(2, $cart['cart_item_count']);
        self::assertSame('SAVE', $cart['promo']);
        self::assertSame('100', $cart['items'][0]['product_id']);
        self::assertSame('PARENT', $cart['items'][0]['sku']);
        self::assertArrayNotHasKey('variant_id', $cart['items'][0]);
    }

    public function testConfigurablePreservesParentAndSelectedChild(): void
    {
        $cart = (new CartSnapshot())->build($this->quote([$this->item('configurable')]));
        self::assertSame('100', $cart['items'][0]['product_id']);
        self::assertSame('101', $cart['items'][0]['variant_id']);
        self::assertSame('CHILD', $cart['items'][0]['variant_sku']);
    }

    public function testEmptyCartIsExplicitEvenWithoutQuoteId(): void
    {
        $quote = $this->quote([]); $quote->setId(null);
        $cart = (new CartSnapshot())->build($quote);
        self::assertNull($cart['cart_id']);
        self::assertSame([], $cart['items']);
        self::assertSame(0, $cart['cart_item_count']);
        self::assertSame(0.0, $cart['cart_value']);
    }

    public function testFractionalQuantityDoesNotProduceTruncatedCart(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new CartSnapshot())->build($this->quote([$this->item('simple', 1.5)]));
    }

    public function testMissingMoneyDoesNotBecomeZero(): void
    {
        $item = $this->item(); $item->unsetData('row_total_incl_tax');
        $this->expectException(UnexpectedValueException::class);
        (new CartSnapshot())->build($this->quote([$item]));
    }

    public function testUnsupportedBundleDoesNotPublishPartialCart(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new CartSnapshot())->build($this->quote([$this->item(), $this->item('bundle')]));
    }
}
