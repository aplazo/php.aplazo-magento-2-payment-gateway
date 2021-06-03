<?php

namespace Aplazo\AplazoPayment\Block;

use Magento\Framework\View\Element\Template;/*OK*/
use Magento\Framework\View\Element\Template\Context;/*OK*/
use Aplazo\AplazoPayment\Model\Config;

class Checkoutjs extends Template
{

    /**
     * @var Config
     */
    protected $config;

    /**
     * Checkoutjs constructor.
     * @param Config $config
     * @param Context $context
     * @param array $data
     */
    public function __construct(Config $config, Template\Context $context, array $data = [])
    {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function getSubtitle()
    {
        return $this->config->getSubtitle();
    }
}
