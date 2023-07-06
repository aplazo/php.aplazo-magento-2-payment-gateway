<?php

namespace Aplazo\AplazoPayment\Block;

class AplazoPayment extends \Magento\Framework\View\Element\Template
{
    const IS_CART_PAGE = 'cart';
    const IS_PRODUCT_PAGE = 'product';

    protected $_registry;
    protected $_aplazoConfigHelper;
    protected $_cart;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Aplazo\AplazoPayment\Helper\Data $aplazoConfigHelper,
        \Magento\Checkout\Model\Cart $cart,
        array $data = []
    )
    {
        $this->_registry = $registry;
        $this->_aplazoConfigHelper = $aplazoConfigHelper;
        $this->_cart = $cart;
        parent::__construct($context, $data);
    }

    public function _prepareLayout()
    {
        return parent::_prepareLayout();
    }

    public function getCurrentCategory()
    {
        return $this->_registry->registry('current_category');
    }

    public function getCurrentProduct()
    {
        return $this->_registry->registry('current_product');
    }

    public function isWidgetVisible($page)
    {
        return $page === self::IS_PRODUCT_PAGE ? $this->_aplazoConfigHelper->getShowOnProductPage() : $this->_aplazoConfigHelper->getShowOnCart();
    }

    public function getCartTotal()
    {
        return $this->_cart->getQuote()->getGrandTotal() * 100;
    }
}
?>
