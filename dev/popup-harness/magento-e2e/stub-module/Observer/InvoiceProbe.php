<?php

namespace LocalDev\AplazoStub\Observer;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * LOCAL TEST ONLY (BNPL-1664, approval). Records the order's state/status right after an invoice is saved
 * (sales_order_invoice_save_after, still inside the approval transaction): what Finalizer::approveLocked() sees when it
 * reloads the order after invoicing.
 */
class InvoiceProbe implements ObserverInterface
{
    public function execute(Observer $observer)
    {
        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice ? $invoice->getOrder() : null;
        if ($order) {
            StubLog::write('probe', 'invoice_saved', ['order_id' => (string)$order->getIncrementId(),
                'state' => (string)$order->getState(), 'status' => (string)$order->getStatus(),
                'is_in_process' => (bool)$order->getIsInProcess()]);
        }
    }
}
