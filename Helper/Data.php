<?php

namespace Aplazo\AplazoPayment\Helper;

use Magento\Customer\Model\Session;/*OK*/
use Magento\Framework\App\Helper\AbstractHelper;/*OK*/
use Magento\Framework\App\Helper\Context;/*OK*/


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
        \Magento\Directory\Model\Country $country,
        Session $customerSession,
        Context $context
    ) {
        $this->country = $country;
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
     * Get the list of regions present in the given Country
     * Returns empty array if no regions available for Country
     * 
     * @param String
     * @return Array/Void
    */
    public function getRegionsOfCountry($countryCode) {
        $regionCollection = $this->country->loadByCode($countryCode)->getRegions();
        $regions = $regionCollection->loadData()->toOptionArray(false);
        return $regions;
    } 
    
    public function getRegionDefaultMX($countryCode,$stringRegion){

        $regionId = $this->getRegionsOfCountry($countryCode);
        foreach($regionId as $data){
            if($data['title'] == $stringRegion){
                return $data['value'];
            }
        }
        return 583; #TODO colocar el id default de la CDMX o el que quieran implementar
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
        $quote->getShippingAddress()->setRegionId($this->getRegionDefaultMX('mex','Ciudad de México'));
        $quote->getBillingAddress()->setRegionId($this->getRegionDefaultMX('mex','Ciudad de México'));
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
