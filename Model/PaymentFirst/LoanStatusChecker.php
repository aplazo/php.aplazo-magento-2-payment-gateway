<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Service\ApiService;

/**
 * The trusted payment check of a payment-first attempt: Aplazo's loan status API, queried with the attempt store's
 * credentials. Neither the browser's message nor the webhook body proves the payment (see dev/payment-first/SECURITY-NOTE-webhook-status.md).
 *
 * The API is queried by cartId, and a cartId does not identify one loan: in STG the same cartId already lists loans of
 * other stores and tests under the same merchant (one of them OUTSTANDING, observed 2026-11-24, R-10), and in
 * production it repeats if a merchant's order numbers repeat. So the answer is read only for the loan this attempt is
 * known to own: the loan id persisted on the attempt (from createLoan's answer, or attributed by an operator), compared
 * exactly with each entry's loanId. Nothing else is used to correlate: never the first entry, the highest id or the
 * only OUTSTANDING one.
 *
 * Only PAID is ever acted on. NOT_PAID means "this attempt's loan is listed and is not paid now": it never closes an
 * attempt, because a loan can be paid later and its expiry is not known (contract C3). UNKNOWN covers every answer that
 * says nothing about this attempt's loan: no loan id on the attempt (the API is not even asked), a failed or unexpected
 * answer, an empty list, entries without loanId, or only other loan ids. ApiService::getLoanStatus returns false for every
 * failure, including a loan that does not exist.
 */
class LoanStatusChecker
{
    public const PAID = 'paid';
    public const NOT_PAID = 'not_paid';
    public const UNKNOWN = 'unknown';
    /** The store's Aplazo environment or merchant is no longer the attempt's: Aplazo is not asked (H-4). */
    public const CONTEXT_CHANGED = 'context_changed';

    public function __construct(
        private ApiService $apiService,
        private StoreScope $storeScope,
        private AttemptContext $context
    ) {
    }

    public function check(array $attempt): string
    {
        if ($this->context->mismatch($attempt) !== null) {
            return self::CONTEXT_CHANGED;
        }
        $loanId = trim((string)($attempt['loan_id'] ?? ''));
        if ($loanId === '') {
            return self::UNKNOWN;
        }
        try {
            $loans = $this->storeScope->run((int)$attempt['store_id'], function () use ($attempt) {
                return $this->apiService->getLoanStatus($attempt['cart_id']);
            });
        } catch (\Throwable $e) {
            return self::UNKNOWN;
        }
        if (!is_array($loans)) {
            return self::UNKNOWN;
        }
        $statuses = [];
        foreach ($loans as $loan) {
            if (is_array($loan) && isset($loan['loanId'], $loan['status']) && is_scalar($loan['loanId'])
                && (string)$loan['loanId'] === $loanId
            ) {
                $statuses[] = (string)$loan['status'];
            }
        }
        if ($statuses === []) {
            return self::UNKNOWN;
        }
        // The same loan listed twice with different statuses is not an answer either.
        if (count(array_unique($statuses)) > 1) {
            return self::UNKNOWN;
        }

        return $statuses[0] === ApiService::LOAN_SUCCESS_STATUS ? self::PAID : self::NOT_PAID;
    }
}
