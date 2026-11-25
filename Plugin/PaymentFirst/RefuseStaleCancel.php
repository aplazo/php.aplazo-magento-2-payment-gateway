<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Plugin\PaymentFirst;

use Aplazo\AplazoPayment\Model\PaymentFirst\PaymentFirstMarker;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * Order::cancel() on a stale copy of a payment-first order is refused before it cancels anything (BNPL-1664, H-8).
 *
 * cancel() cancels the items first, and MSI writes the "order_canceled" reservation right there, before the order is
 * saved; a refusal at save time (KeepPaidOrderOpen) would come after that reservation. So when the committed order is
 * already approved or invoiced while the copy being cancelled still looks new, nothing is cancelled. Magento's own
 * cancellation runs under the order mutex and always reads the committed order, so it is never refused here. A
 * cancellation without the mutex can still race in the moment between this check and its save; that save is then
 * refused by KeepPaidOrderOpen, and the reservation already written stays: only code that bypasses the mutex can reach it.
 */
class RefuseStaleCancel
{
    public function __construct(
        private ResourceConnection $resource,
        private PaymentFirstMarker $marker
    ) {
    }

    public function beforeCancel(Order $order): void
    {
        if (!$order->getId() || $order->getState() !== Order::STATE_NEW || !$this->marker->isPaymentFirstOrder($order)) {
            return;
        }
        $connection = $this->resource->getConnection();
        $state = $connection->fetchOne(
            'SELECT state FROM ' . $this->resource->getTableName('sales_order') . ' WHERE entity_id = ?',
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
