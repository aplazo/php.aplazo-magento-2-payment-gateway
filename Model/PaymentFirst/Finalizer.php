<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Service\ApiService;
use Aplazo\AplazoPayment\Service\LogService;
use Aplazo\AplazoPayment\Service\TrackingService;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\CartMutexInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Turns a paid payment-first attempt into its order (BNPL-1664). The browser's `complete`, the webhook and the
 * reconciliation all call finalize(); none of their signals is taken as proof of payment.
 *
 *  1. The payment is confirmed with Aplazo's status API (LoanStatusChecker), outside any lock. Only PAID is acted on,
 *     and a PAID answer stays true: the only thing that can undo it is the loan reversal, which runs only on rejected
 *     attempts under the same attempt lock. Everything is decided again on rows reloaded inside the locks.
 *  2. Locks, always in this order: the attempt (`aplazo_pf_attempt_<cartId>`, Magento's lock manager, shared by all
 *     nodes on the default DB backend), then the core cart lock (CartMutex `cart_lock_<quoteId>`, the one around
 *     placeOrder). The core never takes the first one, so the order cannot invert. A held lock is "busy", never a
 *     rejection: the caller retries and the reconciliation catches up.
 *  3. The order is looked up first by increment_id = cartId and store (a process that died after saving it), and only
 *     adopted if it is this attempt's (same quote and mark).
 *  4. Otherwise the quote is converted inside one DB transaction that first locks the quote, its items, addresses and
 *     payment rows (SELECT ... FOR UPDATE). The quote is then loaded, recalculated, compared with the attempt
 *     (fingerprint, amount, currency), checked (validators, payment method, stock) and submitted, all inside that
 *     transaction. A normal cart change from another tab writes those rows (an item insert also checks the quote row
 *     through its foreign key), so it waits until the order is committed or rolled back: the data that is compared is
 *     exactly the data converted. A change that arrives after the commit meets an inactive quote, as after any core
 *     placeOrder.
 *  5. Approval (invoice, status) is resumable: the attempt is order_created until it is done.
 *
 * Results: completed · not_paid · unknown · busy · rejected · needs_review.
 */
class Finalizer
{
    public const COMPLETED = 'completed';
    public const NOT_PAID = 'not_paid';
    public const UNKNOWN = 'unknown';
    public const BUSY = 'busy';
    public const REJECTED = 'rejected';
    public const NEEDS_REVIEW = 'needs_review';

    public const SOURCE_BROWSER = 'browser';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_RECONCILE = 'reconcile';

    /** Events for observers (and the local test kit's synchronization points). */
    public const EVENT_CONVERT_BEFORE = 'aplazo_payment_first_convert_before';
    public const EVENT_ORDER_SAVED = 'aplazo_payment_first_order_saved';
    public const EVENT_APPROVE_BEFORE = 'aplazo_payment_first_approve_before';

    private const PAYMENT_CHECKS = [
        AbstractMethod::CHECK_USE_CHECKOUT,
        AbstractMethod::CHECK_USE_FOR_COUNTRY,
        AbstractMethod::CHECK_USE_FOR_CURRENCY,
        AbstractMethod::CHECK_ORDER_TOTAL_MIN_MAX,
        AbstractMethod::CHECK_ZERO_TOTAL,
    ];

    public function __construct(
        private AttemptStore $attemptStore,
        private LoanStatusChecker $statusChecker,
        private LockManagerInterface $lockManager,
        private CartMutexInterface $cartMutex,
        private ResourceConnection $resource,
        private QuoteFactory $quoteFactory,
        private QuoteManagement $quoteManagement,
        private SubmitQuoteValidator $submitQuoteValidator,
        private QuoteFingerprint $fingerprint,
        private StockCheck $stockCheck,
        private StoreScope $storeScope,
        private OrderRepositoryInterface $orderRepository,
        private OrderService $orderService,
        private OrderSender $orderSender,
        private AplazoHelper $aplazoHelper,
        private EventManager $eventManager,
        private TrackingService $trackingService,
        private LogService $logService,
        private ReconcileSchedule $schedule,
        private AttemptContext $context
    ) {
    }

