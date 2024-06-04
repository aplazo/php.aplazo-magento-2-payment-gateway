<?php

namespace Aplazo\AplazoPayment\Helper;

use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\StoreManagerInterface;
use Aplazo\AplazoPayment\Service\ApiService as AplazoService;

class Data extends \Magento\Payment\Helper\Data
{
    const GENERAL_SECTION = 'payment/aplazo_gateway/';
    const APLAZO_WEBHOOK_RECEIVED = 'aplazo_webhook_received';
    const APLAZO_ORDER_CANCELLED = 'aplazo_order_cancelled';
    const LOGS_SUBCATEGORY_AUTH = 'auth';
    const LOGS_SUBCATEGORY_LOAN = 'loan';
    const LOGS_SUBCATEGORY_REQUEST = 'request';
    const LOGS_SUBCATEGORY_ORDER = 'order';
    const LOGS_SUBCATEGORY_REFUND = 'refund';
    const LOGS_SUBCATEGORY_WEBHOOK = 'webhook';
    const LOGS_SUBCATEGORY_HEALTH_CHECK = 'health';
    const LOGS_CATEGORY_ERROR = 'error';
    const LOGS_CATEGORY_WARNING = 'warning';
    const LOGS_CATEGORY_INFO = 'info';
    const LOGS_VVV = 2;

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

    public function getDebugVerbosity(){
        return $this->getConfigFlag(
            self::GENERAL_SECTION . 'debug_mode'
        );
    }

    public function isHealthyCheck(){
        return $this->getConfigFlag(
            self::GENERAL_SECTION . 'check_healthy_site'
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
        return $this->getConfigData(self::GENERAL_SECTION . 'show_on_product_page');
    }

    public function getShowOnCart(){
        return $this->getConfigData(self::GENERAL_SECTION . 'show_on_cart');
    }

    public function getRefund(){
        return $this->getConfigData(self::GENERAL_SECTION . 'refund');
    }

    public function getRmaRefund(){
        return $this->getConfigData(self::GENERAL_SECTION . 'rma_refund');
    }

    public function getSendEmail(){
        return $this->getConfigData(self::GENERAL_SECTION . 'send_email');
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

    public function getCancelActive(){
        return $this->getConfigData(self::GENERAL_SECTION . 'cancel_active');
    }

    public function getCancelMessage(){
        return $this->getConfigData(self::GENERAL_SECTION . 'cancel_message');
    }

    public function getEnableRecoverCart(){
        return $this->getConfigData(self::GENERAL_SECTION . 'enable_recover_cart');
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

    public function getServiceLogUrl(){
        return $this->getConfigFlag(self::GENERAL_SECTION . 'sanbox_mode') ? 'https://posbifrost.aplazo.net/api/v1/merchant/tagging' : 'https://posbifrost.aplazo.mx/api/v1/merchant/tagging';
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

    public function log($message, $verbosity = 1)
    {
        $this->aplazoLogger->setName('aplazo_payments.log');
        if($this->getDebugVerbosity() == self::LOGS_VVV and $verbosity == self::LOGS_VVV) {
            $this->aplazoLogger->info($message);
        } elseif($this->getDebugVerbosity() == 1) {
            $this->aplazoLogger->info($message);
        }
        return true;
    }
}
