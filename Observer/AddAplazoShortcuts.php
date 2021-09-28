<?php

namespace Aplazo\AplazoPayment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class AddAplazoShortcuts implements ObserverInterface
{
    /**
     * Alias for mini-cart block.
     */
    const MINICART_ALIAS = 'mini_cart';

    /**
     * Alias for shopping cart page.
     */
    const SHOPPINGCART_ALIAS = 'shopping_cart';

    /**
     * Alias for shopping cart page.
     */
    const PRODUCTPAGE_ALIAS = 'productpage';

    /**
     * @var string[]
     */
    private $buttonBlocks;

    /**
     * @param string[] $buttonBlocks
     */
    public function __construct(array $buttonBlocks = [])
    {
        $this->buttonBlocks = $buttonBlocks;
    }

    /**
     * @param Observer $observer
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(Observer $observer)
    {
        /*$shortcutButtons = $observer->getEvent()->getContainer();
        if ($observer->getData('is_catalog_product')) {
            $shortcut = $shortcutButtons->getLayout()
                ->createBlock($this->buttonBlocks[self::PRODUCTPAGE_ALIAS]);
        } else {
            $shortcut = $shortcutButtons->getLayout()
                ->createBlock($this->buttonBlocks[self::SHOPPINGCART_ALIAS]);
        }

        $shortcutButtons->addShortcut($shortcut);*/
    }
}
