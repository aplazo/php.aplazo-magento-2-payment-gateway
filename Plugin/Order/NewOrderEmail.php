<?php

namespace Aplazo\AplazoPayment\Plugin\Order;

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

        return [$observer];
    }
}
