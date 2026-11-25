<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Observer\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * A quote that already became a payment-first order is never written back as active (BNPL-1664, finding H-1).
 *
 * A cart request that read the quote before the finalizer converted it, and saves it afterwards, would otherwise write
 * its stale copy over the converted quote (is_active=1, other items, no reserved order id), leaving the buyer an active
 * cart that can be paid again. Reproduced locally with checkout/sidebar/updateItemQty.
 *
 * Runs on sales_quote_save_before, which Magento dispatches inside the quote save's own transaction, for every quote
 * save of every store, so it is ordered from cheapest to most expensive and most saves stop at the first step:
 *  (a) the quote's store never started an attempt and popup is off there (AplazoHelper::isPaymentFirstInUse,
 *      configuration only): nothing else, no query;
 *  (b) the quote never started an attempt (indexed read of aplazo_payment_attempt by quote_id): nothing else. Attempts
 *      are committed at start, long before any conversion, so a quote without one cannot be in conversion;
 *  (c) only then the quote row is read with FOR UPDATE, which waits for a conversion in progress (the finalizer holds
 *      that row lock until it commits) and returns the committed value. Step (b) asks for any attempt, not for one with
 *      an order: the order id of a conversion in progress is not committed yet, and checking it before the lock would
 *      let the stale save through (H-1).
 * The finalizer writes the attempt's order_id in the same transaction that deactivates the quote, so a committed
 * inactive quote of a payment-first conversion always has it. If the row is inactive and an attempt of this quote has
 * an order, the save is refused the way Magento refuses an inactive cart (NoSuchEntityException on cartId) and rolled
 * back. Inactive quotes of any other origin are left alone, so Magento's restoreQuote() keeps working for them.
 *
 * Turning popup off does not turn this guard off for a store that started attempts: the reconciliation and the
 * webhook can still convert them, and a stale save could still race that conversion.
 */
class KeepConvertedQuoteClosed implements ObserverInterface
{
    public function __construct(
        private ResourceConnection $resource,
        private AttemptStore $attemptStore,
        private AplazoHelper $aplazoHelper
    ) {
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote || !$quote->getId() || !$quote->getIsActive()
            || !$this->aplazoHelper->isPaymentFirstInUse((int)$quote->getStoreId())
        ) {
            return;
        }
        $quoteId = (int)$quote->getId();
        if (!$this->attemptStore->hasAttemptForQuote($quoteId)) {
            return;
        }
        $active = $this->resource->getConnection()->fetchOne(
            'SELECT is_active FROM ' . $this->resource->getTableName('quote') . ' WHERE entity_id = ? FOR UPDATE',
            [$quoteId]
        );
        if ($active === false || (int)$active === 1 || !$this->attemptStore->hasOrderForQuote($quoteId)) {
            return;
        }
        throw NoSuchEntityException::singleField('cartId', $quoteId);
    }
}
