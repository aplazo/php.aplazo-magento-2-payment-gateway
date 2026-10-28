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

        if($order->getPayment()->getMethod() === ConfigProvider::CODE && $this->orderService->isPaymentFirstOrder($order)){
            // Popup payment-first (BNPL-1664): the loan was paid before this order existed. What cancelLoan does after a
            // payment is not confirmed (contract C5), so no reversal is requested from here; Magento releases the
            // native reservation. The attempt's reconciliation and an operator follow it up.
            $order->addCommentToStatusHistory(__('Aplazo: this order was paid before it was created (popup). Cancelling it did not request any loan reversal; check the loan with Aplazo.'));
            $this->logService->send('warn', 'Payment-first order cancelled; no loan reversal requested', ['module:cancel'], ['order_id' => $order->getIncrementId()]);
            return;
        }
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
