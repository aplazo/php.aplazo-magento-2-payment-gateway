<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Api;

interface CheckoutNotPaidManagementInterface
{

    /**
     * @param string $incrementId
     * @return mixed
     */
    public function postCheckoutNotPaid($incrementId);
}
