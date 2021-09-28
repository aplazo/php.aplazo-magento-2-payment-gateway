<?php
namespace Aplazo\AplazoPayment\Plugin\Quote\Observer;

class SubmitObserver
{
    public function beforeExecute(
        \Magento\Quote\Observer\SubmitObserver $subject,
        \Magento\Framework\Event\Observer $observer
    ) {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment()->getMethodInstance()->getCode();
        if($payment == 'aplazo_payment' && $order->getStatus()=='pending'){
            $order->setCanSendNewEmailFlag(false);
        }
        return [$observer];
    }

}