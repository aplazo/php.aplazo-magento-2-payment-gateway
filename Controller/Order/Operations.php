<?php
namespace Aplazo\AplazoPayment\Controller\Order;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Item\OptionFactory;
use Magento\Quote\Model\Quote\ItemFactory;
use Magento\Quote\Model\QuoteFactory;

class Operations extends \Magento\Framework\App\Action\Action
{
    const ACTION_URL = 'aplazo/order/operations';

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var AplazoService
     */
    private $aplazoService;
    /**
     * @var AplazoHelper
     */
    private $aplazoHelper;
    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;
    private $quoteFactory;
    private $itemFactory;
    private $itemOptionFactory;


    public function __construct(
        Context $context,
        OrderService $orderService,
        Session $checkoutSession,
        AplazoService $aplazoService,
        Data $aplazoHelper,
        CartRepositoryInterface $cartRepository,
        QuoteFactory $quoteFactory,
        ItemFactory $itemFactory,
        OptionFactory $itemOptionFactory,
        ResultFactory $resultFactory
    )
    {
        parent::__construct($context);
        $this->orderService = $orderService;
        $this->checkoutSession = $checkoutSession;
        $this->aplazoService = $aplazoService;
        $this->aplazoHelper = $aplazoHelper;
        $this->resultFactory = $resultFactory;
        $this->quoteFactory = $quoteFactory;
        $this->itemFactory = $itemFactory;
        $this->itemOptionFactory = $itemOptionFactory;
        $this->cartRepository = $cartRepository;
    }


    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface|string
     */
    public function execute()
    {
        $operation = $this->getRequest()->getParam('operation');
        $functionName = lcfirst(str_replace('_', '', ucwords($operation,'_')));
        if(method_exists(\Aplazo\AplazoPayment\Controller\Order\Operations::class,$functionName)) {
            $response = $this->{$functionName}();
            if (is_array($response)) {
                return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($response);
            }
            else{
                return $response;
            }
        }
        return '';
    }

    /**
     * @return array
     */
    public function purchase()
    {
        $order = $this->checkoutSession->getLastRealOrder();
        $this->orderService->reservingStockUntilPayment($order, 'aplazo_item_reserved');
        if(!empty($order->getAplazoCheckoutUrl())){
            $response = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($order->getAplazoCheckoutUrl());
        } else {
            $this->messageManager->addErrorMessage(__('Aplazo payment gateway is unavailable. Try again later.'));
            $response = $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        return $response;
    }

    public function cancel()
    {
        $incrementId = $this->getRequest()->getParam('incrementid');
        $orderArray = $this->orderService->getOrderByIncrementId($incrementId);
        if($order = $orderArray['order']){
            $this->orderService->cancelOrder($order->getId());
            $this->messageManager->addWarningMessage($this->aplazoHelper->getCancelMessage());
//            if($this->aplazoHelper->getEnableRecoverCart()){
                // Todo: Enable cart
//                $quoteId = $order->getQuoteId();
//                try {
//                    $quote = $this->cartRepository->get($quoteId);
//                    /** @var \Magento\Quote\Model\Quote $newQuote */
//                    $newQuote = $this->quoteFactory->create();
//                    $newQuote->setData($quote->getData());
//                    $newQuote->setId(null)->setIsActive(true)->save();
//                    foreach ($quote->getAllItems() as $item) {
//                        $request = $cart->getQtyRequest($product, $requestInfo);
//                        $newQuote->addProduct($product, $request);
//                        /** @var \Magento\Quote\Model\Quote\Item $itemModel */
//                        $itemModel = $this->itemFactory->create();
//                        $itemModel->setData($item->getData())->setId(null);
//                        $itemModel->setQuoteId($newQuote->getId());
//                        $itemModel->save();
//                        $newQuote->addItem($itemModel);
//                    }
//                    $newQuote->collectTotals()->save();
//                    $this->cartRepository->save($newQuote);
//
//                    // sacado de this cart save
//                    $this->getQuote()->getBillingAddress();
//                    $this->getQuote()->getShippingAddress()->setCollectShippingRates(true);
//                    $this->getQuote()->collectTotals();
//                    $this->quoteRepository->save($this->getQuote());
//                    $this->_checkoutSession->setQuoteId($this->getQuote()->getId());
//
//                    $this->checkoutSession->setQuoteId($newQuote->getId());
//                    $this->checkoutSession->setLastQuoteId($newQuote->getId());
//                    $this->checkoutSession->setLastSuccessQuoteId($newQuote->getId());
//                } catch (\Exception $e) {
//                    $this->aplazoService->sendLog('No se pudo crear el quote de ' . $incrementId, AplazoHelper::LOGS_CATEGORY_WARNING, AplazoHelper::LOGS_SUBCATEGORY_ORDER,
//                        ['method' => 'cancel', 'class' => '\Aplazo\AplazoPayment\Controller\Order\Operations', 'error' => $e->getMessage()]);
//                    $this->messageManager->addWarningMessage(__('We cannot retrieve the products of your order. Please add them again to the cart'));
//                }
//            }
        } else {
            $this->aplazoService->sendLog('No se pudo obtener la orden id ' . $incrementId . ', se procede ir a carrito', AplazoHelper::LOGS_CATEGORY_WARNING, AplazoHelper::LOGS_SUBCATEGORY_ORDER,
            ['method' => 'cancel', 'class' => '\Aplazo\AplazoPayment\Controller\Order\Operations']);
        }
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }

    public function redirectToOnepage()
    {
        $orderid = $this->getRequest()->getParam('orderid');
        $onepage = $this->getRequest()->getParam('onepage');
        $url = 'checkout/onepage/failure/';
        if($orderid){
            $response = $this->orderService->getQuoteIdByOrderId($orderid);
            if($response['success']){
                if(isset($response['quote_id'])){
                    if($onepage == 'success') {
                        $this->checkoutSession->setLastSuccessQuoteId($response['quote_id']);
                        $url = 'checkout/onepage/success/';
                    }
                    $this->checkoutSession->setLastQuoteId($response['quote_id']);
                    $this->checkoutSession->setLastOrderId($response['order_id']);
                }
            }
        }
        return $this->resultRedirectFactory->create()->setPath($url);
    }
}
