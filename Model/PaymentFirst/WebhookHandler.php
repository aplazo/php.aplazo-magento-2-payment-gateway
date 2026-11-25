<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Aplazo's webhook for a payment-first attempt (BNPL-1664), after Notifications has validated its JWT as for every
 * order. The callback is only a signal to run the trusted check: its body status is not signed and the JWT's status
 * claim is not the payment status (dev/payment-first/SECURITY-NOTE-webhook-status.md), so neither is read as payment.
 *
 * The signed claims must match the attempt: merchantId equal to the attempt store's merchant, and loanId equal to the
 * attempt's when it already has one (a different one is refused, never written over the known one).
 *
 * An attempt without a loan id (createLoan's answer was lost) does not take it from the webhook (R-10). A valid
 * signature, the merchant and the cartId do not tie a loan to this attempt: the cartId is all the JWT shares with it,
 * and in Aplazo a cartId can carry loans of other stores or tests under the same merchant (STG, observed). Nothing else
 * in the claims (loanId, status, merchantId, cartId) links back to the attempt, and which URL Aplazo delivers a loan's
 * webhook to is not a confirmed contract (C6, C8). So the claimed loan id is only kept as a candidate on the attempt
 * (last_error `webhook_loan_unattributed:<loanId>`), nothing is converted, and the attempt stays as it was (uncertain,
 * still listed by `aplazo:payment-first:attempts --attention`) until an operator who has confirmed the loan with Aplazo
 * attributes it (`--attribute-loan`); the normal checks then confirm it against that loan only.
 */
class WebhookHandler
{
    /** last_error prefix of an attempt holding a webhook's loan id it could not attribute. */
    public const UNATTRIBUTED = 'webhook_loan_unattributed:';

    public function __construct(
        private AttemptStore $attemptStore,
        private Finalizer $finalizer,
        private ScopeConfigInterface $scopeConfig,
        private Trace $trace
    ) {
    }

    /**
     * @param array $claims the validated JWT payload
     * @return array{status: bool, message: string}
     */
    public function handle(array $attempt, array $claims): array
    {
        try {
            return $this->handleOnce($attempt, $claims);
        } finally {
            $this->trace->flush();
        }
    }

    private function handleOnce(array $attempt, array $claims): array
    {
        // The merchant the loan was requested with; the store's current one only for rows written before it was kept.
        $merchantId = (string)($attempt['merchant_id']
            ?: $this->scopeConfig->getValue('payment/aplazo_gateway/merchantid', ScopeInterface::SCOPE_STORE, (int)$attempt['store_id']));
        $claimedMerchant = (string)($claims['merchantId'] ?? $claims['sub'] ?? '');
        $claimedLoan = isset($claims['loanId']) ? (string)$claims['loanId'] : '';
        if ($merchantId === '' || $claimedMerchant !== $merchantId
            || ($claimedLoan !== '' && !empty($attempt['loan_id']) && $claimedLoan !== (string)$attempt['loan_id'])
        ) {
            $this->trace->add('error', 'Payment-first webhook does not match its attempt', ['module:webhook'],
                ['cart_id' => $attempt['cart_id'], 'merchant_matches' => $claimedMerchant === $merchantId]);

            return ['status' => false, 'message' => 'Webhook does not match the payment attempt'];
        }
        if (empty($attempt['loan_id'])) {
            if ($claimedLoan !== '') {
                $marker = self::UNATTRIBUTED . substr(preg_replace('/[^0-9A-Za-z_-]/', '', $claimedLoan), 0, 64);
                if (($attempt['last_error'] ?? null) !== $marker) {
                    $this->attemptStore->transition($attempt, ['last_error' => $marker]);
                }
            }
            $this->trace->add('warn', 'Payment-first webhook for an attempt without loan id: held for review', ['module:webhook'],
                ['cart_id' => $attempt['cart_id'], 'claimed_loan_id' => $claimedLoan]);

            return ['status' => false, 'message' => 'held_for_review'];
        }
        // A registered customer's quote cannot be saved from this anonymous REST call: Magento's
        // AccessChangeQuoteControl (webapi_rest) refuses it, and it is not bypassed. The attempt is checked by the
        // reconciliation right away instead (or by the buyer's own `complete`).
        if (!empty($attempt['customer_id'])) {
            $this->attemptStore->transition($attempt, ['next_check_at' => gmdate('Y-m-d H:i:s')]);
            $this->trace->add('info', 'Payment-first webhook for a customer cart, reconciliation scheduled', ['module:webhook'],
                ['cart_id' => $attempt['cart_id']]);

            return ['status' => true, 'message' => 'scheduled'];
        }
        $result = $this->finalizer->finalize($attempt, Finalizer::SOURCE_WEBHOOK, 10);
        $this->trace->add('info', 'Payment-first webhook handled', ['module:webhook'],
            ['cart_id' => $attempt['cart_id'], 'result' => $result['status']]);

        return [
            'status' => in_array($result['status'], [Finalizer::COMPLETED, Finalizer::NOT_PAID], true),
            'message' => $result['status'],
        ];
    }
}
