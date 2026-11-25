<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Service\ApiService;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Local reconciliation of payment-first attempts (BNPL-1664): what finalizes a paid attempt when neither the browser nor
 * the webhook did, and resumes an order whose approval was interrupted. It only reads attempt rows; it never processes
 * orders without an attempt.
 *
 * A rejected attempt (paid, but its quote could not become the order) needs the loan reversed. What cancelLoan does
 * after a payment is not confirmed (contract C5), so the call is off by default ($autoCancelRejected): the attempt stays
 * rejected with loan_cancel_state=pending, visible with `bin/magento aplazo:payment-first:attempts --attention`. The
 * local test kit turns it on against the stub. When it is on, the call is made once: an answer is recorded as
 * "request_answered" (the request was taken, not that the money was returned), and a failure or an interrupted request
 * as "unknown", which is never sent again automatically. What happened to the money is recorded only by an operator
 * (`--resolve`), with the reference Aplazo gave them.
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
        private AttemptContext $context,
        private Trace $trace,
        private EventManager $eventManager,
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
        if ($attempt['loan_cancel_state'] === AttemptStore::CANCEL_REQUESTED) {
            return $this->closeInterrupted($attempt);
        }
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
            if (!$current || $current['status'] !== AttemptStore::REJECTED || !empty($current['resolution'])
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
            // Claimed before the call, with a deadline: a process that dies during the request leaves "requested",
            // which the next run after the deadline closes as unknown instead of sending it again.
            $claimed = $this->attemptStore->transition($current, [
                'loan_cancel_state' => AttemptStore::CANCEL_REQUESTED,
                'next_check_at' => $this->schedule->afterCancelRequest(),
            ]);
            if ($claimed === null) {
                return 'skipped';
            }
            $this->eventManager->dispatch('aplazo_payment_first_cancel_requested', ['attempt' => $claimed]);
            try {
                $this->storeScope->run((int)$claimed['store_id'], function () use ($claimed) {
                    return $this->apiService->cancelLoan([
                        'cartId' => $claimed['cart_id'],
                        'totalAmount' => 0,
                        'reason' => 'Payment-first order could not be created'
                    ]);
                });
                $state = AttemptStore::CANCEL_ANSWERED;
            } catch (\Throwable $e) {
                $state = AttemptStore::CANCEL_UNKNOWN;
            }
            $this->attemptStore->transition($claimed, ['loan_cancel_state' => $state, 'next_check_at' => null,
                'last_error' => $state === AttemptStore::CANCEL_UNKNOWN ? 'cancel_request_failed' : null]);
            $this->trace->add($state === AttemptStore::CANCEL_ANSWERED ? 'info' : 'error',
                'Payment-first rejected attempt: cancel request ' . $state . ' (reversal not verified)', ['module:cancel'],
                ['cart_id' => $claimed['cart_id']]);

            return 'cancel_' . $state;
        } finally {
            $this->lockManager->unlock($lockName);
            $this->trace->flush();
        }
    }

    /**
     * A request claimed before its deadline and never recorded: whether Aplazo received it is not known. It is closed
     * as unknown for an operator, never sent again (a repeated cancellation has unconfirmed effects too, C5).
     */
    private function closeInterrupted(array $attempt): string
    {
        $closed = $this->attemptStore->transition($attempt, [
            'loan_cancel_state' => AttemptStore::CANCEL_UNKNOWN,
            'last_error' => 'cancel_request_interrupted',
            'next_check_at' => null,
        ]);

        return $closed ? 'cancel_unknown_interrupted' : 'skipped';
    }
}
