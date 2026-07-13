<?php

namespace Aplazo\AplazoPayment\Model\Product;

use Aplazo\AplazoPayment\Service\LogService;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockManagementInterface;

class InventoryStock
{

    private $stockRegistry;
    private LogService $logService;

    public function __construct(
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        LogService $logService
    )
    {
        $this->stockRegistry = $stockRegistry;
        $this->logService = $logService;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param $plus
     * @return void
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function updateQtyNotMSI($order, $plus)
    {
        $direction = $plus ? 'increase' : 'decrease';
        foreach ($order->getAllItems() as $item) {
            if($item->getProductType() == 'configurable'){
                continue;
            }

            $stockItem = $this->stockRegistry->getStockItem($item->getProductId());
            $stockBefore = (float)$stockItem->getQty();
            $qtyOrdered = (float)$item->getQtyOrdered();
            if($plus){
                $stockItem->setQty($stockBefore + $qtyOrdered);
                $stockItem->setIsInStock(true);
            } else {
                $stockItem->setQty($stockBefore - $qtyOrdered);
                if($stockItem->getQty() < 1){
                    $stockItem->setIsInStock(false);
                } else {
                    $stockItem->setIsInStock(true);
                }
            }
            $stockItem->save();
            $this->logService->send('info', "Legacy stock $direction for item", ['module:checkout', 'action:stock'], [
                'order_id' => $order->getIncrementId(),
                'product_id' => $item->getProductId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty_ordered' => $qtyOrdered,
                'stock_before' => $stockBefore,
                'stock_after' => (float)$stockItem->getQty(),
                'is_in_stock' => $stockItem->getIsInStock()
            ]);
        }
    }
}
