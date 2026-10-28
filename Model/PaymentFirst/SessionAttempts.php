<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * Which payment-first attempts this browser session started (BNPL-1664). The browser only holds the opaque public id;
 * an id is accepted only if this session started it and, for a logged-in customer, if it belongs to that customer.
 * When the session expires the attempt is still finalized by the webhook or the reconciliation, but the browser gets
 * no details about it.
 */
class SessionAttempts
{
    private const KEY = 'aplazo_payment_first_attempts';
    private const LIMIT = 10;

    public function __construct(
        private CheckoutSession $checkoutSession,
        private CustomerSession $customerSession
    ) {
    }

    public function remember(array $attempt): void
    {
        $ids = array_values(array_diff($this->ids(), [$attempt['public_id']]));
        $ids[] = $attempt['public_id'];
        $this->checkoutSession->setData(self::KEY, array_slice($ids, -self::LIMIT));
    }

    public function owns(?array $attempt): bool
    {
        if ($attempt === null || !in_array($attempt['public_id'], $this->ids(), true)) {
            return false;
        }
        $customerId = (int)$this->customerSession->getCustomerId();

        return (int)$attempt['customer_id'] === $customerId;
    }

    private function ids(): array
    {
        $ids = $this->checkoutSession->getData(self::KEY);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
