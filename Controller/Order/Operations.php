<?php

namespace Aplazo\AplazoPayment\Controller\Order;

use Aplazo\AplazoPayment\Api\CheckoutNotPaidManagementInterface;
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
    private $checkoutNotPaidManagement;


    public function __construct(
        Context                            $context,
        OrderService                       $orderService,
        Session                            $checkoutSession,
        AplazoService                      $aplazoService,
        Data                               $aplazoHelper,
        CartRepositoryInterface            $cartRepository,
        QuoteFactory                       $quoteFactory,
        ItemFactory                        $itemFactory,
        OptionFactory                      $itemOptionFactory,
        ResultFactory                      $resultFactory,
        CheckoutNotPaidManagementInterface $checkoutNotPaidManagement
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
        $this->checkoutNotPaidManagement = $checkoutNotPaidManagement;
    }


    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface|string
     */
    public function execute()
    {
        $operation = $this->getRequest()->getParam('operation');
        $functionName = lcfirst(str_replace('_', '', ucwords($operation, '_')));
        if (method_exists(\Aplazo\AplazoPayment\Controller\Order\Operations::class, $functionName)) {
            $response = $this->{$functionName}();
            if (is_array($response)) {
                return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($response);
            } else {
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
        if (!empty($aplazoCheckout = $order->getAplazoCheckoutUrl())) {
            // Exploding cancel token and checkout Url
            if (strpos($aplazoCheckout, "||") !== false) {
                $aplazoCheckout = explode("||", $aplazoCheckout)[0];
            }
            $response = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($aplazoCheckout);
        } else {
            $this->messageManager->addErrorMessage(__('Aplazo payment gateway is unavailable. Try again later.'));
            $response = $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        return $response;
    }

    public function cancel()
    {
        $incrementId = $this->getRequest()->getParam('incrementid');
        $token = $this->getRequest()->getParam('token');
        $result = $this->orderService->getOrderByIncrementId($incrementId);
        if (!empty($aplazoCheckout = $result['order']->getAplazoCheckoutUrl())) {
            if (strpos($aplazoCheckout, "||") !== false) {
                $aplazoCheckout = explode("||", $aplazoCheckout)[1];
                if ($token == $aplazoCheckout){
                    // Exploding cancel token and checkout Url
                    $response = $this->checkoutNotPaidManagement->postCheckoutNotPaid($incrementId);
                    if (!empty($response['message'])) {
                        $this->messageManager->addWarningMessage($response['message']);
                    }
                }
            }

        }
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }

    public function redirectToOnepage()
    {
        $orderid = $this->getRequest()->getParam('orderid');
        $onepage = $this->getRequest()->getParam('onepage');
        $url = 'checkout/onepage/failure/';
        if ($orderid) {
            $response = $this->orderService->getQuoteIdByOrderId($orderid);
            if ($response['success']) {
                if (isset($response['quote_id'])) {
                    if ($onepage == 'success') {
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
