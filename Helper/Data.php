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
    const LOGS_VVV = 2;
    private const DEFAULT_TRACKING_BASE_URL_STG = 'https://core.aplazo.net';
    private const DEFAULT_TRACKING_BASE_URL_PROD = 'https://core.aplazo.mx';
    private const PLATFORM_CODE = 'MGT';

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

    public function getTrackingEnvironment(): string
    {
        return $this->getConfigFlag(self::GENERAL_SECTION . 'sanbox_mode') ? 'stg' : 'prod';
    }

    public function getTrackingBaseUrl(): string
    {
        return $this->getConfigFlag(self::GENERAL_SECTION . 'sanbox_mode')
            ? self::DEFAULT_TRACKING_BASE_URL_STG
            : self::DEFAULT_TRACKING_BASE_URL_PROD;
    }

    public function getPlatformCode(): string
    {
        return self::PLATFORM_CODE;
    }

    public function canCancelOnFailure(){
        return $this->getConfigFlag(self::GENERAL_SECTION . 'cancel_order');
    }

    public function getMerchantId(){
        return $this->getConfigData(self::GENERAL_SECTION . 'merchantid');
    }

    public function getStoreBaseUrl(): string
    {
        try {
            return rtrim((string)$this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getStoreName(): string
    {
        try {
            return (string)$this->storeManager->getStore()->getName();
        } catch (\Exception $e) {
            return '';
        }
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
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();
        return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB) . 'rest/default/V1/aplazo/callback';
    }

    public function getCurrentCurrencyCode(){
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();
        return $store->getCurrentCurrency()->getCode();
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
