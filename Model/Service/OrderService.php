<?php

namespace Aplazo\AplazoPayment\Model\Service;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySales\Model\CheckItemsQuantity;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
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
use Magento\Store\Api\WebsiteRepositoryInterface;

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
     * @var PlaceReservationsForSalesEventInterface
     */
    private $placeReservationsForSalesEvent;
    /**
     * @var GetSkusByProductIdsInterface
     */
    private $getSkusByProductIds;
    /**
     * @var WebsiteRepositoryInterface
     */
    private $websiteRepository;
    /**
     * @var SalesChannelInterfaceFactory
     */
    private $salesChannelFactory;
    /**
     * @var SalesEventInterfaceFactory
     */
    private $salesEventFactory;
    /**
     * @var CheckItemsQuantity
     */
    private $checkItemsQuantity;
    /**
     * @var ItemToSellInterfaceFactory
     */
    private $itemsToSellFactory;
    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;
    /**
     * @var GetProductTypesBySkusInterface
     */
    private $getProductTypesBySkus;
    /**
     * @var IsSourceItemManagementAllowedForProductTypeInterface
     */
    private $isSourceItemManagementAllowedForProductType;
    /**
     * @var SalesEventExtensionFactory
     */
    private $salesEventExtensionFactory;
    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    public function __construct
    (
        OrderCollectionFactory                               $orderCollectionFactory,
        OrderRepositoryInterface                             $orderRepository,
        AplazoService                                        $aplazoService,
        AplazoHelper                                         $aplazoHelper,
        MaskedQuoteIdToQuoteIdInterface                      $maskedQuoteIdToQuoteId,
        InvoiceService                                       $invoiceService,
        TransactionFactory                                   $transactionFactory,
        \Magento\Framework\UrlInterface                      $url,
        StockConfigurationInterface                          $stockConfiguration,
        PlaceReservationsForSalesEventInterface              $placeReservationsForSalesEvent,
        GetSkusByProductIdsInterface                         $getSkusByProductIds,
        WebsiteRepositoryInterface                           $websiteRepository,
        SalesChannelInterfaceFactory                         $salesChannelFactory,
        SalesEventInterfaceFactory                           $salesEventFactory,
        ItemToSellInterfaceFactory                           $itemsToSellFactory,
        CheckItemsQuantity                                   $checkItemsQuantity,
        StockByWebsiteIdResolverInterface                    $stockByWebsiteIdResolver,
        GetProductTypesBySkusInterface                       $getProductTypesBySkus,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        SalesEventExtensionFactory                           $salesEventExtensionFactory,
        CartRepositoryInterface           $quoteRepository
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
        $this->placeReservationsForSalesEvent = $placeReservationsForSalesEvent;
        $this->getSkusByProductIds = $getSkusByProductIds;
        $this->websiteRepository = $websiteRepository;
        $this->salesChannelFactory = $salesChannelFactory;
        $this->salesEventFactory = $salesEventFactory;
        $this->itemsToSellFactory = $itemsToSellFactory;
        $this->checkItemsQuantity = $checkItemsQuantity;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->getProductTypesBySkus = $getProductTypesBySkus;
        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
        $this->salesEventExtensionFactory = $salesEventExtensionFactory;
        $this->quoteRepository = $quoteRepository;
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
            $this->reservingStockUntilPayment($order, 'aplazo_item_reserved');
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
    public function reservingStockUntilPayment($order, $type = SalesEventInterface::EVENT_ORDER_PLACED)
    {
        if ($this->aplazoHelper->getReserveStock()) {
            try {
                $this->updateQty($order, true, $type);
            } catch (CouldNotSaveException|InputException|NoSuchEntityException|LocalizedException $e) {
                $this->aplazoHelper->log('Webhook error inventory: Al crear pedido no se recupero la reserva de stock. Increment_id ' . $order->getIncrementId());
                $this->aplazoHelper->log($e->getMessage());
            }
        }
    }

    /**
     * @param Order $order
     * @return Order
     */
    public function decreasingStockAfterPaymentSuccess($order, $type = SalesEventInterface::EVENT_ORDER_CANCELED)
    {
        if ($this->aplazoHelper->getReserveStock()) {
            try {
                $this->updateQty($order, false, $type);
            } catch (CouldNotSaveException|InputException|NoSuchEntityException|LocalizedException $e) {
                $this->aplazoHelper->log('Webhook error inventory: Al crear invoice no se pudo reservar stock. Increment_id ' . $order->getIncrementId());
                $this->aplazoHelper->log($e->getMessage());
            }
        }
        return $order;
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
        $itemsById = $itemsBySku = $itemsToSell = [];
        foreach ($order->getItems() as $item) {
            if (!isset($itemsById[$item->getProductId()])) {
                $itemsById[$item->getProductId()] = 0;
            }
            $itemsById[$item->getProductId()] += $item->getQtyOrdered();
        }
        $productSkus = $this->getSkusByProductIds->execute(array_keys($itemsById));
        $productTypes = $this->getProductTypesBySkus->execute($productSkus);

        foreach ($productSkus as $productId => $sku) {
            if (false === $this->isSourceItemManagementAllowedForProductType->execute($productTypes[$sku])) {
                continue;
            }

            $itemsBySku[$sku] = (float)$itemsById[$productId];
            if ($plus) {
                $itemsToSell[] = $this->itemsToSellFactory->create([
                    'sku' => $sku,
                    'qty' => (float)$itemsById[$productId]
                ]);
            } else {
                $itemsToSell[] = $this->itemsToSellFactory->create([
                    'sku' => $sku,
                    'qty' => -(float)$itemsById[$productId]
                ]);
            }
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
            try{
                $quote = $this->quoteRepository->get($order->getQuoteId());
                $shippingMethod = $quote->getShippingAddress()->getShippingDescription();
                $taxAmount = $quote->getShippingAddress()->getTaxAmount();
                $extOrderId = $quote->getId();
            }catch (\Magento\Framework\Exception\NoSuchEntityException $e){
                $shippingMethod = 'No info rate';
                $taxAmount = 0;
                $extOrderId = '';
            }

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
                    "title" => $order->getShippingAddress()->getDiscountDescription()
                ],
                "errorUrl" => $this->aplazoHelper->getUrl('aplazo/order/operations', ['operation' => 'redirect_to_onepage', 'onepage' => 'failure', 'orderid' => $orderId]),
                "products" => $products,
                "shipping" => [
                    "price" => abs($order->getBaseShippingAmount()),
                    "title" => $shippingMethod
                ],
                "shopId" => $order->getIncrementId(),
                "successUrl" => $this->aplazoHelper->getUrl('aplazo/order/operations', ['operation' => 'redirect_to_onepage', 'onepage' => 'success', 'orderid' => $orderId]),
                "taxes" => [
                    "price" => $taxAmount,
                    "title" => __('Tax'),
                ],
                "extOrderId" => $extOrderId,
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
        $order = $this->decreasingStockAfterPaymentSuccess($order, 'order_placed_aplazo');
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
            try {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $transaction = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transaction->save();
            } catch (\Exception $e) {
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
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getOrderToCancelCollection($minutes)
    {
        $limitTime = date('Y-m-d H:i:s', strtotime("-$minutes minutes"));
        $paymentMethod = ConfigProvider::CODE;
        $collection = $this->orderCollectionFactory->create();
        $collection
            ->addFieldToFilter('main_table.status', ['eq' => $this->aplazoHelper->getNewOrderStatus()])
            ->addFieldToFilter('main_table.created_at', ['lt' => $limitTime])
            ->getSelect()->join(
                ["sop" => "sales_order_payment"],
                'main_table.entity_id = sop.parent_id',
                array('method')
            )->where("sop.method = \"$paymentMethod\"");
        return $collection;
    }
}
