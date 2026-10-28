<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Service\ApiService;
use Aplazo\AplazoPayment\Service\LogService;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Local reconciliation of payment-first attempts (BNPL-1664): what finalizes a paid attempt when neither the browser nor
 * the webhook did, and resumes an order whose approval was interrupted. It only reads attempt rows; it never processes
 * orders without an attempt.
 *
 * A rejected attempt (paid, but its quote could not become the order) needs the loan reversed. What cancelLoan does
 * after a payment is not confirmed (contract C5), so the call is off by default ($autoCancelRejected): the attempt stays
 * rejected with loan_cancel_state=pending, visible with `bin/magento aplazo:payment-first:attempts`. The local test kit
 * turns it on against the stub. When it is on, the call is made once: an answer is recorded as "accepted" (the
 * request was taken, not that the money was returned) and a failure as "unknown", which is not retried automatically
 * because a repeated cancellation has unconfirmed effects too.
 */
class Reconciler
{
    public function __construct(
        private AttemptStore $attemptStore,
        private Finalizer $finalizer,
        private ApiService $apiService,
        private StoreScope $storeScope,
        private LockManagerInterface $lockManager,
        private ReconcileSchedule $schedule,
        private LogService $logService,
        private AttemptContext $context,
        private bool $autoCancelRejected = false,
        private int $batchSize = 50
    ) {
    }

    /**
     * @param int[]|null $onlyIds limit the run to these attempts (local test runs); null for every due attempt
     * @return array[] one line per attempt: entity_id, cart_id, before, result
     */
    public function run(?array $onlyIds = null): array
    {
        $report = [];
        foreach ($this->attemptStore->getDue($this->batchSize, $onlyIds) as $attempt) {
            $result = $attempt['status'] === AttemptStore::REJECTED
                ? $this->reverse($attempt)
                : $this->finalizer->finalize($attempt, Finalizer::SOURCE_RECONCILE, 10)['status'];
            $report[] = ['entity_id' => (int)$attempt['entity_id'], 'cart_id' => $attempt['cart_id'],
                'before' => $attempt['status'], 'result' => $result];
        }

        return $report;
    }

    private function reverse(array $attempt): string
    {
        if (!$this->autoCancelRejected) {
            $count = (int)$attempt['check_count'] + 1;
            $this->attemptStore->transition($attempt, ['check_count' => $count, 'next_check_at' => $this->schedule->afterCheck($count)]);

            return 'reversal_pending_c5';
        }
        $lockName = 'aplazo_pf_attempt_' . $attempt['cart_id'];
        if (!$this->lockManager->lock($lockName, 10)) {
            return 'busy';
        }
        try {
            $current = $this->attemptStore->get((int)$attempt['entity_id']);
            if (!$current || $current['status'] !== AttemptStore::REJECTED
                || $current['loan_cancel_state'] !== AttemptStore::CANCEL_PENDING
            ) {
                return 'skipped';
            }
            if (($what = $this->context->mismatch($current)) !== null) {
                $this->attemptStore->transition($current, ['last_error' => 'context_changed:' . $what,
                    'next_check_at' => $this->schedule->afterCheck((int)$current['check_count'] + 1),
                    'check_count' => (int)$current['check_count'] + 1]);

                return 'context_changed';
            }
            $claimed = $this->attemptStore->transition($current, ['loan_cancel_state' => AttemptStore::CANCEL_REQUESTED]);
            if ($claimed === null) {
                return 'skipped';
            }
            try {
                $this->storeScope->run((int)$claimed['store_id'], function () use ($claimed) {
                    return $this->apiService->cancelLoan([
                        'cartId' => $claimed['cart_id'],
                        'totalAmount' => 0,
                        'reason' => 'Payment-first order could not be created'
                    ]);
                });
                $state = AttemptStore::CANCEL_ACCEPTED;
            } catch (\Throwable $e) {
                $state = AttemptStore::CANCEL_UNKNOWN;
            }
            $this->attemptStore->transition($claimed, ['loan_cancel_state' => $state, 'next_check_at' => null]);
            $this->logService->send($state === AttemptStore::CANCEL_ACCEPTED ? 'info' : 'error',
                'Payment-first rejected attempt: loan cancel ' . $state, ['module:cancel'], ['cart_id' => $claimed['cart_id']]);

            return 'cancel_' . $state;
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }
}
