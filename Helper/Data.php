<?php

namespace Aplazo\AplazoPayment\Helper;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

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
     * Data constructor.
     * @param Session $customerSession
     * @param Context $context
     */
    public function __construct(
        Session $customerSession,
        Context $context
    ) {
        $this->customerSession = $customerSession;
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

}
