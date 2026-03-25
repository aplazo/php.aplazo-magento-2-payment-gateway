<?php

namespace Aplazo\AplazoPayment\Model\Product;

use Aplazo\AplazoPayment\Service\LogService;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySales\Model\CheckItemsQuantity;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;

class MSIStock
{

    private $getSkusByProductIds;
    private $getProductTypesBySkus;
    private $isSourceItemManagementAllowedForProductType;
    private $itemsToSellFactory;
    private $websiteRepository;
    private $stockByWebsiteIdResolver;
    private $checkItemsQuantity;
    private $salesEventExtensionFactory;
    private $salesEventFactory;
    private $salesChannelFactory;
    private $placeReservationsForSalesEvent;
    private LogService $logService;

    public function __construct(
        GetSkusByProductIdsInterface                         $getSkusByProductIds,
        GetProductTypesBySkusInterface                       $getProductTypesBySkus,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        ItemToSellInterfaceFactory                           $itemsToSellFactory,
        WebsiteRepositoryInterface                           $websiteRepository,
        StockByWebsiteIdResolverInterface                    $stockByWebsiteIdResolver,
        CheckItemsQuantity                                   $checkItemsQuantity,
        SalesEventExtensionFactory                           $salesEventExtensionFactory,
        SalesEventInterfaceFactory                           $salesEventFactory,
        SalesChannelInterfaceFactory                         $salesChannelFactory,
        PlaceReservationsForSalesEventInterface              $placeReservationsForSalesEvent,
        LogService                                           $logService
    )
    {
        $this->getSkusByProductIds = $getSkusByProductIds;
        $this->getProductTypesBySkus = $getProductTypesBySkus;
        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
        $this->itemsToSellFactory = $itemsToSellFactory;
        $this->websiteRepository = $websiteRepository;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->checkItemsQuantity = $checkItemsQuantity;
        $this->salesEventExtensionFactory = $salesEventExtensionFactory;
        $this->salesEventFactory = $salesEventFactory;
        $this->salesChannelFactory = $salesChannelFactory;
        $this->placeReservationsForSalesEvent = $placeReservationsForSalesEvent;
        $this->logService = $logService;
    }

    /**
     * @param Order $order
     * @param $plus
     * @param $type
     * @return mixed
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function updateQty($order, $plus, $type)
    {
        $direction = $plus ? 'increase' : 'decrease';
        $itemsById = $itemsBySku = $itemsToSell = [];
        $itemNames = [];
        foreach ($order->getItems() as $item) {
            if (!isset($itemsById[$item->getProductId()])) {
                $itemsById[$item->getProductId()] = 0;
            }
            $itemsById[$item->getProductId()] += $item->getQtyOrdered();
            $itemNames[$item->getProductId()] = $item->getName();
        }
        $productSkus = $this->getSkusByProductIds->execute(array_keys($itemsById));
        $productTypes = $this->getProductTypesBySkus->execute($productSkus);

        foreach ($productSkus as $productId => $sku) {
            if (false === $this->isSourceItemManagementAllowedForProductType->execute($productTypes[$sku])) {
                continue;
            }

            $itemsBySku[$sku] = (float)$itemsById[$productId];
            $reservationQty = $plus ? (float)$itemsById[$productId] : -(float)$itemsById[$productId];
            $itemsToSell[] = $this->itemsToSellFactory->create([
                'sku' => $sku,
                'qty' => $reservationQty
            ]);
            $this->logService->send('info', "MSI reservation $direction for item", ['module:checkout', 'action:stock'], [
                'order_id' => $order->getIncrementId(),
                'product_id' => $productId,
                'sku' => $sku,
                'name' => $itemNames[$productId] ?? '',
                'qty_ordered' => (float)$itemsById[$productId],
                'reservation_qty' => $reservationQty
            ]);
        }

        $websiteId = (int)$order->getStore()->getWebsiteId();
        $websiteCode = $this->websiteRepository->getById($websiteId)->getCode();
        $stockId = (int)$this->stockByWebsiteIdResolver->execute((int)$websiteId)->getStockId();

        $this->checkItemsQuantity->execute($itemsBySku, $stockId);

        $salesEventExtension = $this->salesEventExtensionFactory->create([
            'data' => ['objectIncrementId' => (string)$order->getIncrementId()]
        ]);

        /** @var SalesEventInterface $salesEvent */
        $salesEvent = $this->salesEventFactory->create([
            'type' => $type,
            'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
            'objectId' => (string)$order->getEntityId()
        ]);
        $salesEvent->setExtensionAttributes($salesEventExtension);
        $salesChannel = $this->salesChannelFactory->create([
            'data' => [
                'type' => SalesChannelInterface::TYPE_WEBSITE,
                'code' => $websiteCode
            ]
        ]);

        $this->placeReservationsForSalesEvent->execute($itemsToSell, $salesChannel, $salesEvent);
        return $order;
    }
}
