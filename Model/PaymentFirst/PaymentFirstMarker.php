<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Marks an order created by the payment-first finalizer (BNPL-1664). Takes over BNPL-1665's checkout mode mark
 * (b049755), which the browser could set through the payment data; this one is written only by the server.
 *
 * Such an order already has its paid loan, so the plugin does not create another one, and it keeps Magento's native
 * inventory reservation: no plugin stock movement at purchase, approval or cancellation. An order counts as
 * payment-first only when the mark names an attempt whose cartId and store are the order's own; redirect orders and
 * historical orders have no mark and keep their behaviour.
 */
class PaymentFirstMarker
{
    public const ATTEMPT_KEY = 'aplazo_payment_attempt';
    public const MODE_KEY = 'aplazo_checkout_mode';
    public const MODE_POPUP = 'popup';
    public const LOAN_KEY = 'aplazo_payment_id';

    public function __construct(private AttemptStore $attemptStore)
    {
    }

    public function isPaymentFirstOrder(?OrderInterface $order): bool
    {
        $payment = $order ? $order->getPayment() : null;
        $publicId = $payment ? (string)$payment->getAdditionalInformation(self::ATTEMPT_KEY) : '';
        if ($publicId === '') {
            return false;
        }
        $attempt = $this->attemptStore->getByPublicId($publicId);

        return $attempt !== null
            && $attempt['cart_id'] === (string)$order->getIncrementId()
            && (int)$attempt['store_id'] === (int)$order->getStoreId();
    }

    public static function mark($payment, array $attempt): void
    {
        $payment->setAdditionalInformation(self::ATTEMPT_KEY, $attempt['public_id']);
        $payment->setAdditionalInformation(self::MODE_KEY, self::MODE_POPUP);
        if (!empty($attempt['loan_id'])) {
            $payment->setAdditionalInformation(self::LOAN_KEY, $attempt['loan_id']);
        }
    }
}
