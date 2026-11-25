<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Magento\Framework\Exception\StateException;

/**
 * The quote's cart lock is held by another request (CartLock).
 */
class CartBusyException extends StateException
{
}
