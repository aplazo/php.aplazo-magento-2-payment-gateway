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
use Aplazo\AplazoPayment\Service\LogService;
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

    private LogService $logService;

    /**
     * @param OrderService $orderService
     * @param ApiService $apiService
     * @param Data $aplazoHelper
     * @param LogService $logService
     */
    public function __construct(
        OrderService $orderService,
        ApiService $apiService,
        Data $aplazoHelper,
        LogService $logService
    )
    {
        $this->orderService = $orderService;
        $this->apiService   = $apiService;
        $this->aplazoHelper = $aplazoHelper;
        $this->logService   = $logService;
    }

    /**
     * Execute the cron
     *
     * @return void
     */
    public function execute()
    {
        $this->logService->resetRequestId();
        if($minutes = $this->aplazoHelper->getCancelTime()){
            $this->aplazoHelper->log('------ Cancelando ordenes ------');
            $orderCollection = $this->orderService->getOrderToCancelCollection($minutes);
            $counter = $ordersCanceledCount = 0;
            $ordersWithErrors = [];
            $cancelledIds = [];
            $recoveredIds = [];
            if(($orderCollectionCount = $orderCollection->getTotalCount()) > 0) {
                $orders = [];
                /** @var Order $order */
                foreach ($orderCollection as $order) {
                    $orders[] = $this->apiService->getOrderImportantDataToLog($order);
                 }
                $message = 'Total de ordenes encontradas: ' . $orderCollectionCount;
                $this->aplazoHelper->log($message);

                foreach ($orderCollection as $order) {
                    $store_id = $order->getStoreId();
                    $incrementId = $order->getIncrementId();
                    if($this->apiService->shouldCancelOrder($incrementId)){
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
                                $recoveredIds[] = $incrementId;
                            } else {
                                $message = 'Order incrementId not found ' . $incrementId;
                                $this->aplazoHelper->log($message);
                                $this->logService->send('error', $message, ['module:cron'], ['increment_id' => $incrementId]);
                            }
                        } catch (\Exception $e) {
                            $message = 'Order could not advance to paid status: ' . $incrementId .'. '. $e->getMessage();
                            $this->aplazoHelper->log($message);
                            $this->logService->send('error', $message, ['module:cron'], ['increment_id' => $incrementId]);
                        }
                    } else {
                        $cancelResponse = $this->orderService->cancelOrder($order->getId());
                        if($cancelResponse['success']){
                            $message = "Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Cancelada exitosamente";
                            $this->aplazoHelper->log($message);
                            $ordersCanceledCount++;
                            $cancelledIds[] = $incrementId;
                        } else {
                            $message = "Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - No se pudo cancelar. Mensaje de error: " . $cancelResponse['message'];
                            $this->aplazoHelper->log($message);
                            $this->logService->send('error', 'Cron: order cancel failed', ['module:cron'], array_merge($this->apiService->getOrderImportantDataToLog($order), ['error' => $cancelResponse['message']]));
                            $ordersWithErrors[] = $incrementId . ' ' . $cancelResponse['message'];
                        }
                    }
                    $counter++;
                }
            }

            $this->finish($ordersCanceledCount, $ordersWithErrors, $cancelledIds, $recoveredIds);
        }
    }

    private function finish($ordersCanceledCount, $ordersWithErrors, $cancelledIds = [], $recoveredIds = [])
    {
        $cancelledCount = $ordersCanceledCount;
        $recoveredCount = count($recoveredIds);

        if($cancelledCount == 0 && count($ordersWithErrors) == 0 && $recoveredCount == 0){
            $this->aplazoHelper->log("<comment>No se encontraron ordenes de Aplazo para cancelar</comment>");
        } else {
            $this->aplazoHelper->log('');
            $this->aplazoHelper->log("<info>Total cancelados: $ordersCanceledCount.</info>");

            if ($cancelledCount > 0 && $recoveredCount > 0) {
                $this->logService->send('info', 'Se cancelaron y recuperaron ordenes', ['module:cron'], [
                    'cancelled_count' => $cancelledCount,
                    'recovered_count' => $recoveredCount,
                    'cancelled_orders' => $cancelledIds,
                    'recovered_orders' => $recoveredIds,
                ]);
            } elseif ($cancelledCount > 0) {
                $this->logService->send('info', 'Se cancelaron ordenes', ['module:cron'], [
                    'cancelled_count' => $cancelledCount,
                    'cancelled_orders' => $cancelledIds,
                ]);
            } elseif ($recoveredCount > 0) {
                $this->logService->send('info', 'Se recuperaron ordenes', ['module:cron'], [
                    'recovered_count' => $recoveredCount,
                    'recovered_orders' => $recoveredIds,
                ]);
            }
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