    /**
     * @param int $lockWait seconds to wait for the attempt lock (0 for the browser)
     * @return array{status: string, attempt: array, order_id?: int}
     */
    public function finalize(array $attempt, string $source, int $lockWait = 0): array
    {
        if (($done = $this->settled($attempt)) !== null) {
            return $done;
        }
        if ($attempt['status'] !== AttemptStore::ORDER_CREATED) {
            $payment = $this->statusChecker->check($attempt);
            if ($payment === LoanStatusChecker::CONTEXT_CHANGED) {
                return ['status' => self::UNKNOWN, 'attempt' => $this->recordContextChange($attempt)];
            }
            if ($payment !== LoanStatusChecker::PAID) {
                $this->scheduleAfterCheck($attempt, $source);

                return ['status' => $payment === LoanStatusChecker::NOT_PAID ? self::NOT_PAID : self::UNKNOWN, 'attempt' => $attempt];
            }
            // Kept even if the conversion fails later, so a paid attempt without order is never silent (H-3).
            if (empty($attempt['paid_confirmed_at'])) {
                $attempt = $this->attemptStore->transition($attempt, ['paid_confirmed_at' => $this->schedule->now()])
                    ?? $this->attemptStore->get((int)$attempt['entity_id']) ?? $attempt;
            }
        }
        $lockName = 'aplazo_pf_attempt_' . $attempt['cart_id'];
        if (!$this->lockManager->lock($lockName, $lockWait)) {
            $this->scheduleSoon($attempt);

            return ['status' => self::BUSY, 'attempt' => $attempt];
        }
        try {
            $attempt = $this->attemptStore->get((int)$attempt['entity_id']) ?? $attempt;
            if (($done = $this->settled($attempt)) !== null) {
                return $done;
            }
            $outcome = $this->storeScope->run((int)$attempt['store_id'], function () use ($attempt) {
                return $this->finalizeLocked($attempt);
            });
        } catch (CartLockedException $e) {
            $this->scheduleSoon($attempt);

            return ['status' => self::BUSY, 'attempt' => $attempt];
        } catch (\DomainException $e) {
            // Already moved to needs_review with its reason: not a transient error to retry.
            return ['status' => self::NEEDS_REVIEW, 'attempt' => $this->attemptStore->get((int)$attempt['entity_id']) ?? $attempt];
        } catch (\Throwable $e) {
            return ['status' => self::UNKNOWN, 'attempt' => $this->recordTransient($attempt, $e)];
        } finally {
            $this->lockManager->unlock($lockName);
        }
        if ($outcome['status'] === self::COMPLETED) {
            $outcome['attempt'] = $this->requestEmail($outcome['attempt']);
        }

        return $outcome;
    }

    private function finalizeLocked(array $attempt): array
    {
        $orderId = $attempt['order_id'] ? (int)$attempt['order_id'] : $this->findOwnOrder($attempt);
        if ($orderId === null) {
            if (!in_array($attempt['status'], AttemptStore::PAYABLE, true)) {
                return $this->settled($attempt) ?? ['status' => self::UNKNOWN, 'attempt' => $attempt];
            }
            $conversion = $this->cartMutex->execute((int)$attempt['quote_id'], function () use ($attempt) {
                return $this->convert($attempt);
            });
            if (isset($conversion['reject'])) {
                return $this->reject($attempt, $conversion['reject']);
            }
            $orderId = (int)$conversion['order_id'];
            $this->eventManager->dispatch(self::EVENT_ORDER_SAVED, ['attempt' => $attempt, 'order_id' => $orderId]);
        }
        if ($attempt['status'] !== AttemptStore::ORDER_CREATED || (int)$attempt['order_id'] !== $orderId) {
            $attempt = $this->mustTransition($attempt, [
                'status' => AttemptStore::ORDER_CREATED,
                'order_id' => $orderId,
                'last_error' => null,
                'next_check_at' => $this->schedule->afterCheck(1),
            ]);
        }
        $this->eventManager->dispatch(self::EVENT_APPROVE_BEFORE, ['attempt' => $attempt, 'order_id' => $orderId]);
        $this->approve($attempt, $orderId);
        $attempt = $this->mustTransition($attempt, ['status' => AttemptStore::COMPLETED, 'next_check_at' => null]);
        $this->logService->send('info', 'Payment-first order completed', ['module:checkout'],
            ['cart_id' => $attempt['cart_id'], 'order_entity_id' => $orderId]);

        return ['status' => self::COMPLETED, 'attempt' => $attempt, 'order_id' => $orderId];
    }

