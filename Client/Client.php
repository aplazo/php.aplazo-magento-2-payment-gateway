<?php

namespace Aplazo\AplazoPayment\Client;

use Aplazo\AplazoPayment\Model\Config;
use Magento\Catalog\Helper\ImageFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Newsletter\Model\Subscriber;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Aplazo\AplazoPayment\Logger\Logger as AplazoLogger;

class Client {

	/**
	 * @var StoreManagerInterface
	 */
	protected $storeManager;

	/**
	 * @var ScopeConfigInterface
	 */
	protected $scopeConfig;

	/**
	 * @var AplazoLogger
	 */
	protected $logger;

	/**
	 * @var ImageFactory
	 */
	protected $imageHelperFactory;

	/**
	 * @var mixed
	 */
	protected $domain;

	/**
	 * @var mixed
	 */
	protected $user;

	/**
	 * @var mixed
	 */
	protected $password;

	/**
	 * @var mixed
	 */
	protected $token;

	/**
	 * @var \Magento\Framework\Message\ManagerInterface
	 */
	protected $messageManager;

	/**
	 * @var array
	 */
	public $endpoints = [
		"auth" => "api/auth",
		"create" => "api/loan",
	];

	/**
	 * @var
	 */
	public $probe;

	/**
	 * @var Subscriber
	 */
	protected $subscriber;

	/**
	 * @var
	 */
	protected $addressRepository;

	/**
	 * @var Config
	 */
	protected $config;

	/**
	 * @var Curl
	 */
	protected $curl;

	private $objectManager;

	/**
     * Logging instance
     * @var Aplazo\AplazoPayment\Logger\Logger
     */
    protected $aplazoLogger;

	/**
	 * Client constructor.
	 * @param Config $config
	 * @param Curl $curl
	 * @param LoggerInterface $logger
	 * @param StoreManagerInterface $storeManager
	 * @param ManagerInterface $messageManager
	 * @param ImageFactory $imageHelperFactory
	 */
	public function __construct(
		AplazoLogger $aplazoLogger,
		Config $config,
		Curl $curl,
		//LoggerInterface $logger,
		StoreManagerInterface $storeManager,
		ManagerInterface $messageManager,
		ImageFactory $imageHelperFactory,
		ObjectManagerInterface $objectManager
	) {
		$this->storeManager = $storeManager;
		$this->config = $config;
		$this->curl = $curl;
		$this->logger = $aplazoLogger;
		$this->messageManager = $messageManager;
		$this->imageHelperFactory = $imageHelperFactory;
		$this->domain = $this->config->getBaseApiUrl();
		$this->objectManager = $objectManager;
		//$this->aplazoLogger = $aplazoLogger;
	}

	public function auth() {
		$enableLog = $this->config->getEnableLog();
		$url = $this->makeUrl("auth");
		
		$body = [
			"apiToken" => $this->config->getApiToken(),
			"merchantId" => $this->config->getMerchantId(),
		];
		$payload = json_encode($body);
		if($enableLog){
			$this->logger->info('====Auth Request===');
			$this->logger->info($payload);
		}
		$this->curl->setHeaders(['Content-Type' => 'application/json']);
		$this->curl->post($url, $payload);
		$result = $this->curl->getBody();
		if($enableLog){
			$this->logger->info('====Auth Response===');
			$this->logger->info($this->curl->getStatus());
			$this->logger->info($result);
		}
		if ($this->curl->getStatus() == 200) {
			return json_decode($result, true);
		} else {
			$response = json_decode($result, true);
			$message = $this->errorCatalog((strval($response['status'])));
			if($enableLog){
				$this->logger->info('====Auth Error===');
				$this->logger->info($message);
			}
			return array("error" => 1, "message" => $message);
		}
	}

	/**
	 * @param $authHeader
	 * @param $quote
	 * @return bool|string
	 */
	public function create($authHeader, $quote, $email) {
		$enableLog = $this->config->getEnableLog();

		$url = $this->makeUrl("create");

		$headers = $authHeader;
		$headers['Content-Type'] = 'application/json';
		$this->curl->setHeaders($headers);
		$body = $this->prepareCreateParams($quote, $email);
		$payload = json_encode($body);
		if($enableLog){
			$this->logger->info('====Create Request===');
			$this->logger->info($payload);
		}
		$this->curl->post($url, $payload);
		$result = $this->curl->getBody();
		if($enableLog){
			$this->logger->info('====Create Response===');
			$this->logger->info($result);
		}
		
		$resultDecode = json_decode($result);
		return $resultDecode;
	}

