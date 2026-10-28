<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Service\LogService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Aplazo's webhook for a payment-first attempt (BNPL-1664), after Notifications has validated its JWT as for every
 * order. The callback is only a signal to run the trusted check: its body status is not signed and the JWT's status
 * claim is not the payment status (dev/payment-first/SECURITY-NOTE-webhook-status.md), so neither is read as payment.
 *
 * The signed claims must match the attempt: merchantId equal to the attempt store's merchant, and loanId equal to the
 * attempt's when it already has one (an attempt still waiting for createLoan's answer takes it from here).
 */
class WebhookHandler
{
    public function __construct(
        private AttemptStore $attemptStore,
        private Finalizer $finalizer,
        private ScopeConfigInterface $scopeConfig,
        private LogService $logService
    ) {
    }

    /**
     * @param array $claims the validated JWT payload
     * @return array{status: bool, message: string}
     */
    public function handle(array $attempt, array $claims): array
    {
        $merchantId = (string)$this->scopeConfig->getValue('payment/aplazo_gateway/merchantid', ScopeInterface::SCOPE_STORE, (int)$attempt['store_id']);
        $claimedMerchant = (string)($claims['merchantId'] ?? $claims['sub'] ?? '');
        $claimedLoan = isset($claims['loanId']) ? (string)$claims['loanId'] : '';
        if ($merchantId === '' || $claimedMerchant !== $merchantId
            || ($claimedLoan !== '' && !empty($attempt['loan_id']) && $claimedLoan !== (string)$attempt['loan_id'])
        ) {
            $this->logService->send('error', 'Payment-first webhook does not match its attempt', ['module:webhook'],
                ['cart_id' => $attempt['cart_id'], 'merchant_matches' => $claimedMerchant === $merchantId]);

            return ['status' => false, 'message' => 'Webhook does not match the payment attempt'];
        }
        if ($claimedLoan !== '' && empty($attempt['loan_id'])) {
            $attempt = $this->attemptStore->transition($attempt, ['loan_id' => substr($claimedLoan, 0, 64)])
                ?? $this->attemptStore->get((int)$attempt['entity_id']) ?? $attempt;
        }
        // A registered customer's quote cannot be saved from this anonymous REST call: Magento's
        // AccessChangeQuoteControl (webapi_rest) refuses it, and it is not bypassed. The attempt is checked by the
        // reconciliation right away instead (or by the buyer's own `complete`).
        if (!empty($attempt['customer_id'])) {
            $this->attemptStore->transition($attempt, ['next_check_at' => gmdate('Y-m-d H:i:s')]);
            $this->logService->send('info', 'Payment-first webhook for a customer cart, reconciliation scheduled', ['module:webhook'],
                ['cart_id' => $attempt['cart_id']]);

            return ['status' => true, 'message' => 'scheduled'];
        }
        $result = $this->finalizer->finalize($attempt, Finalizer::SOURCE_WEBHOOK, 10);
        $this->logService->send('info', 'Payment-first webhook handled', ['module:webhook'],
            ['cart_id' => $attempt['cart_id'], 'result' => $result['status']]);

        return [
            'status' => in_array($result['status'], [Finalizer::COMPLETED, Finalizer::NOT_PAID], true),
            'message' => $result['status'],
        ];
    }
}
