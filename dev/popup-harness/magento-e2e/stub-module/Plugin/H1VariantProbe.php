<?php

namespace LocalDev\AplazoStub\Plugin;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\App\ResourceConnection;

/**
 * LOCAL TEST ONLY (BNPL-1664, H-1 and Mike's point 6). Around AttemptStore::hasAttemptForQuote(), the step (b) of
 * KeepConvertedQuoteClosed. With var/localdev_pf_h1_variant = order_only it answers what Mike's wording asks instead:
 * "is there an attempt WITH ORDER for this quote", as a plain (non-locking) indexed read, so the guard only takes the
 * FOR UPDATE when that read finds an order. Without the file it changes nothing. Every call is logged with the answer.
 */
class H1VariantProbe
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function aroundHasAttemptForQuote(AttemptStore $subject, callable $proceed, int $quoteId): bool
    {
        $file = BP . '/var/localdev_pf_h1_variant';
        $variant = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        if ($variant !== 'order_only') {
            $answer = $proceed($quoteId);
            StubLog::write('probe', 'h1_step_b', ['variant' => 'current', 'quote_id' => $quoteId, 'answer' => $answer]);

            return $answer;
        }
        $connection = $this->resource->getConnection();
        $answer = (bool)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('aplazo_payment_attempt'), ['entity_id'])
                ->where('quote_id = ?', $quoteId)->where('order_id IS NOT NULL')->limit(1)
        );
        StubLog::write('probe', 'h1_step_b', ['variant' => 'order_only', 'quote_id' => $quoteId, 'answer' => $answer]);

        return $answer;
    }
}
