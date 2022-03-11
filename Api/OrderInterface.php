<?php
namespace Aplazo\AplazoPayment\Api;
interface OrderInterface
{
    /**
     * GET for Post api
     * @param string $status
     * @param string $loanId
     * @param string $cartId
     * @param string $extOrderId
     * @param string $merchantApiToken
     * @return string
     */
    public function updateOrder(
                        $status,
                        $loanId,
                        $cartId,
                        $extOrderId,
                        $merchantApiToken
                    );
}