<?php
namespace Aplazo\AplazoPayment\Model\Api;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Model\AplazoSaveOrder;
use Aplazo\AplazoPayment\Model\Config;
use Aplazo\AplazoPayment\Model\Data\Sale;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

use Aplazo\AplazoPayment\Logger\Logger as AplazoLogger;

class AplazoOrder
{

    const APLAZO_PAYMENT_METHOD_CODE = 'aplazo_payment';
    const PARAM_NAME_TOKEN = 'token';

	/**
	 * @var RedirectFactory
	 */
	protected $_redirectFactory;

	/**
	 * @var JsonFactory
	 */
	protected $_jsonFactory;

	/**
	 * @var CheckoutSession
	 */
	protected $_checkoutSession;

	/**
	 * @var CartRepositoryInterface
	 */
	protected $_quoteRepository;

	/**
	 *  @var CartManagementInterface
	 */
	protected $cartManagementInterface;
	/**
	 * @var QuoteFactory
	 */
	protected $_quoteFactory;

	/**
	 * @var LoggerInterface
	 */
	protected $_logger;

	/**
	 * @var QuoteManagement
	 */
	protected $quoteManagement;

	/**
	 * @var InvoiceService
	 */
	protected $invoiceService;

	/**
	 * @var TransactionFactory
	 */
	protected $transactionFactory;

	/**
	 * @var SessionManagerInterface
	 */
	protected $_coreSession;

	/**
	 * @var Http
	 */
	protected $http;

	/**
	 * @var Data
	 */
	protected $aplazoHelper;

	/**
	 * @var Order
	 */
	protected $orderModel;

	/**
	 * @var OrderRepositoryInterface
	 */
	protected $orderRepository;
    /**
	 * @var Config
	 */
	protected $config;

    /**
     * @var AplazoSaveOrder
     */
    protected $aplazoSaveOrder;
    protected $searchCriteriaBuilder;

    protected $logger;
    protected $response;

    const HTTP_INTERNAL_ERROR = \Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR;
    const HTTP_UNAUTHORIZED = \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED;
    const HTTP_BAD_REQUEST = \Magento\Framework\Webapi\Exception::HTTP_BAD_REQUEST;
    const HTTP_SUCCESS = 200;

	/**
	 * Success constructor.
	 * @param Context $context
	 * @param ResultFactory $resultFactory
	 * @param RedirectFactory $redirectFactory
	 * @param JsonFactory $jsonFactory
	 * @param CheckoutSession $checkoutSession
	 * @param CartRepositoryInterface $quoteRepository
	 * @param CartManagementInterface $cartManagementInterface
	 * @param QuoteFactory $quoteFactory
	 * @param LoggerInterface $logger
	 * @param QuoteManagement $quoteManagement
	 * @param InvoiceService $invoiceService
	 * @param TransactionFactory $transactionFactory
	 * @param SessionManagerInterface $coreSession
	 * @param Data $aplazoHelper
	 * @param OrderModel $orderModel
	 * @param OrderRepositoryInterface $orderRepository
	 */


