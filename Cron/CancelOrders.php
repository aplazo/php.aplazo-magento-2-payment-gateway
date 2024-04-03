<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Aplazo\AplazoPayment\Service\ApiService;
use Magento\Sales\Model\Order;

class CancelOrders
{
    /**
     * @var Data
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
     * @param Data $aplazoHelper
     */
    public function __construct(
        OrderService $orderService,
        ApiService $apiService,
        Data $aplazoHelper
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
                $orders = [];
                /** @var Order $order */
                foreach ($orderCollection as $order) {
                    $orders[] = $this->apiService->getOrderImportantDataToLog($order);
                 }
                $message = 'Total de ordenes encontradas: ' . $orderCollectionCount;
                $this->aplazoHelper->log($message);
                $this->apiService->sendLog('Cron de cancelación de órdenes. ' . $message, Data::LOGS_CATEGORY_INFO, Data::LOGS_SUBCATEGORY_ORDER, ['orders' => $orders]);

                foreach ($orderCollection as $order) {
                    $store_id = $order->getStoreId();
                    $incrementId = $order->getIncrementId();
                    if($this->apiService->getLoanStatus($incrementId)){
                        try {
                            $orderResult = $this->orderService->getOrderByIncrementId($incrementId);
                            if ($orderResult['success']) {
                                /** @var Order $order */
                                $order = $orderResult['order'];
                                if($order->getStatus() === Data::APLAZO_WEBHOOK_RECEIVED){
                                    if (!$this->orderService->invoiceOrder($order)) {
                                        $message = 'No se pudo crear el invoice de la orden ' . $order->getIncrementId() . ' a través del cron.';
                                        $this->aplazoHelper->log( $message );
                                    } else {
                                        $order->setStatus($this->aplazoHelper->getApprovedOrderStatus());
                                        $message = 'Orden creo el invoice correctamente a través de cron.';
                                    }
                                } else {
                                    $orderService = $this->orderService->approveOrder($order->getId());
                                    $order = $orderService['order'];
                                    $orderPayment = $order->getPayment();
                                    $orderPayment->setAdditionalInformation('aplazo_status', $this->apiService::LOAN_SUCCESS_STATUS);
                                    $message = 'Orden en Aplazo pagada correctamente. Notificación realizada a través de cron.';
                                }

                                $order->addCommentToStatusHistory($message);
                                $order = $this->orderService->saveOrder($order);
                                $this->aplazoHelper->log("Order $incrementId is OUTSTANDING in Aplazo. Message > " . $message );
                                $this->apiService->sendLog($message, Data::LOGS_CATEGORY_INFO, Data::LOGS_SUBCATEGORY_ORDER,
                                    $this->apiService->getOrderImportantDataToLog($order)
                                );
                            } else {
                                $message = 'Order incrementId not found ' . $incrementId;
                                $this->aplazoHelper->log($message);
                                $this->apiService->sendLog($message, Data::LOGS_CATEGORY_ERROR, Data::LOGS_SUBCATEGORY_ORDER);
                            }
                        } catch (\Exception $e) {
                            $message = 'Order could not advance to paid status: ' . $incrementId .'. '. $e->getMessage();
                            $this->aplazoHelper->log($message);
                            $this->apiService->sendLog($message, Data::LOGS_CATEGORY_ERROR, Data::LOGS_SUBCATEGORY_ORDER);
                        }
                    } else {
                        $cancelResponse = $this->orderService->cancelOrder($order->getId());
                        if($cancelResponse['success']){
                            $message = "Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Cancelada exitosamente";
                            $this->aplazoHelper->log($message);
                            $this->apiService->sendLog($message, Data::LOGS_CATEGORY_INFO, Data::LOGS_SUBCATEGORY_ORDER, $this->apiService->getOrderImportantDataToLog($order));
                            $ordersCanceledCount++;
                        } else {
                            $message = "Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - No se pudo cancelar. Mensaje de error: " . $cancelResponse['message'];
                            $this->aplazoHelper->log($message);
                            $this->apiService->sendLog($message, Data::LOGS_CATEGORY_ERROR, Data::LOGS_SUBCATEGORY_ORDER, $this->apiService->getOrderImportantDataToLog($order));
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
