<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

/**
 * The attempt being converted is no longer payable (another process settled it): the conversion is rolled back and the
 * attempt is not touched (BNPL-1664, H-1).
 */
class AttemptNotPayableException extends \RuntimeException
{
}
