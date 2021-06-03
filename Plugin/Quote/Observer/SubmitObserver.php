<?php
/**
 * Copyright © Ibar Mata All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Plugin\Quote\Observer;

class SubmitObserver
{

    public function beforeExecute(\Quote\Observer\SubmitObserver $subject)
    {
        $order = $subject->getEvent()->getOrder();
        $payment = $order->getPayment()->getMethodInstance()->getCode();
        if($payment == 'aplazo_payment' && $order->getStatus()=='pending'){
            $order->setCanSendNewEmailFlag(false);
        }
        return [$subject];
    }
}
