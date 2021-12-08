<?php

namespace Aplazo\AplazoPayment\Controller\Index;

use Aplazo\AplazoPayment\Client\Client;
use Aplazo\AplazoPayment\Helper\Data;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\SessionFactory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;

class Onplaceorder extends Action {
	/**
	 * @var Client
	 */
	protected $client;

	/**
	 * @var LoggerInterface
	 */
	protected $_logger;

	/**
	 * @var Http
	 */
	protected $http;

	/**
	 * @var CheckoutSession
	 */
	protected $_checkoutSession;

	/**
	 * @var SessionFactory
	 */
	protected $_session;

	/**
	 * @var CustomerRepositoryInterface
	 */
	protected $customerRepository;

	/**
	 * @var QuoteManagement
	 */
	protected $quoteManagement;

	/**
	 * @var Data
	 */
	protected $aplazoHelper;

	/**
	 * Transaction constructor.
	 * @param Context $context
	 * @param CheckoutSession $checkoutSession
	 * @param Http $http
	 * @param SessionFactory $_session
	 * @param CustomerRepositoryInterface $customerRepository
	 * @param LoggerInterface $logger
	 * @param Client $client
	 * @param QuoteManagement $quoteManagement
	 * @param Data $aplazoHelper
	 */
	public function __construct(
		Context $context,
		CheckoutSession $checkoutSession,
		LoggerInterface $logger,
		Http $http,
		Client $client,
		QuoteManagement $quoteManagement,
		Data $aplazoHelper,
		SessionFactory $session,
		CustomerRepositoryInterface $customerRepository
	) {
		$this->_logger = $logger;
		$this->http = $http;
		$this->_checkoutSession = $checkoutSession;
		$this->client = $client;
		$this->quoteManagement = $quoteManagement;
		$this->aplazoHelper = $aplazoHelper;
		$this->_session = $session;
		$this->customerRepository = $customerRepository;
		parent::__construct($context);
	}

	/**
	 * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|\Magento\Framework\View\Result\Layout
	 */
	public function execute() {
		$email = $this->http->getParam('email');
		$resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
		$quote = $this->_checkoutSession->getQuote();
		try {
			if ($quote->getCustomer()->getId() == "") {
				$quote->setCustomerIsGuest(true);
				$quote->setCustomerEmail($email);
				$quote->save();
			}
			$auth = $this->client->auth();
			if (isset($auth['error']) && $auth['error'] == 1) {
				$data = [
					'error' => true,
					'message' => __($auth['message']),
					'transactionId' => null,
				];
			} else {
				if ($auth && is_array($auth)) {
					$result = $this->client->create($auth, $quote, $email);
					if (isset($result->error) && $result->error == 1) {
						$data = [
							'error' => true,
							'message' => __($result->message),
							'transactionId' => null,
						];
					} else {
						$redirectUrl = $result->url;
						if ($redirectUrl) {
							$data = [
								'error' => false,
								'message' => '',
								'redirecturl' => $redirectUrl,
							];
						}
					}
				}
			}
		} catch (\Exception $e) {
			$this->_logger->debug($e->getMessage());
		}
		$resultJson->setData($data);
		return $resultJson;
	}

}