<?php

namespace Aplazo\AplazoPayment\Client;

use Magento\Framework\HTTP\Client\Curl;/*OK*/
use Magento\Quote\Model\Quote;/*OK*/
use Aplazo\AplazoPayment\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;/*OK*/
use Magento\Framework\Message\ManagerInterface;/*OK*/
use Magento\Newsletter\Model\Subscriber;/*OK*/
use Magento\Store\Model\StoreManagerInterface;/*OK*/
use Psr\Log\LoggerInterface;
use Magento\Catalog\Helper\ImageFactory;/*??*/
use Magento\Framework\ObjectManagerInterface;/*OK*/

class Client
{

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
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
     * Client constructor.
     * @param Config $config
     * @param Curl $curl
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param ManagerInterface $messageManager
     * @param ImageFactory $imageHelperFactory
     */
    public function __construct(
        Config $config,
        Curl $curl,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        ManagerInterface $messageManager,
        ImageFactory $imageHelperFactory,
        ObjectManagerInterface $objectManager
    ) {
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->imageHelperFactory = $imageHelperFactory;
        $this->domain = $this->config->getBaseApiUrl();
        $this->objectManager = $objectManager;
    }

    public function auth()
    {
        $url = $this->makeUrl("auth");

        $body = [
            "apiToken" => $this->config->getApiToken(),
            "merchantId" => $this->config->getMerchantId()
        ];
        $payload = json_encode($body);
        $this->curl->setHeaders(['Content-Type' => 'application/json']);
        $this->curl->post($url, $payload);
        $result = $this->curl->getBody();
        if ($this->curl->getStatus() == 200) {
            return json_decode($result, true);
        }
        return false;
    }

    /**
     * @param $authHeader
     * @param $quote
     * @return bool|string
     */
    public function create($authHeader, $quote)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/aplazo.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);

        $url = $this->makeUrl("create");

        $headers = $authHeader;
        $headers['Content-Type'] = 'application/json';
        $this->curl->setHeaders($headers);

        $body = $this->prepareCreateParams($quote);
        $payload = json_encode($body);
        $this->curl->post($url, $payload);

        $logger->info("Request : ". $payload);

        $result = $this->curl->getBody();
        if ($this->curl->getStatus() == 200) {
            return $result;
        }
        if ($this->curl->getStatus() == 100 && strpos($result,'https://')===0) {
            return $result;
        }
        return false;
    }

    /**
     * @param $endpoint
     * @return string
     */
    protected function makeUrl($endpoint)
    {
        return $this->domain . $this->endpoints[$endpoint];
    }

    /**
     * @param Quote $quote
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function prepareCreateParams(Quote $quote)
    {

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/aplazo.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);

        $products = [];
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            if ($quoteItem->getProduct()->getTypeId()=='configurable'){
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
                "title" => $quoteItem->getName()
            ];
            $products[] = $productArr;
        }



        $return = [
            "cartId" => $this->updateReservedOrderId(), 
            "discount" => [
                "price" => $quote->getShippingAddress()->getDiscountAmount(),
                "title" => $quote->getShippingAddress()->getDiscountDescription()
            ],
            "errorUrl" => $this->storeManager->getStore()->getUrl('aplazopayment/index/error'),
            "products" => $products,
            "shipping" => [
                "price" => $quote->getShippingAddress()->getShippingAmount(),
                "title" => $quote->getShippingAddress()->getShippingDescription()
            ],
            "shopId" => $this->storeManager->getStore()->getName(),
            "successUrl" => $this->storeManager->getStore()->getUrl('aplazopayment/index/success'),
            "taxes" => [
                "price" => $quote->getShippingAddress()->getTaxAmount(),
                "title" => __('Tax')
            ],
            "totalPrice" => $quote->getGrandTotal()
        ];

        $logger->info("aplazo request ". print_r($return,true));

        return $return;
    }

    public function updateReservedOrderId(){

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

}
