<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Model\Service\OrderService;
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
     * Constructor
     *
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        OrderService $orderService,
        \Aplazo\AplazoPayment\Helper\Data $aplazoHelper
    )
    {
        $this->orderService = $orderService;
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
            $orderCollection = $this->orderService->getOrderToCancelCollection($minutes);
            $counter = $ordersCanceledCount = 0;
            $ordersWithErrors = [];
            if(($orderCollectionCount = $orderCollection->getTotalCount()) > 0) {
                /**
                 * @var Order $order
                 */
                foreach ($orderCollection as $order) {
                    $store_id = $order->getStoreId();
                    $this->aplazoHelper->log('------ Cancelando ordenes ------');
                    $this->aplazoHelper->log('Total de ordenes encontradas: ' . $orderCollectionCount);

                    $incrementId = $order->getIncrementId();
                    $cancelResponse = $this->orderService->cancelOrder($order->getId());
                    if($cancelResponse['success']){
                        $this->aplazoHelper->log("Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Cancelada exitosamente");
                        $ordersCanceledCount++;
                    }
                    else{
                        $this->aplazoHelper->log("<comment>Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Con detalles</comment>");
                        $ordersWithErrors[] = $incrementId . ' ' . $cancelResponse['message'];
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
