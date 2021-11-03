<?php

namespace Aplazo\AplazoPayment\Helper;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Data\Form\FormKey;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\Service\OrderService;

class Data extends AbstractHelper
{

    const DUMMY_FIRST_NAME = 'Aplazo';

    const DUMMY_LAST_NAME = 'Client';

    const DUMMY_EMAIL = 'aplazoclient@aplazo.mx';

    /**
     * @var Session
     */
    protected $customerSession;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var StoreManager
     */
    protected $storeManager;

    /**
     * @var Product
     */
    protected $product;

    /**
     * @var FromKey
     */
    protected $formkey;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;

    /**
     * @var CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var CustomerRepository
     */
    protected $customerRepository;

    /**
     * @var OrderService
     */
    protected $orderService;

    /**
     * Data constructor.
     * @param Session $customerSession
     * @param Context $context
     */
    public function __construct(
        Session $customerSession,
        Context $context,
        StoreManagerInterface $storeManager,
        Product $product,
        FormKey $formkey,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        CustomerFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        OrderService $orderService
    ) {
        $this->customerSession = $customerSession;
        $this->_storeManager = $storeManager;
        $this->_product = $product;
        $this->_formkey = $formkey;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderService = $orderService;
        parent::__construct($context);
    }

    /**
     * @return string
     */
    protected function getCustomerFirstname()
    {
        if ($this->customerSession->isLoggedIn()){
            return $this->customerSession->getCustomer()->getFirstname();
        } else {
            return self::DUMMY_FIRST_NAME;
        }
    }

    /**
     * @return string
     */
    protected function getCustomerLastname()
    {
        if ($this->customerSession->isLoggedIn()){
            return $this->customerSession->getCustomer()->getLastname();
        } else {
            return self::DUMMY_LAST_NAME;
        }
    }

    /**
     * @return string
     */
    public function getCustomerEmail()
    {
        if ($this->customerSession->isLoggedIn()){
            return $this->customerSession->getCustomer()->getEmail();
        } else {
            return self::DUMMY_EMAIL;
        }
    }

    /**
     * @param $quote
     */
    public function fillDummyQuote(&$quote)
    {
        $this->setAddressDataToQuote($quote);
        $this->setCustomerDataToQuote($quote);
        $quote->collectTotals();
        $this->setShippingDataToQuote($quote);
        $this->setPaymentDataToQuote($quote);
    }

    /**
     * @param $quote
     */
    protected function setAddressDataToQuote(&$quote)
    {
        $quote->getShippingAddress()->setEmail($this->getCustomerEmail());
        $quote->getShippingAddress()->setEmail($this->getCustomerEmail());
        $quote->getShippingAddress()->setFirstname($this->getCustomerFirstname());
        $quote->getBillingAddress()->setFirstname($this->getCustomerFirstname());
        $quote->getShippingAddress()->setLastname($this->getCustomerLastname());
        $quote->getBillingAddress()->setLastname($this->getCustomerLastname());
        $quote->getShippingAddress()->setCity('Mexico City');
        $quote->getBillingAddress()->setCity('Mexico City');
        $quote->getShippingAddress()->setPostcode('11000');
        $quote->getBillingAddress()->setPostcode('11000');
        $quote->getShippingAddress()->setCountryId('MX');
        $quote->getBillingAddress()->setCountryId('MX');
        $quote->getShippingAddress()->setRegionId(664);
        $quote->getBillingAddress()->setRegionId(664);
        $quote->getShippingAddress()->setStreet('Avenida Paseo de las Palmas, number 755');
        $quote->getBillingAddress()->setStreet('Avenida Paseo de las Palmas, number 755');
        $quote->getShippingAddress()->setTelephone('1234567890');
        $quote->getBillingAddress()->setTelephone('1234567890');
        $quote->getShippingAddress()->setCollectShippingRates(true)
            ->collectShippingRates();
    }

    /**
     * @param $quote
     */
    public function setCustomerDataToQuote(&$quote)
    {
        $quote->setCustomerEmail($this->getCustomerEmail());
    }

    /**
     * @param $quote
     */
    protected function setPaymentDataToQuote(&$quote)
    {
        $quote->setPaymentMethod(\Aplazo\AplazoPayment\Model\Payment::CODE);
        $quote->getPayment()->importData(['method' => \Aplazo\AplazoPayment\Model\Payment::CODE]);
    }

    /**
     * @param $quote
     */
    protected function setShippingDataToQuote(&$quote)
    {
        $rates = $quote->getShippingAddress()->getAllShippingRates();
        if (is_array($rates) && count($rates)) {
            $quote->getShippingAddress()->setShippingMethod($rates[0]->getCode());
        }
    }

    public function getConfig($config_path)
    {
        return $this->scopeConfig->getValue(
            $config_path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Create Order On Your Store
     * 
     * @param array $orderData
     * @return array
     * 
    */
    public function createMageOrder($quote) {
        $quote->setCurrency();
        $quote->setPaymentMethod('aplazo_payment'); //payment method
        $quote->setInventoryProcessed(false); //not effetc inventory
        $quote->save(); 
        $quote->getPayment()->importData(['method' => 'aplazo_payment']);
        $quote->collectTotals()->save();
        $order = $this->quoteManagement->submit($quote);
        $order->setEmailSent(0);
        $increment_id = $order->getRealOrderId();
        if($order->getEntityId()){
            $result['order_id']= $order->getRealOrderId();
        }else{
            $result=['error'=>1,'msg'=>'Something was wrong'];
        }
        return $result;
    }

}
