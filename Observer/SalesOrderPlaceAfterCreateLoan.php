<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Magento\Framework\Event\ObserverInterface;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Magento\Framework\Exception\LocalizedException;
use Random\RandomException;

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
            $this->aplazoHelper->log('Observer sales_order_place_after pagando con Aplazo.', AplazoHelper::LOGS_VVV);
            try {
                $randomToken = $this->generateRandomString();
            } catch (RandomException $e) {
                $randomToken = "0";
            }
            $result = $this->orderService->createLoan($order, $randomToken);
            if(!empty($result['url'])){
                $order->setStatus($this->aplazoHelper->getNewOrderStatus());
                $aplazoCheckoutUrl = empty($randomToken) ? $result['url'] : $result['url'] . '||' . $randomToken;
                $order->setAplazoCheckoutUrl($aplazoCheckoutUrl);
                $this->aplazoHelper->log('Se guarda la url de Aplazo en la orden: '. $aplazoCheckoutUrl, AplazoHelper::LOGS_VVV);
            }
        }

        return $this;
    }

    /**
     * @param $length
     * @return string
     * @throws RandomException
     */
    private function generateRandomString($length = 16) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
