<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * The cart lock of the payment-first start and conversion (BNPL-1664): the same lock as Magento's CartMutex
 * (`cart_lock_<quoteId>`, taken without waiting), so on Magento 2.4.8+ a popup start or conversion and a core placeOrder
 * of the same quote never run together.
 *
 * It does not depend on Magento\Quote\Model\CartMutexInterface, which only exists from Magento 2.4.8: a constructor
 * argument of that type would break setup:di:compile, and the webhook of every order, on earlier versions, popup or not.
 * Before 2.4.8 core placeOrder takes no cart lock, so this lock only orders the module's own start and conversions
 * against each other; the quote row lock and is_active still keep one order per quote for the conversion.
 */
class CartLock
{
    public function __construct(private LockManagerInterface $lockManager)
    {
    }

    /**
     * @throws CartBusyException when the cart is locked by another request (the start, a conversion, or core placeOrder)
     */
    public function execute(int $quoteId, callable $callable)
    {
        $name = 'cart_lock_' . $quoteId;
        if (!$this->lockManager->lock($name, 0)) {
            throw new CartBusyException(__('The cart is locked for processing. Please try again later.'));
        }
        try {
            return $callable();
        } finally {
            $this->lockManager->unlock($name);
        }
    }
}
