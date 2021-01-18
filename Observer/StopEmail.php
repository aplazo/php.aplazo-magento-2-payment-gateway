<?php

namespace Aplazo\AplazoPayment\Observer;

class StopEmail implements \Magento\Framework\Event\ObserverInterface
{
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
     

        $order = $observer->getEvent()->getOrder();
        

        $payment = $order->getPayment()->getMethodInstance()->getCode();

        if($payment == 'aplazo_payment' || $payment == 'aplazo_payment'){
             $order->setCanSendNewEmailFlag(false);
    $order->setSendEmail(false);
  
        //$order->save();
        }


}


} 