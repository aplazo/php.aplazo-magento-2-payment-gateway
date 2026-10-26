<?php

namespace Aplazo\AplazoPayment\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Aplazo orders placed in the buyer's own checkout session (guest or registered).
 *
 * In popup mode, purchase and popup_return only act on an order the browser names when it is in this list, so an order
 * id in the URL is never trusted on its own, and two orders placed in two tabs of the same session each open their own
 * checkout.
 */
class PlacedOrders
{
    private const SESSION_KEY = 'aplazo_placed_order_increment_ids';
    private const LIMIT = 10;

    private CheckoutSession $checkoutSession;

    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    public function remember(OrderInterface $order): void
    {
        $incrementId = (string)$order->getIncrementId();
        if ($incrementId === '') {
            return;
        }
        $ids = array_values(array_diff($this->getIncrementIds(), [$incrementId]));
        $ids[] = $incrementId;
        $this->checkoutSession->setData(self::SESSION_KEY, array_slice($ids, -self::LIMIT));
    }

    public function contains(OrderInterface $order): bool
    {
        $incrementId = (string)$order->getIncrementId();

        return $incrementId !== '' && in_array($incrementId, $this->getIncrementIds(), true);
    }

    private function getIncrementIds(): array
    {
        $ids = $this->checkoutSession->getData(self::SESSION_KEY);

        return is_array($ids) ? array_map('strval', $ids) : [];
    }
}