    public function __construct(
        AplazoLogger $logger,
        Config $config,
        Context $context,
		ResultFactory $resultFactory,
		RedirectFactory $redirectFactory,
		JsonFactory $jsonFactory,
		CheckoutSession $checkoutSession,
		CartRepositoryInterface $quoteRepository,
		CartManagementInterface $cartManagementInterface,
		QuoteFactory $quoteFactory,
		QuoteManagement $quoteManagement,
		InvoiceService $invoiceService,
		TransactionFactory $transactionFactory,
		SessionManagerInterface $coreSession,
		Http $http,
		Data $aplazoHelper,
		Order $orderModel,
		OrderRepositoryInterface $orderRepository,
        ResponseInterface $response,
        \Magento\Framework\Webapi\Rest\Request $request,
        AplazoSaveOrder $aplazoSaveOrder,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    )
    {
        $this->logger = $logger;
        $this->config = $config;

        $this->_quoteFactory = $quoteFactory;
		$this->_quoteRepository = $quoteRepository;
		$this->_checkoutSession = $checkoutSession;
		$this->resultFactory = $resultFactory;
		$this->_jsonFactory = $jsonFactory;
		$this->_redirectFactory = $redirectFactory;
		$this->quoteManagement = $quoteManagement;
		$this->cartManagementInterface = $cartManagementInterface;
		$this->invoiceService = $invoiceService;
		$this->transactionFactory = $transactionFactory;
		$this->_coreSession = $coreSession;
		$this->http = $http;
		$this->aplazoHelper = $aplazoHelper;
		$this->orderModel = $orderModel;
		$this->orderRepository = $orderRepository;
        $this->aplazoSaveOrder = $aplazoSaveOrder;
        $this->response = $response;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * @inheritdoc
     */
    public function updateOrder(
                                $status,
                                $loanId,
                                $cartId,
                                $extOrderId,
                                $merchantApiToken
                            )
    {
        $currentToken = $this->config->getApiToken();

        if( $currentToken != $merchantApiToken ){
            $dataResponse = [
                'code' => 401,
                'message' => 'Authentication Failed'
            ];
            $this->aplazoSaveOrder->updateAplazoOrder(intval($extOrderId), Sale::STATUS_ERROR, $dataResponse['message'], $loanId);
            $this->sendResponse($dataResponse, self::HTTP_UNAUTHORIZED);
            return;
        }

        if ($status == "Activo") {
			$dataResponse = $this->submitOrder($extOrderId, $cartId, $loanId);
            $this->aplazoSaveOrder->updateAplazoOrder(intval($extOrderId), $dataResponse['status'], $dataResponse['response']['message'], $loanId);
            $this->sendResponse($dataResponse['response'], self::HTTP_SUCCESS);
		}else{
            $dataResponse = array(
                'code' => 400,
                'message' => 'Invalid Status'
            );
            $this->aplazoSaveOrder->updateAplazoOrder(intval($extOrderId), Sale::STATUS_ERROR, $dataResponse['message'], $loanId);
            $this->sendResponse($dataResponse, self::HTTP_BAD_REQUEST);
        }
   }

   public function submitOrder($extOrderId, $cartId, $loanId)
   {
       try {
           $this->setOrderId($extOrderId);
           $this->setIncrementId($cartId);
           $this->setLoanId($loanId);

           if(!($order = $this->isOrderCreatedBefore($cartId))){
               $quote = $this->_quoteRepository->get(intval($extOrderId));
               $quote->getPayment()->importData(['method' => self::APLAZO_PAYMENT_METHOD_CODE]);

               if ($quote->getCustomer()->getId() == "") {
                   $quote->setCustomerIsGuest(true);
                   $quote->setCustomerEmail($quote->getCustomerEmail())
                       ->setCustomerFirstname($quote->getBillingAddress()->getFirstName())
                       ->setCustomerLastname($quote->getBillingAddress()->getLastName());
                   $quote->save();
               }

               //Magento version
               $version = floatval( $this->aplazoHelper->getMageVersion() );
               switch( $version ){
                   case 2.3:
                       $order = $this->quoteManagement->submit($quote);
                       break;
                   case 2.4:
                       $order_id = $this->cartManagementInterface->placeOrder($quote->getId());
                       $order = $this->orderRepository->get($order_id);
                       break;
                   default:
                       $order_id = $this->cartManagementInterface->placeOrder($quote->getId());
                       $order = $this->orderRepository->get($order_id);
                       break;
               }
           }

           $this->invoiceOrder($order);

           return array(
               'response' => [
                   'code' => 200,
                   'orderId' => $order->getId(),
                   'message' => 'The order was created successfully'
               ],
               'status' => Sale::STATUS_PROCESSING
           );
       } catch (\Exception $e) {
           return array(
               'response' => [
                   'code' => 200, // If 500, aplazo loan goes from Paid to Pending
                   'message' => $e->getMessage()
               ],
               'status' => Sale::STATUS_ERROR
           );
       }
   }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
   public function invoiceOrder($order)
   {
       if ($order->canInvoice()) {
           $invoice = $this->invoiceService->prepareInvoice($order);
           $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
           $invoice->register();
           $transaction = $this->transactionFactory->create()
               ->addObject($invoice)
               ->addObject($invoice->getOrder());
           $transaction->save();
       }
   }

   public function isOrderCreatedBefore($reservedIncrementId)
   {
       $searchCriteria = $this->searchCriteriaBuilder
           ->addFilter('increment_id', $reservedIncrementId)->create();
       $orderList = $this->orderRepository->getList($searchCriteria)->getItems();
       foreach($orderList as $order){
           if($order->getPayment()->getMethod() == self::APLAZO_PAYMENT_METHOD_CODE){
               return $order;
           }
       }
       return false;
   }

   /**
	 * @param OrderId
	 */
	public function setOrderId($orderId) {
		$this->_coreSession->start();
		$this->_coreSession->setOrderId($orderId);
	}

	/**
	 * @param IncrementId
	 */
	public function setIncrementId($incrementId) {
		$this->_coreSession->start();
		$this->_coreSession->setIncrementId($incrementId);
	}

	/**
	 * @param LoanId
	 */
	public function setLoanId($loanId) {
		$this->_coreSession->start();
		$this->_coreSession->setLoanId($loanId);
	}

    public function sendResponse($dataResponse, $httpResponseCode){
        $response = $this->response;
        $response->setHttpResponseCode($httpResponseCode);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode($dataResponse));

        $response->send();
        die;
    }
}
