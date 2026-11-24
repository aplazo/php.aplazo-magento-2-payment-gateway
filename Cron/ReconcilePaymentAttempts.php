<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\PaymentFirst\Reconciler;

/**
 * Popup payment-first reconciliation (BNPL-1664): finalizes paid attempts nobody finalized, resumes interrupted
 * approvals and handles rejected attempts. It only reads attempt rows whose check is due.
 *
 * When no store has popup payment-first on (configuration only), it returns without any query. Attempts left from when
 * it was on are then reconciled only by `bin/magento aplazo:payment-first:attempts`, which is not gated.
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
        if (!$this->aplazoHelper->isPaymentFirstEnabledInAnyStore()) {
            return;
        }
        $this->reconciler->run();
    }
}
