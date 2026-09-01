<?php
namespace Rejoiner\Acr\Helper;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;

class Conversion extends AbstractHelper
{
    /**
     * @var Order|null
     */
    private ?Order $order = null;

    /**
     * Conversion constructor.
     * @param Data $rejoinerHelper
     * @param Session $checkoutSession
     * @param OrderFactory $orderFactory
     * @param ItemsData $itemsData
     * @param Context $context
     */
    public function __construct(
        private Data         $rejoinerHelper,
        private Session      $checkoutSession,
        private OrderFactory $orderFactory,
        private ItemsData    $itemsData,
        Context      $context
    ) {
        parent::__construct($context);
    }

    /**
     * @return bool
     */
    public function shouldSaveConversionData(): bool
    {
        $order = $this->getOrder();

        return $order->getId()
            && null === $this->checkoutSession->getQuoteId()
            && $order->getQuoteId() == $this->checkoutSession->getLastQuoteId();
    }

    /**
     * @return array
     */
    public function getCartData(): array
    {
        $result = [];
        $displayPriceWithTax = $this->rejoinerHelper->getTrackPriceWithTax();
        $order = $this->getOrder();

        if ($order->getId()) {
            $total = $displayPriceWithTax? $order->getGrandTotal() : $order->getSubtotal();
            $result = [
                'cart_id' => (string) $order->getQuoteId(),
                'cart_value' => $this->rejoinerHelper->convertPriceToCents($total),
                'cart_item_count' => intval($order->getTotalQtyOrdered()),
                'queen_one_total_price' => $this->rejoinerHelper->convertPriceToCents($order->getGrandTotal()),
                'queen_one_subtotal_price' => $this->rejoinerHelper->convertPriceToCents($order->getSubtotal()),
                'currency_code' => (string) $order->getOrderCurrencyCode(),
                'customer_order_number' => $order->getIncrementId(),
                'return_url' => $this->_urlBuilder->getUrl('sales/order/view/', ['order_id' => $order->getIncrementId()])
            ];

            $promo = $order->getCouponCode();

            if ($promo) {
                $result['promo'] = $promo;
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getCartItems(): array
    {
        return $this->itemsData->getCartItems($this->getOrder());
    }

    /**
     * @return Order
     */
    private function getOrder(): Order
    {
        if ($this->order === null) {
            /** @var Order $order */
            $this->order = $this->orderFactory->create();
            $this->order->loadByIncrementId($this->checkoutSession->getLastRealOrderId());
        }

        return $this->order;
    }
}
