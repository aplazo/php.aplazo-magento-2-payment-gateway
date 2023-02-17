<?php

namespace Aplazo\AplazoPayment\Model\Service;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use \Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Observer\DataAssignObserver;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockManagementInterface;

class OrderService
{
    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AplazoService
     */
    private $aplazoService;

    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;

    /**
     * @var MaskedQuoteIdToQuoteIdInterface
     */
    private $maskedQuoteIdToQuoteId;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var StockConfigurationInterface
     */
    private $stockConfiguration;

    /**
     * @var StockManagementInterface
     */
    private $stockManagement;

    /**
     * @var \Magento\Catalog\Model\Indexer\Product\Price\Processor
     */
    private $priceIndexer;

    private $stockRegistry;

    /**
     * @var \Magento\InventoryApi\Api\SourceItemsSaveInterface
     */
    private $sourceItemsSaveInterface;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var SourceItemRepositoryInterface
     */
    private $sourceItemRepository;

    public function __construct
    (
        OrderCollectionFactory                                 $orderCollectionFactory,
        OrderRepositoryInterface                               $orderRepository,
        AplazoService                                          $aplazoService,
        AplazoHelper                                           $aplazoHelper,
        MaskedQuoteIdToQuoteIdInterface                        $maskedQuoteIdToQuoteId,
        InvoiceService                                         $invoiceService,
        TransactionFactory                                     $transactionFactory,
        \Magento\Framework\UrlInterface                        $url,
        StockConfigurationInterface                            $stockConfiguration,
        StockManagementInterface                               $stockManagement,
        \Magento\CatalogInventory\Api\StockRegistryInterface   $stockRegistry,
        \Magento\Catalog\Model\Indexer\Product\Price\Processor $priceIndexer,
        \Magento\InventoryApi\Api\SourceItemsSaveInterface $sourceItemsSaveInterface,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SourceItemRepositoryInterface $sourceItemRepository
    )
    {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
        $this->url = $url;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->stockConfiguration = $stockConfiguration;
        $this->stockManagement = $stockManagement;
        $this->priceIndexer = $priceIndexer;
        $this->stockRegistry = $stockRegistry;
        $this->sourceItemsSaveInterface = $sourceItemsSaveInterface;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sourceItemRepository = $sourceItemRepository;
    }

    /**
     * @param $quoteId
     * @return array
     */
    public function purchaseAction($quoteId)
    {
        if (!is_numeric($quoteId)) {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($quoteId);
        }
        $result = $this->getOrderByAttribute(OrderInterface::QUOTE_ID, $quoteId, false);
        if ($result['success']) {
            $order = $result['order'];
            $this->reservingStockUntilPayment($order);
            $order->setStatus($this->aplazoHelper->getNewOrderStatus());
            $this->saveOrder($order);
            $result = $this->createLoan($order->getEntityId());
        }
        return $result;
    }

    /**
     * @param Order $order
     * @return void
     */
    public function reservingStockUntilPayment($order)
    {
        if ($this->stockConfiguration->canSubtractQty() && $this->aplazoHelper->getReserveStock()) {
            /** @var Item $item */
//            foreach ($order->getAllVisibleItems() as $item) {
//                $qty = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();
//                if ($item->getId() && $item->getProductId() && $qty) {
//                    $this->stockManagement->backItemQty($item->getProductId(), $qty, $item->getStore()->getWebsiteId());
//                    $this->priceIndexer->reindexRow($item->getProductId());
//                }
//            }
            $this->updateQty($order, true);
        }
    }

