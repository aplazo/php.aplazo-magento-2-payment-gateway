<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Service\ApiService;
use Magento\Sales\Model\Order;

class CancelOrders
{
    /**
     * @var \Aplazo\AplazoPayment\Helper\Data
     */
    private $aplazoHelper;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var ApiService
     */
    private $apiService;

    /**
     * @param OrderService $orderService
     * @param ApiService $apiService
     * @param \Aplazo\AplazoPayment\Helper\Data $aplazoHelper
     */
    public function __construct(
        OrderService $orderService,
        ApiService $apiService,
        \Aplazo\AplazoPayment\Helper\Data $aplazoHelper
    )
    {
        $this->orderService = $orderService;
        $this->apiService   = $apiService;
        $this->aplazoHelper = $aplazoHelper;
    }

    /**
     * Execute the cron
     *
     * @return void
     */
    public function execute()
    {
        if($minutes = $this->aplazoHelper->getCancelTime()){
            $this->aplazoHelper->log('------ Cancelando ordenes ------');
            $orderCollection = $this->orderService->getOrderToCancelCollection($minutes);
            $counter = $ordersCanceledCount = 0;
            $ordersWithErrors = [];
            if(($orderCollectionCount = $orderCollection->getTotalCount()) > 0) {
                $this->aplazoHelper->log('Total de ordenes encontradas: ' . $orderCollectionCount);
                /**
                 * @var Order $order
                 */
                foreach ($orderCollection as $order) {
                    $store_id = $order->getStoreId();

                    $incrementId = $order->getIncrementId();
                    if($this->apiService->getLoanStatus($incrementId)){
                        try {
                            $orderResult = $this->orderService->getOrderByIncrementId($incrementId);
                            if ($orderResult['success']) {
                                /** @var Order $order */
                                $order = $orderResult['order'];
                                $orderService = $this->orderService->approveOrder($order->getId());
                                $order = $orderService['order'];
                                $orderPayment = $order->getPayment();
                                $orderPayment->setAdditionalInformation('aplazo_status', $this->apiService::LOAN_SUCCESS_STATUS);
                                $order->addCommentToStatusHistory('Orden en Aplazo pagada correctamente. Notificación realizada a través de cron.');
                                $this->orderService->saveOrder($order);
                                $this->aplazoHelper->log("Order $incrementId is OUTSTANDING in Aplazo.");
                            } else {
                                $this->aplazoHelper->log('Order incrementId not found ' . $incrementId);
                            }
                        } catch (\Exception $e) {
                            $this->aplazoHelper->log('Order could not advance to paid status: ' . $incrementId) .'. '. $e->getMessage();
                        }
                    } else {
                        $cancelResponse = $this->orderService->cancelOrder($order->getId());
                        if($cancelResponse['success']){
                            $this->aplazoHelper->log("Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Cancelada exitosamente");
                            $ordersCanceledCount++;
                        }
                        else{
                            $this->aplazoHelper->log("<comment>Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Con detalles</comment>");
                            $ordersWithErrors[] = $incrementId . ' ' . $cancelResponse['message'];
                        }
                    }

                    $counter++;
                }
            }

            $this->finish($ordersCanceledCount, $ordersWithErrors);
        }
    }

    private function finish($ordersCanceledCount, $ordersWithErrors)
    {
        if($ordersCanceledCount == 0 && count($ordersWithErrors) == 0){
            $this->aplazoHelper->log("<comment>No se encontraron ordenes de Aplazo para cancelar</comment>");
        }
        else {
            $this->aplazoHelper->log('');
            $this->aplazoHelper->log("<info>Total cancelados: $ordersCanceledCount.</info>");
        }

        if(count($ordersWithErrors) > 0){
            $this->aplazoHelper->log('');
            $this->aplazoHelper->log("<comment>Ordenes con conflictos</comment>");
            foreach ($ordersWithErrors as $error){
                $this->aplazoHelper->log($error);
            }
        }
    }
}
