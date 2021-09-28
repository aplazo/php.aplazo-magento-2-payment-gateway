<?php

namespace Aplazo\AplazoPayment\Observer;

use Magento\Framework\Event\ObserverInterface;

class Sendmail implements ObserverInterface
{

    protected $orderModel;


    protected $orderSender;


    protected $checkoutSession;

    protected $orderNotifier;


    public function __construct(
        \Magento\Sales\Model\OrderFactory $orderModel,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderNotifier $orderNotifier
    )
    {
        $this->orderModel = $orderModel;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->orderNotifier = $orderNotifier;
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $orderIds = $observer->getEvent()->getOrderIds();
        if(count($orderIds))
        {
            $order = $this->orderModel->create()->load($orderIds[0]);
            $paymentCode = $observer->getOrder()->getPayment()->getMethod();
            $paymentStatus = $observer->getOrder()->getData('status');
            if($paymentCode == 'aplazo_payment' && $paymentStatus=='processing'){
                $this->checkoutSession->setForceOrderMailSentOnSuccess(true);
                $this->orderSender->send($order, true);
            }
        }
    }
}