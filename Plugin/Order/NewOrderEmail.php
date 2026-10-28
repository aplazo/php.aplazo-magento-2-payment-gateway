<?php

namespace Aplazo\AplazoPayment\Plugin\Order;

use Aplazo\AplazoPayment\Model\PaymentFirst\PaymentFirstMarker;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;

class NewOrderEmail
{
    private $aplazoHelper;

    public function __construct(
        \Aplazo\AplazoPayment\Helper\Data $aplazoHelper
    )
    {
        $this->aplazoHelper = $aplazoHelper;
    }

    public function beforeExecute($subject, $observer)
    {
        $order = $observer->getEvent()->getOrder();

        if ($order->getPayment()->getMethod() === ConfigProvider::CODE && $this->aplazoHelper->getSendEmail()) {
            $order->setCanSendNewEmailFlag(false);
        }
        // Popup payment-first (BNPL-1664): the order is submitted inside the finalizer's transaction; its email is
        // handed to Magento once the order is committed and approved (Finalizer::requestEmail), not from here.
        if ($order->getPayment()->getMethod() === ConfigProvider::CODE
            && $order->getPayment()->getAdditionalInformation(PaymentFirstMarker::ATTEMPT_KEY)
        ) {
            $order->setCanSendNewEmailFlag(false);
        }

        return [$observer];
    }
}
