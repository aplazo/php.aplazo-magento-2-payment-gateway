<?php
namespace Aplazo\AplazoPayment\Model\Api;

use Aplazo\AplazoPayment\Helper\Data;
use Aplazo\AplazoPayment\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Webapi\Exception;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\InvoiceService;

use Aplazo\AplazoPayment\Logger\Logger as AplazoLogger;

class AplazoOrder
{

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
	protected $coreSession;

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

    protected $response;

    const HTTP_INTERNAL_ERROR = \Magento\Framework\Webapi\Exception::HTTP_INTERNAL_ERROR;
    const HTTP_UNAUTHORIZED = \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED;
    const HTTP_FORBIDDEN = \Magento\Framework\Webapi\Exception::HTTP_FORBIDDEN;
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


    protected $logger;
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
        \Magento\Framework\Webapi\Rest\Request $request
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


        $this->response = $response;
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

		$version = floatval( $this->aplazoHelper->getMageVersion() );
        
        if( $currentToken != $merchantApiToken ){
            $dataResponse = [
                'code' => 401,
                'message' => 'Authentication Failed'
            ];
            $this->sendResponse($dataResponse, self::HTTP_UNAUTHORIZED);
        }

        if ($status == "Activo") {

			try {
                $this->setOrderId($extOrderId);
                $this->setIncrementId($cartId);
                $this->setLoanId($loanId);
                $quote = $this->_quoteRepository->get(intval($extOrderId));

				//$quote->setPaymentMethod('aplazo_payment');
				$quote->getPayment()->importData(['method' => 'aplazo_payment']);
                
                
                if ($quote->getCustomer()->getId() == "") {
					$quote->setCustomerIsGuest(true);
					$quote->setCustomerEmail($quote->getCustomerEmail())
						->setCustomerFirstname($quote->getBillingAddress()->getFirstName())
						->setCustomerLastname($quote->getBillingAddress()->getLastName());
					$quote->save();
				}

                //Magento version
				//Magento version
				switch( $version ){
					case 2.3:
						$order = $this->quoteManagement->submit($quote);
                		$order_id = $order->getId();
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
                

                

                if ($order->canInvoice()) {
					$invoice = $this->invoiceService->prepareInvoice($order);
					$invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
					$invoice->register();
					$transaction = $this->transactionFactory->create()
						->addObject($invoice)
						->addObject($invoice->getOrder());
					$transaction->save();
				}

                $dataResponse = array(
					'code' => 200,
					'orderId' => $order_id,
					'message' => 'The order was created successfully',
				);
                $this->sendResponse($dataResponse, self::HTTP_SUCCESS);
                
			} catch (\Exception $e) {

                $dataResponse = array(
                    'code' => 500,
                    'message' => $e->getMessage()
                );
                $this->sendResponse($dataResponse, self::HTTP_INTERNAL_ERROR);
			}
		}else{
            $dataResponse = array(
                'code' => 400,
                'message' => 'Invalid Satus'
            );
            $this->sendResponse($dataResponse, self::HTTP_BAD_REQUEST);
        }


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
        $response->setContent(json_encode($dataResponse)
        );

        $response->send();
        die;
    }
}