	/**
	 * @param $endpoint
	 * @return string
	 */
	protected function makeUrl($endpoint) {
		return $this->domain . $this->endpoints[$endpoint];
	}

	/**
	 * @param Quote $quote
	 * @return array
	 * @throws \Magento\Framework\Exception\NoSuchEntityException
	 */
	protected function prepareCreateParams(Quote $quote, $email) {
		$products = [];
		foreach ($quote->getAllVisibleItems() as $quoteItem) {
			if ($quoteItem->getProduct()->getTypeId() == 'configurable') {
				$childItem = $quoteItem->getChildren()[0];
				$image = $this->imageHelperFactory->create()
					->init($childItem->getProduct(), 'product_small_image')->getUrl();
			} else {
				$image = $this->imageHelperFactory->create()
					->init($quoteItem->getProduct(), 'product_small_image')->getUrl();
			}
			$productArr = [
				"count" => $quoteItem->getQty(),
				"description" => $quoteItem->getProduct()->getShortDescription(),
				"id" => $quoteItem->getProduct()->getId(),
				"imageUrl" => $image,
				"price" => $quoteItem->getPrice(),
				"title" => $quoteItem->getName(),
			];
			$products[] = $productArr;
		}
		$storeCode = $this->storeManager->getStore()->getCode();
		$webhookUrl = 'rest/'.$storeCode.'/V1/aplazopayment/updateOrder';
		return [
			"cartId" => $this->updateReservedOrderId(),
			"extOrderId" => $quote->getId(),
			"buyer" => [
				"addressLine" => $quote->getShippingAddress()->getCity(),
				"email" => $email,
				"firstName" => $quote->getShippingAddress()->getFirstname(),
				"lastName" => $quote->getShippingAddress()->getLastname(),
				"phone" => $quote->getShippingAddress()->getTelephone(),
				"postalCode" => $quote->getShippingAddress()->getPostcode(),
			],
			"discount" => [
				"price" => $quote->getShippingAddress()->getDiscountAmount(),
				"title" => $quote->getShippingAddress()->getDiscountDescription(),
			],
			"errorUrl" => $this->storeManager->getStore()->getUrl('aplazopayment/index/error'),
			"products" => $products,
			"shipping" => [
				"price" => $quote->getShippingAddress()->getShippingAmount(),
				"title" => $quote->getShippingAddress()->getShippingDescription(),
			],
			"shopId" => $this->storeManager->getStore()->getName(),
			"successUrl" => $this->storeManager->getStore()->getUrl('aplazopayment/index/successpage?orderId=' . $this->updateReservedOrderId()),
			"webHookUrl" => $this->storeManager->getStore()->getUrl($webhookUrl),
			"cartUrl" => $this->storeManager->getStore()->getUrl('checkout/cart/'),
			"taxes" => [
				"price" => $quote->getShippingAddress()->getTaxAmount(),
				"title" => __('Tax'),
			],
			"totalPrice" => $quote->getGrandTotal(),
		];
	}

	public function updateReservedOrderId() {

		$checkoutSession = $this->objectManager->create('Magento\Checkout\Model\Session');
		$resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
		$connection = $resource->getConnection();

		$quoteId = $checkoutSession->getQuoteId();

		$cartData = $this->objectManager->create('Magento\Quote\Model\QuoteRepository')->get($quoteId);

		$checkoutSession->getQuote()->reserveOrderId();
		$reservedOrderId = $checkoutSession->getQuote()->getReservedOrderId();

		$connection->query("UPDATE quote SET reserved_order_id = '$reservedOrderId' WHERE entity_id = $quoteId");

		return $reservedOrderId;
	}

	public function errorCatalog($code) {
		$catalog = array(
			"0" => "The minimum amount is 250.0",
			"404" => "Invalid Credentials",
			"500" => "Internal Server Error",
		);
		$response = $catalog[$code];
		return $response;
	}

}