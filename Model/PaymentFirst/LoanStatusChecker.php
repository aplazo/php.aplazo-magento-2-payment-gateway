<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Service\ApiService;

/**
 * The trusted payment check of a payment-first attempt: Aplazo's loan status API, queried with the attempt store's
 * credentials. Neither the browser's message nor the webhook body proves the payment (see dev/payment-first/SECURITY-NOTE-webhook-status.md).
 *
 * Only PAID is ever acted on. NOT_PAID means "no paid loan was reported now": it never closes an attempt, because a
 * loan can be paid later and its expiry is not known (contract C3). Any failure or unexpected answer is UNKNOWN.
 * ApiService::getLoanStatus returns false for every failure, including a loan that does not exist, so an absent loan
 * is UNKNOWN too.
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
        foreach ($loans as $loan) {
            if (!is_array($loan) || !isset($loan['status'])) {
                return self::UNKNOWN;
            }
            // The API is queried by cartId; a known loan id must match when the answer carries one (contract C7).
            if (!empty($attempt['loan_id']) && isset($loan['loanId']) && (string)$loan['loanId'] !== (string)$attempt['loan_id']) {
                continue;
            }
            if ($loan['status'] === ApiService::LOAN_SUCCESS_STATUS) {
                return self::PAID;
            }
        }

        return $loans === [] ? self::UNKNOWN : self::NOT_PAID;
    }
}
