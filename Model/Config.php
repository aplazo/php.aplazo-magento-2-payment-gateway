<?php

namespace Aplazo\AplazoPayment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Aplazo\AplazoPayment\Model\Config\Source\Mode;

class Config
{

    const APLAZO_STAGE_LINK ='https://api.aplazo.net/';
    const APLAZO_DEV_LINK = 'https://api.aplazo.dev/';
    const APLAZO_PROD_LINK = 'https://api.aplazo.mx/';

    /**
     * config path for active
     */
    const APLAZO_ACTIVE = 'payment/aplazo_payment/active';

    /**
     * config path for title
     */
    const APLAZO_TITLE = 'payment/aplazo_payment/title';

    /**
     * config path for title
     */
    const APLAZO_SUBTITLE = 'payment/aplazo_payment/subtitle';

    /**
     * config path for api_token
     */
    const APLAZO_API_TOKEN = 'payment/aplazo_payment/api_token';

    /**
     * config path for api_token
     */
    const APLAZO_ORDER_CANCELLATION_TIME = 'payment/aplazo_payment/order_cancellation_time';

    /**
     * config path for merchant_id
     */
    const APLAZO_MERCHANT_ID = 'payment/aplazo_payment/merchant_id';

    /**
     * config path for show_on_product_page
     */
    const APLAZO_SHOW_ON_PRODUCT_PAGE = 'payment/aplazo_payment/show_on_product_page';

    /**
     * config path for show_on_cart
     */
    const APLAZO_SHOW_ON_CART = 'payment/aplazo_payment/show_on_cart';

    /**
     * config path for debug
     */
    const APLAZO_DEBUG = 'payment/aplazo_payment/debug';

    /**
     * config path for mode
     */
    const APLAZO_MODE = 'payment/aplazo_payment/mode';

    /**
     * config path for enable_log
     */
    const APLAZO_ENABLE_LOG = 'payment/aplazo_payment/enable_log';

    /**
     * config path for sort_order
     */
    const APLAZO_SORT_ORDER = 'payment/aplazo_payment/sort_order';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Config constructor.
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return mixed
     */
    public function getActive()
    {
        return $this->scopeConfig->getValue(self::APLAZO_ACTIVE);
    }

    /**
     * @return mixed
     */
    public function getTitle()
    {
        return $this->scopeConfig->getValue(self::APLAZO_TITLE);
    }

    /**
     * @return mixed
     */
    public function getSubtitle()
    {
        return $this->scopeConfig->getValue(self::APLAZO_SUBTITLE);
    }

    /**
     * @return mixed
     */
    public function getApiToken()
    {
        return $this->scopeConfig->getValue(self::APLAZO_API_TOKEN);
    }

    /**
     * @return mixed
     */
    public function getOrderCancellationTime()
    {
        return $this->scopeConfig->getValue(self::APLAZO_ORDER_CANCELLATION_TIME);
    }

    /**
     * @return mixed
     */
    public function getMerchantId()
    {
        return $this->scopeConfig->getValue(self::APLAZO_MERCHANT_ID);
    }

    /**
     * @return mixed
     */
    public function getMode()
    {
        return $this->scopeConfig->getValue(self::APLAZO_MODE);
    }

    /**
     * @return mixed
     */
    public function getShowOnProductPage()
    {
        return $this->scopeConfig->getValue(self::APLAZO_SHOW_ON_PRODUCT_PAGE);
    }

    /**
     * @return mixed
     */
    public function getShowOnCart()
    {
        return $this->scopeConfig->getValue(self::APLAZO_SHOW_ON_CART);
    }

    /**
     * @return mixed
     */
    public function getDebug()
    {
        return $this->scopeConfig->getValue(self::APLAZO_DEBUG);
    }

    /**
     * @return mixed
     */
    public function getEnableLog()
    {
        return $this->scopeConfig->getValue(self::APLAZO_ENABLE_LOG);
    }

    /**
     * @return mixed
     */
    public function getSortOrder()
    {
        return $this->scopeConfig->getValue(self::APLAZO_SORT_ORDER);
    }

    /**
     * @return string
     */
    public function getBaseApiUrl()
    {
        $mode = $this->getMode();
        if ($mode==Mode::DEV) {
            return self::APLAZO_DEV_LINK;
        }
        if ($mode==Mode::STAGE) {
            return self::APLAZO_STAGE_LINK;
        }
        if ($mode==Mode::PROD) {
            return self::APLAZO_PROD_LINK;
        }
        return self::APLAZO_PROD_LINK;
    }
}
