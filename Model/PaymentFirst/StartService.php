<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Service\ApiService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\CartMutexInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\SubmitQuoteValidator;

/**
 * Starts a popup payment-first attempt for the session's quote (BNPL-1664). No order is created and no stock moves.
 *
 * It runs under the core cart lock (CartMutex, the one around placeOrder), so a double click or a redirect order being
 * placed for the same quote is answered "busy" instead of running alongside. The attempt row is committed before
 * createLoan, so a lost answer or an early webhook can still be matched by its cartId.
 *
 * Results: open (url to load in the window) · use_redirect (popup not effective now; no loan was requested) ·
 * invalid (the cart is not eligible; no loan was requested) · busy · uncertain (createLoan did not give a usable
 * answer: a loan may exist, and the attempt stays under reconciliation).
 */
class StartService
{
    public const OPEN = 'open';
    public const USE_REDIRECT = 'use_redirect';
    public const INVALID = 'invalid';
    public const BUSY = 'busy';
    public const UNCERTAIN = 'uncertain';

    private const PAYMENT_CHECKS = [
        AbstractMethod::CHECK_USE_CHECKOUT,
        AbstractMethod::CHECK_USE_FOR_COUNTRY,
        AbstractMethod::CHECK_USE_FOR_CURRENCY,
        AbstractMethod::CHECK_ORDER_TOTAL_MIN_MAX,
        AbstractMethod::CHECK_ZERO_TOTAL,
    ];
    /** Aplazo's minimum loan amount, in minor units (loan-ms: totalPrice minimum 1.00). */
    private const MIN_AMOUNT = 100;

    public function __construct(
        private CheckoutSession $checkoutSession,
        private CustomerSession $customerSession,
        private CartMutexInterface $cartMutex,
        private SubmitQuoteValidator $submitQuoteValidator,
        private AttemptStore $attemptStore,
        private SessionAttempts $sessionAttempts,
        private QuoteFingerprint $fingerprint,
        private QuoteLoanPayloadBuilder $payloadBuilder,
        private CheckoutUrlValidator $urlValidator,
        private ApiService $apiService,
        private AplazoHelper $aplazoHelper,
        private ResourceConnection $resource,
        private RemoteAddress $remoteAddress,
        private ReconcileSchedule $schedule,
        private AttemptContext $context,
        private Trace $trace
    ) {
    }

