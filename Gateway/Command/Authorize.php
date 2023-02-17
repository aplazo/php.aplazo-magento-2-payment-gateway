<?php
namespace Aplazo\AplazoPayment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Authorize implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        return null;
    }
}
