<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The checkout mode the server accepted when the order was created (BNPL-1665), kept in the payment's
 * additional_information. Orders marked as popup keep Magento's native inventory reservation: the plugin applies none
 * of its own stock movements to them (purchase, approval, cancellation). Orders without the mark keep the previous
 * behaviour. The mark records the accepted request, not that the Aplazo window was physically opened.
 */
class CheckoutModeMarker
{
    public const KEY = 'aplazo_checkout_mode';
    public const POPUP = CheckoutMode::MODE_POPUP;

    public static function isPopupOrder(?OrderInterface $order): bool
    {
        $payment = $order ? $order->getPayment() : null;

        return $payment !== null && $payment->getAdditionalInformation(self::KEY) === self::POPUP;
    }
}