    public function start(): array
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getId() || !$quote->getIsActive() || !$quote->hasItems()) {
            return $this->result(self::INVALID, __('Your cart is no longer available. Please reload the page.'));
        }
        if ($this->aplazoHelper->getCheckoutMode((int)$quote->getStoreId()) !== CheckoutMode::MODE_POPUP) {
            return $this->result(self::USE_REDIRECT);
        }
        try {
            return $this->cartMutex->execute((int)$quote->getId(), function () use ($quote) {
                return $this->startLocked($quote);
            });
        } catch (CartLockedException $e) {
            return $this->result(self::BUSY, __('Your order is already being processed. Please wait a moment.'));
        } finally {
            $this->trace->flush();
        }
    }

    private function startLocked(Quote $quote): array
    {
        $customerId = (int)$this->customerSession->getCustomerId();
        if ((int)$quote->getCustomerId() !== $customerId || $quote->getPayment()->getMethod() !== ConfigProvider::CODE) {
            return $this->result(self::INVALID, __('We could not start the payment for this cart. Please reload the page.'));
        }
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        try {
            $this->submitQuoteValidator->validateQuote($quote);
            $quote->getPayment()->importData(['method' => ConfigProvider::CODE, 'checks' => self::PAYMENT_CHECKS]);
        } catch (LocalizedException $e) {
            return $this->result(self::INVALID, __($e->getMessage()));
        }
        [$amount, $currency] = $this->fingerprint->amount($quote);
        if ($quote->getHasError() || !$quote->validateMinimumAmount()
            || $currency !== AplazoHelper::APLAZO_CURRENCY || $amount < self::MIN_AMOUNT
        ) {
            return $this->result(self::INVALID, __('This cart cannot be paid with Aplazo. Please review it.'));
        }
        $fingerprint = $this->fingerprint->compute($quote);

        // An attempt for exactly this checkout data is reused, so a second click, another tab or reopening the window
        // keeps one loan. Reuse depends only on that identity: there is no time window, because how long Aplazo's
        // checkout stays valid is not known (contract C3).
        foreach ($this->attemptStore->getForQuote((int)$quote->getId(), [AttemptStore::OPEN]) as $open) {
            if ($open['fingerprint'] === $fingerprint && !empty($open['checkout_url']) && $this->context->mismatch($open) === null) {
                $this->sessionAttempts->remember($open);

                return $this->result(self::OPEN, null, $open);
            }
        }
        // Different data: the earlier attempts are superseded, never cancelled here. Their loans may still be paid, and
        // the reconciliation keeps checking them; one paid late ends rejected (Finalizer).
        foreach ($this->attemptStore->getForQuote((int)$quote->getId(), AttemptStore::PAYABLE) as $previous) {
            if ($previous['status'] !== AttemptStore::SUPERSEDED) {
                $this->attemptStore->transition($previous, [
                    'status' => AttemptStore::SUPERSEDED,
                    'next_check_at' => $this->schedule->now(),
                ]);
            }
        }
        $this->saveBuyerAddress($quote);
        $attempt = $this->attemptStore->create([
            'quote_id' => (int)$quote->getId(),
            'store_id' => (int)$quote->getStoreId(),
            // The environment and merchant this loan is requested with; later calls about it must use the same.
            'environment' => $this->context->current((int)$quote->getStoreId())['environment'],
            'merchant_id' => $this->context->current((int)$quote->getStoreId())['merchant_id'],
            'customer_id' => $customerId ?: null,
            'amount' => $amount / 100,
            'currency' => $currency,
            'fingerprint' => $fingerprint,
            'summary_json' => json_encode($this->fingerprint->summary($quote), JSON_UNESCAPED_UNICODE),
            // If this request dies during createLoan, the reconciliation picks the attempt up after this delay.
            'next_check_at' => $this->schedule->afterStart(),
        ]);
        $this->sessionAttempts->remember($attempt);

        $result = null;
        try {
            $result = $this->apiService->createLoan(
                $this->payloadBuilder->build($quote, $attempt),
                $this->apiService->getAuthorizationToken()
            );
        } catch (\Throwable $e) {
            $error = 'create_loan_failed';
        }
        $url = is_array($result) && isset($result['url'])
            ? $this->urlValidator->popupUrl((string)$result['url'], $this->aplazoHelper->getCheckoutOrigin((int)$quote->getStoreId()))
            : null;
        $loanId = is_array($result) && isset($result['loanId']) ? substr((string)$result['loanId'], 0, 64) : null;
        if ($url === null) {
            $this->trace->add('error', 'Payment-first createLoan gave no usable checkout URL', ['module:checkout'],
                ['cart_id' => $attempt['cart_id'], 'reason' => $error ?? 'invalid_checkout_url']);
            $changes = ['status' => AttemptStore::UNCERTAIN, 'last_error' => $error ?? 'invalid_checkout_url',
                'next_check_at' => $this->schedule->now()];
            if ($loanId !== null) {
                $changes['loan_id'] = $loanId;
            }
            $this->attemptStore->transition($attempt, $changes);

            return $this->result(self::UNCERTAIN,
                __('We could not confirm that Aplazo opened. Please try again; nothing was charged in this step.'));
        }
        $changes = ['status' => AttemptStore::OPEN, 'checkout_url' => $url, 'next_check_at' => $this->schedule->now()];
        if ($loanId !== null) {
            $changes['loan_id'] = $loanId;
        }
        // The webhook can arrive before this write and set the loan id: the compare-and-set then fails, and it is
        // applied once more on the current row if that row is still starting.
        $open = $this->attemptStore->transition($attempt, $changes);
        if ($open === null) {
            $current = $this->attemptStore->get((int)$attempt['entity_id']);
            $open = $current && $current['status'] === AttemptStore::STARTING
                ? $this->attemptStore->transition($current, $changes)
                : $current;
        }
        $this->trace->add('info', 'Payment-first attempt opened', ['module:checkout'],
            ['cart_id' => $attempt['cart_id'], 'quote_id' => (int)$quote->getId()]);

        return $open && !empty($open['checkout_url'])
            ? $this->result(self::OPEN, null, $open)
            : $this->result(self::UNCERTAIN, __('We could not confirm that Aplazo opened. Please try again.'));
    }

    /**
     * The order keeps the buyer's IP address, as placeOrderRun stores it, even when the webhook or the reconciliation
     * creates it later. The quote table has no X-Forwarded-For column, so that header is not kept for these orders.
     */
    private function saveBuyerAddress(Quote $quote): void
    {
        $remoteIp = $this->remoteAddress->getRemoteAddress();
        if ($remoteIp === false) {
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName('quote'),
            ['remote_ip' => $remoteIp],
            ['entity_id = ?' => (int)$quote->getId()]
        );
    }

    private function result(string $status, $message = null, ?array $attempt = null): array
    {
        $result = ['status' => $status];
        if ($message !== null) {
            $result['message'] = (string)$message;
        }
        if ($attempt !== null) {
            $result['attempt'] = $attempt['public_id'];
            $result['url'] = $attempt['checkout_url'];
        }

        return $result;
    }
}
