<?php

namespace Aplazo\AplazoPayment\Model\Config\Source;

class DebugVerbosity implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray()
    {
        return [['value' => 2, 'label' => __('Todos los logs')], ['value' => 1, 'label' => __('Logs importantes')], ['value' => 0, 'label' => __('No')]];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [0 => __('No'), 1 => __('Logs importantes'), 2 => __('Todos los logs')];
    }
}
