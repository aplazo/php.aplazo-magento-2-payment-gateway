<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Observer\PaymentFirst;

use Aplazo\AplazoPayment\Model\PaymentFirst\PaymentFirstMarker;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * A payment-first order is not saved as cancelled over an approval that happened meanwhile (BNPL-1664, finding H-8).
 *
 * Magento's own cancellation (OrderManagement::cancel, the admin button) runs under the order mutex, as the finalizer's
 * approval does, so the two never interleave. Code that loads an order, cancels it in memory and saves it without that
 * mutex could still write "canceled" over an order the finalizer approved and invoiced in between. On sales_order_save_before, inside the save's own transaction, a payment-first order changing to
 * canceled has its row read again with FOR UPDATE (waiting for an approval in progress); if the committed order is no
 * longer new or already has an invoice, the save is refused and rolled back. Only payment-first orders, and only that
 * change, are checked; every other order and save is untouched.
 */
class KeepPaidOrderOpen implements ObserverInterface
{
    public function __construct(
        private ResourceConnection $resource,
        private PaymentFirstMarker $marker
    ) {
    }

    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getId() || $order->getState() !== Order::STATE_CANCELED
            || $order->getOrigData('state') === Order::STATE_CANCELED || !$this->marker->isPaymentFirstOrder($order)
        ) {
            return;
        }
        $connection = $this->resource->getConnection();
        $state = $connection->fetchOne(
            'SELECT state FROM ' . $this->resource->getTableName('sales_order') . ' WHERE entity_id = ? FOR UPDATE',
            [(int)$order->getId()]
        );
        $invoices = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->resource->getTableName('sales_invoice') . ' WHERE order_id = ?',
            [(int)$order->getId()]
        );
        if ($state !== Order::STATE_NEW || $invoices > 0) {
            throw new LocalizedException(__('This order was paid and approved while it was being cancelled; it was not cancelled.'));
        }
    }
}