    /**
     * @return array{order_id: int}|array{reject: string}
     */
    private function convert(array $attempt): array
    {
        $connection = $this->resource->getConnection();
        $quoteId = (int)$attempt['quote_id'];
        $connection->beginTransaction();
        try {
            $active = $connection->fetchOne(
                'SELECT is_active FROM ' . $this->resource->getTableName('quote') . ' WHERE entity_id = ? FOR UPDATE',
                [$quoteId]
            );
            foreach (['quote_item', 'quote_address', 'quote_payment'] as $table) {
                $connection->fetchCol(
                    'SELECT 1 FROM ' . $this->resource->getTableName($table) . ' WHERE quote_id = ? FOR UPDATE',
                    [$quoteId]
                );
            }
            if ($active === false || (int)$active !== 1) {
                $connection->rollBack();

                return ['reject' => 'quote_not_active'];
            }
            /** @var Quote $quote */
            $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
            $reject = $this->rejectReason($quote, $attempt);
            if ($reject !== null) {
                $connection->rollBack();

                return ['reject' => $reject];
            }
            $this->prepareCustomer($quote);
            $quote->setReservedOrderId($attempt['cart_id']);
            PaymentFirstMarker::mark($quote->getPayment(), $attempt);
            $this->eventManager->dispatch('checkout_submit_before', ['quote' => $quote]);
            $this->eventManager->dispatch(self::EVENT_CONVERT_BEFORE, ['attempt' => $attempt, 'quote' => $quote]);
            $order = $this->quoteManagement->submit($quote);
            if (!$order || (string)$order->getIncrementId() !== $attempt['cart_id']) {
                throw new \RuntimeException('submit did not create the attempt order');
            }
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->getTransactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $e;
        }
        $this->eventManager->dispatch('checkout_submit_all_after', ['order' => $order, 'quote' => $quote]);

        return ['order_id' => (int)$order->getId()];
    }

    /**
     * Definitive reasons not to convert, checked on the locked quote. Anything else that fails later (submit) is
     * treated as transient and retried, never as a rejection.
     */
    private function rejectReason(Quote $quote, array $attempt): ?string
    {
        if (!$quote->getId() || (int)$quote->getStoreId() !== (int)$attempt['store_id']
            || (int)$quote->getCustomerId() !== (int)$attempt['customer_id']
            || $quote->getPayment()->getMethod() !== ConfigProvider::CODE
        ) {
            return 'quote_mismatch';
        }
        try {
            $quote->getPayment()->importData(['method' => ConfigProvider::CODE, 'checks' => self::PAYMENT_CHECKS]);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->submitQuoteValidator->validateQuote($quote);
        } catch (LocalizedException $e) {
            // A missing unit makes the validators fail too; name it as such.
            return $this->stockCheck->isSalable($quote) ? 'not_eligible' : 'out_of_stock';
        }
        if ($this->fingerprint->compute($quote) !== $attempt['fingerprint']) {
            return 'cart_changed';
        }
        [$amount, $currency] = $this->fingerprint->amount($quote);
        if ($amount !== QuoteFingerprint::toMinorUnits($attempt['amount']) || $currency !== $attempt['currency']) {
            return 'amount_changed';
        }
        if ($quote->getHasError() || !$this->stockCheck->isSalable($quote)) {
            return 'out_of_stock';
        }

        return null;
    }

