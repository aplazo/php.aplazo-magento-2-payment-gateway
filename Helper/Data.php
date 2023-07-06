<?php

namespace Aplazo\AplazoPayment\Helper;

use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\StoreManagerInterface;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;

class Data extends \Magento\Payment\Helper\Data
{
    const GENERAL_SECTION = 'payment/aplazo_gateway/';
    const CHECKOUT_SECTION = 'payment/aplazo_gateway/checkout/';
    const CREDENTIAL_SECTION = 'payment/aplazo_gateway/credentials/';
    const DEBUG_SECTION = 'payment/aplazo_gateway/debug/';

    const USER_AUTHENTICATED = 1;
    const INCOMPLETE_CREDENTIALS = 0;
    const USER_NOT_AUTHENTICATED = -1;
    const CALLBACK_NOT_EQUALS = 2;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private $encryptor;
    /**
     * @var \Aplazo\AplazoPayment\Logger\Logger
     */
    private $aplazoLogger;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        LayoutFactory $layoutFactory,
        \Magento\Payment\Model\Method\Factory $paymentMethodFactory,
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\App\Config\Initial $initialConfig,
        StoreManagerInterface $storeManager,
        \Aplazo\AplazoPayment\Logger\Logger $logger,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor
    )
    {
        parent::__construct($context, $layoutFactory, $paymentMethodFactory, $appEmulation, $paymentConfig, $initialConfig);
        $this->storeManager = $storeManager;
        $this->aplazoLogger = $logger;
        $this->encryptor = $encryptor;
    }

    public function isDebugEnabled(){
        return $this->getConfigFlag(
            self::GENERAL_SECTION . 'debug_mode'
        );
    }

    public function isActive(){
        return $this->getConfigFlag(self::GENERAL_SECTION . 'active');
    }

    public function getNewOrderStatus(){
        return $this->getConfigData(self::GENERAL_SECTION . 'order_status');
    }

    public function getApprovedOrderStatus(){
        return $this->getConfigData(self::GENERAL_SECTION . 'approved_order_status');
    }

    public function getFailureOrderStatus(){
        return $this->getConfigData(self::GENERAL_SECTION . 'failure_order_status');
    }

    public function getReserveStock(){
        return $this->getConfigData(self::GENERAL_SECTION . 'reserve_stock');
    }

    public function getCancelTime(){
        return $this->getConfigData(self::GENERAL_SECTION . 'cancel_time');
    }

    public function getShowOnProductPage(){
        return $this->getConfigData(self::CHECKOUT_SECTION . 'show_on_product_page');
    }

    public function getShowOnCart(){
        return $this->getConfigData(self::CHECKOUT_SECTION . 'show_on_cart');
    }

    public function getRefund(){
        return $this->getConfigData(self::CHECKOUT_SECTION . 'refund');
    }

    public function getSendEmail(){
        return $this->getConfigData(self::CHECKOUT_SECTION . 'send_email');
    }

    public function canCancelOnFailure(){
        return $this->getConfigFlag(self::GENERAL_SECTION . 'cancel_order');
    }

    public function getMerchantId(){
        return $this->getConfigData(self::GENERAL_SECTION . 'merchantid');
    }

    public function getApiToken(){
        return $this->encryptor->decrypt($this->getConfigData(self::GENERAL_SECTION . 'apitoken'));
    }

    public function getCallbackUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB) . 'rest/default/V1/aplazo/callback';
    }

    public function getCurrentCurrencyCode(){
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    public function getServiceUrl(){
        return $this->getConfigFlag(self::GENERAL_SECTION . 'sanbox_mode') ? 'https://api.aplazo.net' : 'https://api.aplazo.mx';
    }

    public function getUrl($route, $params = []){
        return $this->_getUrl($route, $params);
    }

    private function getConfigFlag($path){
        return $this->scopeConfig->isSetFlag(
            $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    private function getConfigData($path){
        return $this->scopeConfig->getValue(
            $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function log($message)
    {
        if($this->isDebugEnabled()) {
            $this->aplazoLogger->setName('aplazo_payments.log');
            $this->aplazoLogger->info($message);
        }
        return true;
    }
}
