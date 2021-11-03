<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Aplazo\AplazoPayment\Helper\Data;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Webapi\Exception;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class Webhook extends Action {
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
	 * @var Http
	 */
	protected $http;

	/**
	 * @var Data
	 */
	protected $aplazoHelper;

	/**
	 * Success constructor.
	 * @param Context $context
	 * @param RedirectFactory $redirectFactory
	 * @param JsonFactory $jsonFactory
	 * @param CheckoutSession $checkoutSession
	 * @param CartRepositoryInterface $quoteRepository
	 * @param QuoteFactory $quoteFactory
	 * @param LoggerInterface $logger
	 * @param QuoteManagement $quoteManagement
	 * @param InvoiceService $invoiceService
	 * @param TransactionFactory $transactionFactory
	 * @param Data $aplazoHelper
	 */
	public function __construct(
		Context $context,
		RedirectFactory $redirectFactory,
		JsonFactory $jsonFactory,
		CheckoutSession $checkoutSession,
		CartRepositoryInterface $quoteRepository,
		QuoteFactory $quoteFactory,
		LoggerInterface $logger,
		QuoteManagement $quoteManagement,
		InvoiceService $invoiceService,
		TransactionFactory $transactionFactory,
		Http $http,
		Data $aplazoHelper
	) {
		$this->_logger = $logger;
		$this->_quoteFactory = $quoteFactory;
		$this->_quoteRepository = $quoteRepository;
		$this->_checkoutSession = $checkoutSession;
		$this->_jsonFactory = $jsonFactory;
		$this->_redirectFactory = $redirectFactory;
		$this->quoteManagement = $quoteManagement;
		$this->invoiceService = $invoiceService;
		$this->transactionFactory = $transactionFactory;
		$this->http = $http;
		$this->aplazoHelper = $aplazoHelper;
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
		$this->_logger->debug('webhook');
		$params = $this->getPost();
		try {
			$quote = $this->quoteFactory->create()->load($params['extOrderId']);
			$createOrder = $this->_quoteFactory->createMageOrder($quote);
			$this->_logger->debug('toorder');
			$lastOrder = $params['cartid'];
			$this->_logger->debug($lastOrder);
			if ($lastOrder->canInvoice()) {
				$invoice = $this->invoiceService->prepareInvoice($lastOrder);
				$invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
				$invoice->register();
				$transaction = $this->transactionFactory->create()
					->addObject($invoice)
					->addObject($invoice->getOrder());
				$transaction->save();
			}
			$response_body = array(
				'code' => 200,
				'orderId' => $lastOrder,
				'message' => 'The order was created successfully',
			);
			$response = json_encode($response_body);
			return $response;
		} catch (\Exception $e) {
			$this->_logger->debug($e->getMessage());
			$response_body = array(
				'code' => 500,
				'message' => $e->getMessage(),
			);
			$response = json_encode($response_body);
			return $response;
		}
	}
}