    /**
     * Guest or customer as QuoteManagement::placeOrderRun prepares them, without the browser session: the quote
     * already carries the customer and group. PayPal's ignoreAddressValidation() is not copied: addresses are validated.
     */
    private function prepareCustomer(Quote $quote): void
    {
        if ($quote->getCheckoutMethod() !== QuoteManagement::METHOD_GUEST && $quote->getCustomerId()) {
            return;
        }
        $billing = $quote->getBillingAddress();
        $quote->setCustomerId(null);
        if (!$quote->getCustomerEmail()) {
            $quote->setCustomerEmail($billing ? $billing->getEmail() : null);
        }
        if ($quote->getCustomerFirstname() === null && $quote->getCustomerLastname() === null && $billing) {
            $quote->setCustomerFirstname($billing->getFirstname());
            $quote->setCustomerLastname($billing->getLastname());
            if ($billing->getMiddlename() !== null) {
                $quote->setCustomerMiddlename($billing->getMiddlename());
            }
        }
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
    }

    /**
     * Invoice and approval with Magento's invoice service (OrderService::invoiceOrder), once: an existing invoice is
     * not repeated, and the status comment and tracking are written only on the change to processing.
     */
    private function approve(array $attempt, int $orderId): void
    {
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);
        $approvedStatus = $this->aplazoHelper->getApprovedOrderStatus();
        // An order someone already moved on (cancelled, closed, completed) is left as it is.
        if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PROCESSING], true)) {
            return;
        }
        if (!$order->hasInvoices()) {
            if (!$order->canInvoice() || !$this->orderService->invoiceOrder($order)) {
                throw new \RuntimeException('invoice_failed');
            }
            $order = $this->orderRepository->get($orderId);
        }
        if ($order->getState() !== Order::STATE_PROCESSING || $order->getStatus() !== $approvedStatus) {
            $order->setState(Order::STATE_PROCESSING)->setStatus($approvedStatus);
            $order->getPayment()->setAdditionalInformation('aplazo_status', ApiService::LOAN_SUCCESS_STATUS);
            $order->addCommentToStatusHistory(__('Aplazo: payment confirmed through the loan status API before the order was created (popup).'));
            $this->orderRepository->save($order);
            try {
                $this->trackingService->trackOrderPaid($order, (string)$attempt['loan_id'], ApiService::LOAN_SUCCESS_STATUS);
            } catch (\Throwable $e) {
                // Never block the approval because of tracking.
            }
        }
    }

    /**
     * The order email is handed to Magento's OrderSender once per attempt. With async sending enabled Magento's own
     * cron (sales_send_order_emails) retries the delivery; with sync sending a failure stays logged and is not retried
     * here. Delivery exactly once is not guaranteed, and a failure never touches the order or the loan.
     */
    private function requestEmail(array $attempt): array
    {
        if (!empty($attempt['email_requested_at']) || empty($attempt['order_id'])) {
            return $attempt;
        }
        $claimed = $this->attemptStore->transition($attempt, ['email_requested_at' => $this->schedule->now()]);
        if ($claimed === null) {
            return $this->attemptStore->get((int)$attempt['entity_id']) ?? $attempt;
        }
        try {
            $order = $this->orderRepository->get((int)$attempt['order_id']);
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
        } catch (\Throwable $e) {
            $this->logService->send('error', 'Payment-first order email failed', ['module:checkout'],
                ['cart_id' => $attempt['cart_id'], 'error' => get_class($e)]);
        }

        return $claimed;
    }

    private function reject(array $attempt, string $reason): array
    {
        $rejected = $this->mustTransition($attempt, [
            'status' => AttemptStore::REJECTED,
            'reject_reason' => $reason,
            'loan_cancel_state' => AttemptStore::CANCEL_PENDING,
            'next_check_at' => $this->schedule->now(),
        ]);
        $this->logService->send('error', 'Payment-first attempt paid but not convertible', ['module:checkout'],
            ['cart_id' => $attempt['cart_id'], 'reason' => $reason]);

        return ['status' => self::REJECTED, 'attempt' => $rejected];
    }

    /**
     * An order with this cartId: adopted only if it is in the attempt's store, was created from this attempt's quote and
     * carries its mark. Any other order with the number, in any store, is a conflict an operator has to look at:
     * Magento's reserveOrderId() would not give this order the number either (it checks every store), and the cartId
     * must stay the order's increment for refunds and cancellation (H-9).
     */
    private function findOwnOrder(array $attempt): ?int
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['o' => $this->resource->getTableName('sales_order')], ['entity_id', 'quote_id', 'store_id'])
                ->join(['p' => $this->resource->getTableName('sales_order_payment')], 'p.parent_id = o.entity_id', ['additional_information'])
                ->where('o.increment_id = ?', $attempt['cart_id'])
        );
        if (!$row) {
            return null;
        }
        $info = json_decode((string)$row['additional_information'], true) ?: [];
        if ((int)$row['store_id'] === (int)$attempt['store_id'] && (int)$row['quote_id'] === (int)$attempt['quote_id']
            && ($info[PaymentFirstMarker::ATTEMPT_KEY] ?? null) === $attempt['public_id']
        ) {
            return (int)$row['entity_id'];
        }
        $this->attemptStore->transition($attempt, ['status' => AttemptStore::NEEDS_REVIEW, 'last_error' => 'cart_id_used_by_other_order']);
        throw new \DomainException('cart_id_used_by_other_order');
    }

    private function settled(array $attempt): ?array
    {
        switch ($attempt['status']) {
            case AttemptStore::COMPLETED:
                return ['status' => self::COMPLETED, 'attempt' => $attempt, 'order_id' => (int)$attempt['order_id']];
            case AttemptStore::REJECTED:
                return ['status' => self::REJECTED, 'attempt' => $attempt];
            case AttemptStore::NEEDS_REVIEW:
                return ['status' => self::NEEDS_REVIEW, 'attempt' => $attempt];
        }

        return null;
    }

    private function mustTransition(array $attempt, array $changes): array
    {
        $updated = $this->attemptStore->transition($attempt, $changes);
        if ($updated === null) {
            // Under the attempt lock only bookkeeping writes (schedule, loan id) can race; apply on the current row.
            $current = $this->attemptStore->get((int)$attempt['entity_id']);
            $updated = $current && $current['status'] === $attempt['status'] ? $this->attemptStore->transition($current, $changes) : null;
        }
        if ($updated === null) {
            throw new \RuntimeException('attempt_changed_concurrently');
        }

        return $updated;
    }

    /**
     * Only the reconciliation advances the schedule on its own checks; a signal (browser, webhook) asks for a prompt
     * check instead. A failed compare-and-set means someone else already changed the row: nothing to do.
     */
    private function scheduleAfterCheck(array $attempt, string $source): void
    {
        if ($source === self::SOURCE_RECONCILE) {
            $count = (int)$attempt['check_count'] + 1;
            $this->attemptStore->transition($attempt, ['check_count' => $count, 'next_check_at' => $this->schedule->afterCheck($count)]);
        } elseif ($source === self::SOURCE_WEBHOOK) {
            $this->scheduleSoon($attempt);
        }
    }

    private function scheduleSoon(array $attempt): void
    {
        $this->attemptStore->transition($attempt, ['next_check_at' => $this->schedule->now()]);
    }

    private function recordContextChange(array $attempt): array
    {
        $what = $this->context->mismatch($attempt) ?? 'unknown';
        $count = (int)$attempt['check_count'] + 1;

        return $this->attemptStore->transition($attempt, [
            'check_count' => $count,
            'last_error' => 'context_changed:' . $what,
            'next_check_at' => $this->schedule->afterCheck($count),
        ]) ?? $attempt;
    }

    private function recordTransient(array $attempt, \Throwable $e): array
    {
        $current = $this->attemptStore->get((int)$attempt['entity_id']) ?? $attempt;
        $count = (int)$current['check_count'] + 1;
        $error = substr(get_class($e) . ($e instanceof \RuntimeException ? ': ' . $e->getMessage() : ''), 0, 255);
        $this->logService->send('error', 'Payment-first finalization failed, will retry', ['module:checkout'],
            ['cart_id' => $attempt['cart_id'], 'error' => $error]);

        return $this->attemptStore->transition($current, [
            'check_count' => $count,
            'last_error' => $error,
            'next_check_at' => $this->schedule->afterCheck($count),
        ]) ?? $current;
    }
}
