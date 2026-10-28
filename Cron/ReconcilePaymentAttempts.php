<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Model\PaymentFirst\Reconciler;

/**
 * Popup payment-first reconciliation (BNPL-1664): finalizes paid attempts nobody finalized, resumes interrupted
 * approvals and handles rejected attempts. It only reads attempt rows whose check is due.
 */
class ReconcilePaymentAttempts
{
    public function __construct(private Reconciler $reconciler)
    {
    }

    public function execute(): void
    {
        $this->reconciler->run();
    }
}
