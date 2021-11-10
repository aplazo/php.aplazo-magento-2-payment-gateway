<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class Success extends Action  implements CsrfAwareActionInterface{
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
		TransactionFactory $transactionFactory
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

		parent::__construct($context);
	}

	/**
	 * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|\Magento\Framework\View\Result\Layout
	 */
	public function execute() {
		$result = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
		try {
			$result->setUrl('/aplazopayment/index/successpage');
			return $result;	
		} catch (\Exception $e) {
			$this->_logger->debug($e->getMessage());
			$result->setUrl('/checkout/cart');
			return $result;
		}
	}

	/**
	 * @param RequestInterface $request
	 * @return InvalidRequestException|null
	 */
	public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
} 