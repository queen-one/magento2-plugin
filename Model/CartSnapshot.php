<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model;

use Magento\Quote\Model\Quote;
use UnexpectedValueException;

class CartSnapshot
{
    public function build(Quote $quote): array
    {
        $items = [];
        $value = 0.0;
        $count = 0;
        foreach ($quote->getAllVisibleItems() as $item) {
            $type = (string) $item->getProductType();
            // Bundles have their own price/quantity ownership; never send a partial cart.
            if (!in_array($type, ['simple', 'configurable', 'virtual', 'downloadable'], true)) {
                throw new UnexpectedValueException('unsupported_product_type');
            }
            $quantity = (float) $item->getQty();
            if (!is_finite($quantity) || $quantity <= 0 || floor($quantity) !== $quantity
                || $quantity > 2147483647) {
                throw new UnexpectedValueException('unsupported_quantity');
            }
            $productId = (string) $item->getProductId();
            $price = $item->getPriceInclTax();
            $total = $item->getRowTotalInclTax();
            if ($productId === '' || !is_numeric($price) || !is_numeric($total)
                || !is_finite((float) $price) || !is_finite((float) $total)
                || $price < 0 || $total < 0) {
                throw new UnexpectedValueException('invalid_item');
            }
            $row = [
                'item_id' => (string) $item->getId(),
                'product_id' => $productId,
                'sku' => (string) $item->getProduct()->getData('sku'),
                'quantity' => (int) $quantity,
                'price' => (float) $price,
                'total_amount' => (float) $total
            ];
            if ($type === 'configurable') {
                $option = $item->getOptionByCode('simple_product');
                $variant = $option ? $option->getProduct() : null;
                if (!$variant || !$variant->getId()) {
                    throw new UnexpectedValueException('missing_variant');
                }
                $row['variant_id'] = (string) $variant->getId();
                $row['variant_sku'] = (string) $variant->getData('sku');
            }
            $items[] = $row;
            $value += (float) $total;
            $count += (int) $quantity;
        }
        if ($count > 2147483647 || !is_finite($value)) {
            throw new UnexpectedValueException('invalid_cart_total');
        }
        $currency = (string) $quote->getQuoteCurrencyCode();
        if ($items && (!$quote->getId() || !preg_match('/^[A-Z]{3}$/D', $currency))) {
            throw new UnexpectedValueException('incomplete_cart');
        }
        return [
            'cart_id' => $quote->getId() ? (string) $quote->getId() : null,
            'currency_code' => $currency,
            'cart_value' => round($value, 4),
            'cart_item_count' => $count,
            'promo' => (string) $quote->getCouponCode(),
            'items' => $items
        ];
    }
}
