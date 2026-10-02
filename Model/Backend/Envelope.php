<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model\Backend;

use Magento\Sales\Model\Order;

class Envelope
{
    public static function eventId(string $integrationId, string $siteId, string $orderId): string
    {
        return hash('sha256', $integrationId . "\n" . $siteId . "\n" . $orderId);
    }

    public function build(Order $order, array $config): array
    {
        $items = [];
        foreach ($order->getAllItems() as $item) {
            $items[] = [
                'item_id' => (string) $item->getId(),
                'parent_item_id' => $item->getParentItemId() ? (string) $item->getParentItemId() : null,
                'product_id' => (string) $item->getProductId(),
                'product_type' => (string) $item->getProductType(),
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'qty_ordered' => (string) $item->getQtyOrdered(),
                'price_incl_tax' => (string) ($item->getPriceInclTax() ?? $item->getPrice()),
                'row_total_incl_tax' => (string) ($item->getRowTotalInclTax() ?? $item->getRowTotal()),
                'discount_amount' => (string) ($item->getDiscountAmount() ?? '0'),
            ];
        }
        $date = new \DateTimeImmutable((string) $order->getCreatedAt(), new \DateTimeZone('UTC'));
        return [
            'version' => 1, 'type' => 'order.created',
            'event_id' => self::eventId($config['integration_id'], $config['site_id'], (string) $order->getId()),
            'integration_id' => $config['integration_id'], 'site_id' => $config['site_id'],
            'order' => [
                'entity_id' => (string) $order->getId(), 'increment_id' => (string) $order->getIncrementId(),
                'quote_id' => $order->getQuoteId() ? (string) $order->getQuoteId() : null,
                'created_at' => $date->format('Y-m-d\TH:i:s\Z'), 'store_id' => (string) $order->getStoreId(),
                'customer_id' => $order->getCustomerId() ? (string) $order->getCustomerId() : null,
                'customer_email' => $order->getCustomerEmail() ?: null,
                'customer_is_guest' => (bool) $order->getCustomerIsGuest(),
                'order_currency_code' => (string) $order->getOrderCurrencyCode(),
                'subtotal' => (string) $order->getSubtotal(), 'grand_total' => (string) $order->getGrandTotal(),
                'tax_amount' => (string) ($order->getTaxAmount() ?? '0'),
                'shipping_amount' => (string) ($order->getShippingAmount() ?? '0'),
                'discount_amount' => (string) ($order->getDiscountAmount() ?? '0'),
                'coupon_code' => $order->getCouponCode() ?: null, 'items' => $items,
            ],
        ];
    }
}
