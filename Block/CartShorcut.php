<?php

namespace Aplazo\AplazoPayment\Block;

use Magento\Catalog\Block\ShortcutInterface;/*OK*/
use Aplazo\AplazoPayment\Model\Config;
use Magento\Checkout\Model\Session;/*OK*/
use Magento\Framework\Locale\ResolverInterface;/*OK*/
use Magento\Framework\View\Element\Template;/*OK*/
use Magento\Framework\View\Element\Template\Context;/*OK*/
use Aplazo\AplazoPayment\Model\Payment;

/**
 * Class Button
 */
class CartShorcut extends Template implements ShortcutInterface
{

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var Payment
     */
    protected $payment;

    /**
     * CartShorcut constructor.
     * @param Context $context
     * @param ResolverInterface $localeResolver
     * @param Session $checkoutSession
     * @param Config $config
     * @param Payment $payment
     * @param array $data
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        Config $config,
        Payment $payment,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->payment = $payment;
    }

    public function getAlias()
    {
        return 'aplazo.cart.shorcut';
    }

    /**
     * @inheritdoc
     */
    protected function _toHtml()
    {
        if ($this->isActive()) {
            $this->setTemplate('Aplazo_AplazoPayment::buy_with_aplazo_checkout.phtml');
            return parent::_toHtml();
        }

        return '';
    }

    /**
     * Returns if is active.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->payment->isAvailable($this->checkoutSession->getQuote()) &&
            $this->config->getShowOnCart();
    }
}