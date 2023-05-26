<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Magento\Framework\Event\ObserverInterface;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Magento\Framework\Exception\LocalizedException;

class SalesOrderPlaceAfterCreateLoan implements ObserverInterface
{
    private $aplazoHelper;
    private $orderService;

    public function __construct(
        AplazoHelper $aplazoHelper,
        OrderService $orderService
    )
    {
        $this->aplazoHelper = $aplazoHelper;
        $this->orderService = $orderService;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if($order->getPayment()->getMethod() === ConfigProvider::CODE){
            $result = $this->orderService->createLoan($order);
            if(!$result['success'] || empty($result['data']['url'])){
                $this->aplazoHelper->log($result['message']);
                throw new LocalizedException(__('Aplazo payment gateway is unavailable. Try again later.'));
            }
            $order->setStatus($this->aplazoHelper->getNewOrderStatus());
            $order->setAplazoCheckoutUrl($result['data']['url']);
        }

        return $this;
    }
}
