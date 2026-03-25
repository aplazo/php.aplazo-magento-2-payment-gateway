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
use Aplazo\AplazoPayment\Service\LogService;
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

    private LogService $logService;

    public function __construct(
        OrderService $orderService,
        AplazoService $aplazoService,
        LogService $logService
    )
    {
        $this->orderService = $orderService;
        $this->aplazoService = $aplazoService;
        $this->logService = $logService;
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
            $this->logService->send('info', 'Order cancel after: cancelling loan and updating stock', ['module:cancel'], ['order_id' => $order->getIncrementId(), 'state' => $order->getState(), 'status' => $order->getStatus()]);
            $this->orderService->decreasingStockAfterPaymentSuccess($order, 'order_canceled_aplazo');
            try{
                $this->aplazoService->cancelLoan([
                    "cartId" => $order->getIncrementId(),
                    "totalAmount" => 0,
                    "reason" => 'No payment'
                ]);
                $this->logService->send('info', 'Loan cancelled via API', ['module:cancel'], ['order_id' => $order->getIncrementId()]);
            } catch (LocalizedException $e) {
                $this->logService->send('error', 'Cancel loan API failed: ' . $e->getMessage(), ['module:cancel'], ['order_id' => $order->getIncrementId()]);
            }
        }
    }
}
