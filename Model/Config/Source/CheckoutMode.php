<?php

namespace Aplazo\AplazoPayment\Model\Config\Source;

class CheckoutMode implements \Magento\Framework\Data\OptionSourceInterface
{
    const MODE_REDIRECT = 'redirect';
    const MODE_POPUP = 'popup';

    public function toOptionArray()
    {
        return [
            ['value' => self::MODE_REDIRECT, 'label' => __('Redirect')],
            ['value' => self::MODE_POPUP, 'label' => __('Popup')],
        ];
    }
}
