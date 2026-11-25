<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\PaymentFirst\Reconciler;

/**
 * Popup payment-first reconciliation (BNPL-1664): finalizes paid attempts nobody finalized, resumes interrupted
 * approvals and handles rejected attempts. It only reads attempt rows whose check is due.
 *
 * It runs for every store that may hold attempts: popup on now, or an attempt was ever started there
 * (AplazoHelper::isPaymentFirstInUse, configuration only). Turning popup off stops new attempts, not this. A store that
 * never started one returns here without any query.
 */
class ReconcilePaymentAttempts
{
    public function __construct(
        private Reconciler $reconciler,
        private AplazoHelper $aplazoHelper
    ) {
    }

    public function execute(): void
    {
        if (!$this->aplazoHelper->isPaymentFirstInUseInAnyStore()) {
            return;
        }
        $this->reconciler->run();
    }
}