    /**
     * @param Order $order
     * @return Order
     */
    public function decreasingStockAfterPaymentSuccess($order)
    {
        if ($this->stockConfiguration->canSubtractQty() && $this->aplazoHelper->getReserveStock()) {
//            try {
//                $items = [];
//                foreach ($order->getAllVisibleItems() as $item) {
//                    if(!($productId = $item->getProductId())) {
//                        continue;
//                    }
//                    $items[$productId] = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();
//                }
//
//                $itemsForReindex = $this->stockManagement->registerProductsSale(
//                    $items,
//                    $order->getStore()->getWebsiteId()
//                );
//                $ids = [];
//
//                foreach ($itemsForReindex as $itemForReindex) {
//                    $this->priceIndexer->reindexRow($itemForReindex->getProductId());
//                }
//            } catch (LocalizedException $e) {
//                $message = __("Error de inventario. No se pudo disminuir la cantidad de un producto. Mensaje > ") . $e->getMessage();
//                $order->addCommentToStatusHistory($message);
//                $this->aplazoHelper->log("Order " . $order->getIncrementId() . " posible error de inventario.");
//            }

            $order = $this->updateQty($order, false);
        }
        return $order;
    }

    public function updateQty($order, $plus)
    {
        /** @var Item $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $qty = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();
            if ($item->getId() && $item->getProductId() && $qty) {
//                $searchCriteria = $this->searchCriteriaBuilder
//                    ->addFilter(SourceItemInterface::SKU, $item->getSku())
//                    ->create();
//
//                $result = $this->sourceItemRepository->getList($searchCriteria)->getItems();
//
//                foreach($result as $sourceItem){
//                    if($plus){
//                        $sourceItem->setQuantity($sourceItem->getQuantity() + (float)$qty);
//                    } else {
//                        $sourceItem->setQuantity($sourceItem->getQuantity() - (float)$qty);
//                    }
//                }
//                try {
//                    $this->sourceItemsSaveInterface->execute([$sourceItem]);
//                } catch (LocalizedException $e) {
//                    $message = __("Error de inventario. No se pudo disminuir la cantidad del producto con sku " . $item->getSku() . ". Mensaje > ") . $e->getMessage();
//                    $order->addCommentToStatusHistory($message);
//                    $this->aplazoHelper->log("Order " . $order->getIncrementId() . " posible error de inventario.");
//                }

                try {
                    $stockItem = $this->stockRegistry->getStockItemBySku($item->getSku());
                    if($plus){
                        $qtyTotal = $stockItem->getQty() + (float)$qty;
                    } else {
                        $qtyTotal = $stockItem->getQty() - (float)$qty;
                    }
                    $stockItem->setQty($qtyTotal);
                    $stockItem->setIsInStock((bool)$qtyTotal);
                    $stockItem->setStockStatusChangedAutomaticallyFlag(true);
                    $this->stockRegistry->updateStockItemBySku($item->getSku(), $stockItem);
                    $this->priceIndexer->reindexRow($item->getProductId());
                } catch (LocalizedException $e) {
                    $message = __("Error de inventario. No se pudo disminuir la cantidad del producto con sku " . $item->getSku() . ". Mensaje > ") . $e->getMessage();
                    $order->addCommentToStatusHistory($message);
                    $this->aplazoHelper->log("Order " . $order->getIncrementId() . " posible error de inventario.");
                }
            }
        }
        return $order;
    }

    /**
     * @param $quoteId
     * @return array
     */
    public function getOrderIdByQuoteId($quoteId)
    {
        if (!is_numeric($quoteId)) {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($quoteId);
        }
        return $this->getOrderByAttribute(OrderInterface::QUOTE_ID, $quoteId, true);
    }

    /**
     * @param $orderId
     * @return array
     */
    public function getQuoteIdByOrderId($orderId)
    {
        $result = $this->getOrderByAttribute(OrderInterface::ENTITY_ID, $orderId, false);
        if ($result['success']) {
            $order = $result['order'];
            unset($result['order']);
            $result['quote_id'] = $order->getQuoteId();
        }
        return $result;
    }

    /**
     * @param $entityId
     * @return array
     */
    public function getOrderById($entityId)
    {
        return $this->getOrderByAttribute(OrderInterface::ENTITY_ID, $entityId, false);
    }

    /**
     * @param $incrementId
     * @return array
     */
    public function getOrderByIncrementId($incrementId)
    {
        return $this->getOrderByAttribute(OrderInterface::INCREMENT_ID, $incrementId, false);
    }

    public function isOrderKeyValid($orderId, $orderKeyUnverified)
    {
        $orderKeyValid = false;
        try {
            $order = $this->orderRepository->get($orderId);
            $orderKey = (string)$order->getPayment()->getAdditionalInformation(\Aplazo\AplazoPayment\Observer\DataAssignObserver::APLAZO_ORDER_KEY);
            if (isset($orderKey)) {
                $orderKeyDecoded = explode(':', base64_decode($orderKey));
                //\Aplazo\AplazoPayment\Helper\Data::log("OrderKey: $orderKeyDecoded[0] vs $orderKeyUnverified - QuooteId: $orderKeyDecoded[1] vs {$order->getQuoteId()}",'aplazo_pay_verification.log');

                $orderKeyValid = $orderKeyDecoded[0] == $orderKeyUnverified && $orderKeyDecoded[1] == $order->getQuoteId();
            }
        } catch (\Exception $e) {
        }
        return $orderKeyValid;
    }

    /**
     * @param $order
     * @return OrderInterface
     */
    public function saveOrder($order)
    {
        return $this->orderRepository->save($order);
    }

    /**
     * @param $attribute_code
     * @param $value
     * @param $retrieveId
     * @return array
     */
    private function getOrderByAttribute($attribute_code, $value, $retrieveId)
    {
        $result = ['success' => false, 'message' => ''];
        try {
            /**
             * @var Order $orderLoaded
             */
            $orderLoaded = $this->orderCollectionFactory->create()->addFieldToFilter($attribute_code, ['eq' => $value])->getFirstItem();
            if ($orderLoaded->getId()) {
                if ($retrieveId) {
                    $result['order_id'] = $orderLoaded->getId();
                } else {
                    $result['order'] = $orderLoaded;
                }
                $result['success'] = true;
            } else {
                $result['message'] = __('Order with %1 %2 not found', $attribute_code, $value);
            }
        } catch (\Exception $exception) {
            $result['message'] = $exception->getMessage();
        }
        return $result;
    }

    /**
     * @param string $orderId
     * @return array
     */
    public function createLoan($orderId)
    {
        $result = ['success' => false, 'data' => '', 'message' => ''];
        try {
            /**
             * @var OrderInterface $order
             */
            $order = $this->orderRepository->get($orderId);
            $billingAddress = $order->getBillingAddress();
            $products = [];
            foreach ($order->getItems() as $item) {
                if ($item->getProductType() != 'configurable') {
                    $products[] = [
                        "count" => intval($item->getQtyOrdered()),
                        "description" => $item->getDescription(),
                        "id" => $item->getProductId(),
                        "imageUrl" => '',
                        "price" => $item->getRowTotal(),
                        "title" => $item->getName()
                    ];
                }
            }
            $orderData = [
                "buyer" => [
                    "addressLine" => trim($billingAddress->getStreetLine(1) . ' ' . $billingAddress->getStreetLine(2)),
                    "email" => $billingAddress->getEmail(),
                    "firstName" => $billingAddress->getFirstname(),
                    "lastName" => $billingAddress->getLastname(),
                    //"loan_id"=> 0,
                    "phone" => $billingAddress->getTelephone(),
                    "postalCode" => $billingAddress->getPostcode()
                ],
                "cartId" => $orderId,
                "cartUrl" => $this->aplazoHelper->getUrl('checkout/cart'),
                "discount" => [
                    "price" => abs($order->getBaseDiscountAmount()),
                    "title" => "discount"
                ],
                "errorUrl" => $this->aplazoHelper->getUrl('aplazo/order/operations', ['operation' => 'redirect_to_onepage', 'onepage' => 'failure', 'orderid' => $orderId]),
                "products" => $products,
                "shipping" => [
                    "price" => abs($order->getBaseShippingAmount()),
                    "title" => 'DHL'
                ],
                "shopId" => $order->getIncrementId(),
                "successUrl" => $this->aplazoHelper->getUrl('aplazo/order/operations', ['operation' => 'redirect_to_onepage', 'onepage' => 'success', 'orderid' => $orderId]),
                "taxes" => [
                    "price" => 0,//$order->getTaxAmount()
                    "title" => "VAT"
                ],
                "totalPrice" => $order->getBaseGrandTotal(),
                "webHookUrl" => $this->aplazoHelper->getUrl('rest/V1/aplazo') . 'callback'
            ];

            $response = $this->aplazoService->createLoan($orderData);
            if (is_array($response)) {
                if (array_key_exists('status', $response)) {
                    $result['success'] = $response['status'];
                } else {
                    $result['success'] = true;
                }

                if (array_key_exists('message', $response)) {
                    $result['message'] = $response['message'];
                }
                $result['data'] = $response;
            } else {
                $result['message'] = $response;
            }
        } catch (NoSuchEntityException $noSuchEntityException) {
            $result['message'] = $noSuchEntityException->getMessage();
        }
        return $result;
    }

    /**
     * @param string $orderId
     * @return OrderInterface
     */
    public function approveOrder($orderId)
    {
        $order = $this->orderRepository->get($orderId);
        $order = $this->decreasingStockAfterPaymentSuccess($order);
        if ($this->invoiceOrder($order)) {
            $order->setStatus($this->aplazoHelper->getApprovedOrderStatus());
            $order->setState(Order::STATE_PROCESSING);
            return $this->saveOrder($order);
        }
        return $order;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return bool
     */
    public function invoiceOrder($order)
    {
        if ($order->canInvoice()) {
            try{
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $transaction = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transaction->save();
            } catch (\Exception $e){
                $this->aplazoHelper->log('Error al crear el invoice de la orden ' . $order->getIncrementId() . ' mensaje de error > ' . $e->getMessage());
                return false;
            }
            return true;
        }
    }

    /**
     * @param string $orderId
     * @return array
     */
    public function cancelOrder($orderId)
    {
        $result = ['success' => false, 'message' => ''];
        try {
            /**
             * @var OrderInterface $order
             */
            $order = $this->orderRepository->get($orderId);
            if ($this->aplazoHelper->canCancelOnFailure()) {
                if ($order->canCancel()) {
                    $order = $this->decreasingStockAfterPaymentSuccess($order);
                    $order->cancel();
                    $result['success'] = true;
                    $result['message'] = __("Order successfully canceled");
                } else {
                    if ($order->isCanceled()) {
                        $result['success'] = true;
                        $result['message'] = __("Order already canceled");
                    } else {
                        $result['message'] = __("Can not cancel this orden");
                    }
                }
            } else {
                $result['message'] = __('Order not canceled due to module configurations.');
            }
            $order->addCommentToStatusHistory(__('Aplazo info: ') . $result['message']);
            $this->saveOrder($order);
            $result['quote_id'] = $order->getQuoteId();
        } catch (NoSuchEntityException $noSuchEntityException) {
            $result['message'] = $noSuchEntityException->getMessage();
        }
        return $result;
    }

    /**
     * @param $storeId
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getOrderToCancelCollection($storeId)
    {
        $paymentMethod = ConfigProvider::CODE;
        $collection = $this->orderCollectionFactory->create();
        $collection
            ->addFieldToFilter('main_table.store_id', ['eq' => $storeId])
            ->addFieldToFilter('main_table.status', ['eq' => $this->aplazoHelper->getNewOrderStatus()])
            ->getSelect()->join(
                ["sop" => "sales_order_payment"],
                'main_table.entity_id = sop.parent_id',
                array('method')
            )->where("sop.method = \"$paymentMethod\"");
        return $collection;
    }
}
