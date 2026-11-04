<?php

namespace Aplazo\AplazoPayment\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Entity ids of the Aplazo orders placed in the buyer's own checkout session (guest or registered), newest last.
 *
 * Entity ids are unique across stores, unlike increment ids. Only positive integers are stored and compared, strictly.
 * The list lives under its own session key, so a value stored under another key (for example an increment id) is never
 * read as an entity id.
 */
class PlacedOrders
{
    private const SESSION_KEY = 'aplazo_popup_placed_order_entity_ids';
    private const LIMIT = 10;

    private CheckoutSession $checkoutSession;

    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Must run once the order is saved (checkout_submit_all_after), when its entity id exists.
     */
    public function remember(OrderInterface $order): void
    {
        $entityId = self::normalizeId($order->getEntityId());
        if ($entityId === null) {
            return;
        }
        $ids = array_values(array_diff($this->getEntityIds(), [$entityId]));
        $ids[] = $entityId;
        $this->checkoutSession->setData(self::SESSION_KEY, array_slice($ids, -self::LIMIT));
    }

    public function contains(OrderInterface $order): bool
    {
        $entityId = self::normalizeId($order->getEntityId());

        return $entityId !== null && in_array($entityId, $this->getEntityIds(), true);
    }

    /**
     * A positive decimal integer without sign, spaces or leading zeros, as an int; anything else is null.
     */
    public static function normalizeId($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/', $value) !== 1) {
            return null;
        }

        return (int)$value;
    }

    /**
     * @return int[]
     */
    private function getEntityIds(): array
    {
        $ids = $this->checkoutSession->getData(self::SESSION_KEY);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, function ($id) {
            return is_int($id) && $id > 0;
        }));
    }
}
