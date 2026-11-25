<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Plugin\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Aplazo\AplazoPayment\Model\PaymentFirst\Finalizer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Before any order is placed for a quote that started popup payment-first attempts, those attempts are settled with
 * Aplazo first (BNPL-1664).
 *
 * The checkout page blocks the buyer while a payment is not confirmed, but the page is not a boundary: a reload,
 * another tab, the cart page or another payment method all reach placeOrder. Without this check, a loan already paid
 * in Aplazo would be found only later by the webhook or the reconciliation, after the quote became an order with the
 * other method, and end rejected: the buyer charged twice and a manual refund.
 *
 * So placeOrder (every payment method, Aplazo redirect included, guest or customer) first runs the shared finalization
 * on each payable attempt of the quote, which asks Aplazo's loan status API:
 *  - paid and the cart unchanged: the attempt becomes the order (with Aplazo), and this placeOrder is refused with
 *    that order number. Nothing is charged with the other method;
 *  - a conversion of this quote is running right now (busy): refused, the buyer is asked to wait;
 *  - not paid, unknown (Aplazo did not answer, or the loan was never created), rejected or under review: placeOrder
 *    goes on as before. "Unknown" cannot block, because an absent loan also reads as unknown and would lock the cart
 *    for good. A loan paid after this moment is still found later and ends rejected: what this check cannot prevent
 *    (see dev/payment-first/REVIEW-BNPL-1664-mike-2026-11-20.md §1a).
 *
 * It runs before QuoteManagement::placeOrder takes the cart lock, so the finalization can take it. Cost: nothing for a
 * site where no store ever started an attempt (configuration only); otherwise one indexed read of
 * aplazo_payment_attempt per placeOrder, and one status request per payable attempt of that quote only.
 * Not covered: flows that call QuoteManagement::submit directly instead of placeOrder (e.g. PayPal Express).
 */
class SettlePaidAttemptsBeforePlaceOrder
{
    public function __construct(
        private AplazoHelper $aplazoHelper,
        private AttemptStore $attemptStore,
        private Finalizer $finalizer,
        private OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @param mixed $cartId
     * @param mixed $paymentMethod
     * @throws LocalizedException
     */
    public function beforePlaceOrder(CartManagementInterface $subject, $cartId, $paymentMethod = null): void
    {
        if (!$this->aplazoHelper->isPaymentFirstInUseInAnyStore()) {
            return;
        }
        foreach ($this->attemptStore->getForQuote((int)$cartId, AttemptStore::PAYABLE) as $attempt) {
            $result = $this->finalizer->finalize($attempt, Finalizer::SOURCE_BROWSER, 0);
            if ($result['status'] === Finalizer::COMPLETED) {
                $order = $this->orderRepository->get((int)$result['order_id']);
                throw new LocalizedException(__(
                    'Your Aplazo payment for this cart was already confirmed: order %1. No other order was placed and nothing else was charged.',
                    $order->getIncrementId()
                ));
            }
            if ($result['status'] === Finalizer::BUSY) {
                throw new LocalizedException(__(
                    'We are confirming an Aplazo payment for this cart. Please wait a moment and try again.'
                ));
            }
        }
    }
}
