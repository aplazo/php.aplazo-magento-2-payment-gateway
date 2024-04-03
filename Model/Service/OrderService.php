<?php

namespace Aplazo\AplazoPayment\Model\Service;

use Aplazo\AplazoPayment\Helper\Data;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use \Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Aplazo\AplazoPayment\Observer\DataAssignObserver;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Sales\Model\Service\InvoiceService;

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
     * @var CartRepositoryInterface
     */
    private $quoteRepository;
    /**
     * @var Manager
     */
    private $manager;

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param AplazoService $aplazoService
     * @param AplazoHelper $aplazoHelper
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param CartRepositoryInterface $quoteRepository
     * @param Manager $manager
     */
    public function __construct
    (
        OrderCollectionFactory              $orderCollectionFactory,
        OrderRepositoryInterface            $orderRepository,
        AplazoService                       $aplazoService,
        AplazoHelper                        $aplazoHelper,
        MaskedQuoteIdToQuoteIdInterface     $maskedQuoteIdToQuoteId,
        InvoiceService                      $invoiceService,
        TransactionFactory                  $transactionFactory,
        CartRepositoryInterface             $quoteRepository,
        Manager                             $manager
    )
    {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->quoteRepository = $quoteRepository;
        $this->manager = $manager;
    }

    /**
     * @param Order $order
     * @return void
     */
    public function reservingStockUntilPayment($order, $type = SalesEventInterface::EVENT_ORDER_PLACED)
    {
        if ($this->aplazoHelper->getReserveStock()) {
            try {
                $this->isMsiOrInventory($order, true, 'aplazo_item_reserved');
            } catch (\Exception $e) {
                $message = 'Webhook error inventory: Al crear pedido no se recupero la reserva de stock. Increment_id ' . $order->getIncrementId();
                $this->aplazoHelper->log($message);
                $this->aplazoHelper->log($e->getMessage());
                $this->aplazoService->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_ORDER, ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param Order $order
     */
    public function decreasingStockAfterPaymentSuccess($order, $type = SalesEventInterface::EVENT_ORDER_CANCELED)
    {
        if ($this->aplazoHelper->getReserveStock()) {
            try {
                $this->isMsiOrInventory($order, false, $type);
            } catch (\Exception $e) {
                $message = 'Webhook error inventory: Al crear invoice no se pudo reservar stock. Increment_id ' . $order->getIncrementId();
                $this->aplazoHelper->log($message);
                $this->aplazoHelper->log($e->getMessage());
                $this->aplazoService->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR,AplazoHelper::LOGS_SUBCATEGORY_ORDER, ['error' => $e->getMessage()]);
            }
        }
    }

    public function isMsiOrInventory($order, $plus, $type = ''){
        if($this->manager->isEnabled("Magento_Inventory") && $this->manager->isEnabled("Magento_InventoryCatalogApi")){
            try{
                /** @var \Aplazo\AplazoPayment\Model\Product\MSIStock $msiStock */
                $msiStock = \Magento\Framework\App\ObjectManager::getInstance()->get(\Aplazo\AplazoPayment\Model\Product\MSIStock::class);
                $msiStock->updateQty($order, $plus, $type);
            } catch (\Exception $e){
                /** @var \Aplazo\AplazoPayment\Model\Product\MSIStock $msiStock */
                $inventoryStock = \Magento\Framework\App\ObjectManager::getInstance()->get(\Aplazo\AplazoPayment\Model\Product\InventoryStock::class);
                $inventoryStock->updateQtyNotMSI($order, $plus);
            }
        } else {
            /** @var \Aplazo\AplazoPayment\Model\Product\MSIStock $msiStock */
            $inventoryStock = \Magento\Framework\App\ObjectManager::getInstance()->get(\Aplazo\AplazoPayment\Model\Product\InventoryStock::class);
            $inventoryStock->updateQtyNotMSI($order, $plus);
        }
    }

    /**
     * @param $orderId
     * @return array
     */
    public function getQuoteIdByOrderId($orderId)
    {
        $result = $this->getOrderByAttribute(OrderInterface::INCREMENT_ID, $orderId, false);
        if ($result['success']) {
            $order = $result['order'];
            unset($result['order']);
            $result['quote_id'] = $order->getQuoteId();
            $result['order_id'] = $order->getEntityId();
        }
        return $result;
    }

    /**
     * @param $incrementId
     * @return array
     */
    public function getOrderByIncrementId($incrementId)
    {
        return $this->getOrderByAttribute(OrderInterface::INCREMENT_ID, $incrementId, false);
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
            $this->aplazoService->sendLog('getOrderByAttribute failed: ' . $exception->getMessage(), AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_ORDER);
        }
        return $result;
    }

    /**
     * @param OrderInterface $order
     * @return array
     */
    public function createLoan($order)
    {
        $result = ['success' => false, 'data' => '', 'message' => ''];
        try {
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
            $cartUrl = $this->aplazoHelper->getCancelActive() ? $this->aplazoHelper->getUrl('aplazo/order/operations',
                ['operation' => 'cancel', 'incrementid' => $order->getIncrementId()]) : $this->aplazoHelper->getUrl('checkout/cart');
            $orderData = [
                "buyer" => [
                    "addressLine" => trim($billingAddress->getStreetLine(1) . ' ' . $billingAddress->getStreetLine(2)),
                    "email" => $billingAddress->getEmail(),
                    "firstName" => $billingAddress->getFirstname(),
                    "lastName" => $billingAddress->getLastname(),
                    "phone" => $billingAddress->getTelephone(),
                    "postalCode" => $billingAddress->getPostcode()
                ],
                "cartId" => $order->getIncrementId(),
                "cartUrl" => $cartUrl,
                "discount" => [
                    "price" => abs($order->getBaseDiscountAmount()),
                    "title" => $order->getShippingAddress()->getDiscountDescription()
                ],
                "errorUrl" => $this->aplazoHelper->getUrl('aplazo/order/operations', ['operation' => 'redirect_to_onepage', 'onepage' => 'failure', 'orderid' => $order->getIncrementId()]),
                "products" => $products,
                "shipping" => [
                    "price" => abs($order->getBaseShippingAmount()),
                    "title" => $shippingMethod
                ],
                "shopId" => $order->getIncrementId(),
                "successUrl" => $this->aplazoHelper->getUrl('aplazo/order/operations', ['operation' => 'redirect_to_onepage', 'onepage' => 'success', 'orderid' => $order->getIncrementId()]),
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
        } catch (\Exception $exception) {
            $result['message'] = $exception->getMessage();
            $this->aplazoService->sendLog('createLoan error ' . $exception->getMessage(), AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_LOAN);
        }
        return $result;
    }

    /**
     * @param string $orderId
     * @return OrderInterface[]
     */
    public function approveOrder($orderId)
    {
        $order = $this->orderRepository->get($orderId);
        $message = '';
        $this->decreasingStockAfterPaymentSuccess($order, 'order_placed_aplazo');
        if (!$this->invoiceOrder($order)) {
            $message = 'Orden no se puede hacer invoice ' . $order->getIncrementId();
            $this->aplazoHelper->log($message);
            $this->aplazoService->sendLog($message, Data::LOGS_CATEGORY_ERROR, Data::LOGS_SUBCATEGORY_ORDER, $this->aplazoService->getOrderImportantDataToLog($order));
            $order->setStatus(AplazoHelper::APLAZO_WEBHOOK_RECEIVED);
        } else {
            $order->setStatus($this->aplazoHelper->getApprovedOrderStatus());
        }
        $order->setState(Order::STATE_PROCESSING);

        return [
            'order' => $this->saveOrder($order),
            'message' => $message
        ];
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
                $message = 'Error al crear el invoice de la orden ' . $order->getIncrementId() . ' mensaje de error > ' . $e->getMessage();
                $this->aplazoHelper->log($message);
                $this->aplazoService->sendLog($message, AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_ORDER);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * @param string $orderId
     * @return array
     */
    public function cancelOrder($orderId)
    {
        $result = ['success' => false, 'message' => ''];
        try {
            $order = $this->orderRepository->get($orderId);
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
            $order->addCommentToStatusHistory(__('Aplazo info: ') . $result['message']);
            $this->saveOrder($order);
            $result['quote_id'] = $order->getQuoteId();
        } catch (NoSuchEntityException $entityException) {
            $result['message'] = $entityException->getMessage();
            $this->aplazoService->sendLog("Order id not found $orderId: " . $entityException->getMessage(), AplazoHelper::LOGS_CATEGORY_ERROR, AplazoHelper::LOGS_SUBCATEGORY_ORDER);
        } catch (\Exception $exception) {
            $result['message'] = $exception->getMessage();
            $order->setStatus(AplazoHelper::APLAZO_ORDER_CANCELLED);
            $this->saveOrder($order);
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
            ->addFieldToFilter(['main_table.status','main_table.status'],[['eq'=>$this->aplazoHelper->getNewOrderStatus()],['eq'=>AplazoHelper::APLAZO_WEBHOOK_RECEIVED]])
            ->addFieldToFilter('main_table.created_at', ['lt' => $limitTime])
            ->getSelect()->join(
                ["sop" => "sales_order_payment"],
                'main_table.entity_id = sop.parent_id',
                array('method')
            )->where("sop.method = \"$paymentMethod\"");
        $collection->setOrder('entity_id', 'DESC');
        return $collection;
    }
}
