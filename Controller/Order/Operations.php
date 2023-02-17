<?php
namespace Aplazo\AplazoPayment\Controller\Order;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Aplazo\AplazoPayment\Model\Service\OrderService;

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

    public function __construct(
        Context $context,
        OrderService $orderService,
        Session $checkoutSession,
        ResultFactory $resultFactory
    )
    {
        parent::__construct($context);
        $this->orderService = $orderService;
        $this->checkoutSession = $checkoutSession;
        $this->resultFactory = $resultFactory;
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
    public function purchase(){
        $response = ['success' => false, 'url' => '', 'message' => ''];
        $quoteId = $this->getRequest()->getParam('quoteid');
        if($quoteId){
            $result = $this->orderService->purchaseAction($quoteId);
            if($result['success']){
                $response = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl($result['data']['url']);
            }
            else{
                if(array_key_exists('message',$result)){
                    $this->messageManager->addErrorMessage($result['message']);
                }
                $response = $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }
        }
        return $response;
    }

    public function redirectToOnepage(){
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
                    $this->checkoutSession->setLastOrderId($orderid);
                }
            }
        }
        return $this->resultRedirectFactory->create()->setPath($url);
    }

    /**
     * @return array
     */
    public function cancelOrder()
    {
        $response = ['success' => false, 'url' => '', 'message' => ''];
        $orderid = $this->getRequest()->getParam('orderid');
        if($orderid){
            $response = $this->orderService->cancelOrder($orderid);
            if(isset($response['quote_id'])) {
                $this->checkoutSession->setLastQuoteId($response['quote_id']);
                $this->checkoutSession->setLastOrderId($orderid);
            }
            if($response['success']){
                $response['url'] = 'checkout/onepage/failure/';
            }
        }
        return $response;
    }
}
