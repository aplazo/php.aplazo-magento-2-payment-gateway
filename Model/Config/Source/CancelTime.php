<?php

namespace Aplazo\AplazoPayment\Model\Config\Source;

class CancelTime implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '15', 'label' => '15 ' . __('minutes')],
            ['value' => '30', 'label' => '30 ' . __('minutes')],
            ['value' => '60', 'label' => '1 ' . __('hour')],
            ['value' => '120', 'label' => '2 ' . __('hours')],
            ['value' => '240', 'label' => '4 ' . __('hours')],
            ['value' => '480', 'label' => '8 ' . __('hours')],
            ['value' => '720', 'label' => '12 ' . __('hours')],
            ['value' => '1080', 'label' => '18 ' . __('hours')],
            ['value' => '1440', 'label' => '24 ' . __('hours')],
            ['value' => '0', 'label' => __('Disabled')],
        ];
    }
}
