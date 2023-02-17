<?php
namespace Aplazo\AplazoPayment\Api;


interface NotificationsInterface
{
    /**
     * @param string $loanId
     * @param string $status
     * @param string $cartId
     * @return mixed
     */
    public function notify($loanId, $status, $cartId);

}
