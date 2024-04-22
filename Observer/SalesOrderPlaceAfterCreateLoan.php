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
            try {
                $token = $this->generateRandomString();
            } catch (RandomException $e) {
		$token = "0";
            }
            $result = $this->orderService->createLoan($order, $token);
            if(!$result['success'] || empty($result['data']['url'])){
                $this->aplazoHelper->log($result['message']);
                throw new LocalizedException(__('Aplazo payment gateway is unavailable. Try again later.'));
            }
            $order->setStatus($this->aplazoHelper->getNewOrderStatus());
            $aplazoCheckoutUrl = empty($token) ? $result['data']['url'] : $result['data']['url'] . '||' . $token;
            $order->setAplazoCheckoutUrl($aplazoCheckoutUrl);
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
