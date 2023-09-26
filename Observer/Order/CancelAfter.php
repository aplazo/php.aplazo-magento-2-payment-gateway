<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Observer\Order;

use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

class CancelAfter implements \Magento\Framework\Event\ObserverInterface
{

    /**
     * @var AplazoService
     */
    private $aplazoService;
    /**
     * @var OrderService
     */
    private $orderService;

    public function __construct(
        OrderService $orderService,
        AplazoService $aplazoService
    )
    {
        $this->orderService = $orderService;
        $this->aplazoService = $aplazoService;
    }

    /**
     * Execute observer
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(
        \Magento\Framework\Event\Observer $observer
    ) {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if($order->getPayment()->getMethod() === ConfigProvider::CODE){
            $this->orderService->decreasingStockAfterPaymentSuccess($order, 'order_canceled_aplazo');
            try{
                $this->aplazoService->cancelLoan([
                    "cartId" => $order->getIncrementId(),
                    "totalAmount" => 0,
                    "reason" => 'No payment'
                ]);
            } catch (LocalizedException $e) {
            }
        }
    }
}
