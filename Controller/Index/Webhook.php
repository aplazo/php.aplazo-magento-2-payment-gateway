<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Aplazo\AplazoPayment\Helper\Data;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
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
use Psr\Log\LoggerInterface;

class Webhook extends Action implements HttpPostActionInterface, CsrfAwareActionInterface {
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
		Context $context,
		ResultFactory $resultFactory,
		RedirectFactory $redirectFactory,
		JsonFactory $jsonFactory,
		CheckoutSession $checkoutSession,
		CartRepositoryInterface $quoteRepository,
		CartManagementInterface $cartManagementInterface,
		QuoteFactory $quoteFactory,
		LoggerInterface $logger,
		QuoteManagement $quoteManagement,
		InvoiceService $invoiceService,
		TransactionFactory $transactionFactory,
		SessionManagerInterface $coreSession,
		Http $http,
		Data $aplazoHelper,
		Order $orderModel,
		OrderRepositoryInterface $orderRepository
	) {
		$this->_logger = $logger;
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
		parent::__construct($context);
	}

	/**
	 * @return
	 * Request Post Data
	 */
	public function getPost() {
		return $this->http->getPost();
	}

	/**
	 * @return
	 * Response IF order is correct CODE 200 ELSE CODE 500
	 */
	public function execute() {
		$entityBody = file_get_contents('php://input');
		$params = json_decode($entityBody, true);
		if ($params['status'] == "Activo") {
			try {
				$this->setOrderId($params['extOrderId']);
				$this->setIncrementId($params['cartId']);
				$this->setLoanId($params['loanId']);
				$quote = $this->_quoteRepository->get(intval($params['extOrderId']));
				$quote->setPaymentMethod('aplazo_payment');
				if ($quote->getCustomer()->getId() == "") {
					$quote->setCustomerIsGuest(true);
					$quote->setCustomerEmail($quote->getCustomerEmail())
						->setCustomerFirstname($quote->getBillingAddress()->getFirstName())
						->setCustomerLastname($quote->getBillingAddress()->getLastName());
					$quote->save();
				}
				$order_id = $this->cartManagementInterface->placeOrder($quote->getId());
				$order = $this->orderRepository->get($order_id);
				if ($order->canInvoice()) {
					$invoice = $this->invoiceService->prepareInvoice($order);
					$invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
					$invoice->register();
					$transaction = $this->transactionFactory->create()
						->addObject($invoice)
						->addObject($invoice->getOrder());
					$transaction->save();
				}
				$response_body = array(
					'code' => 200,
					'orderId' => $order_id,
					'message' => 'The order was created successfully',
				);
				$response = json_encode($response_body, JSON_PRETTY_PRINT);
				echo $response;
				header("Content-type:application/json");
			} catch (\Exception $e) {
				$response_body = array(
					'code' => 500,
					'message' => $e->getMessage(),
				);
				$response = json_encode($response_body, JSON_PRETTY_PRINT);
				http_response_code(500);
				header("HTTP/1.1 500 Internal Server Error");
				header("code : 500");
				echo $response;
				header("Content-type:application/json");
			}
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

	public function createCsrfValidationException(RequestInterface $request):  ? InvalidRequestException {
		return null;
	}

	public function validateForCsrf(RequestInterface $request) :  ? bool {
		return true;
	}
}