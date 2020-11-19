<?php

namespace Spro\AplazoPayment\Model\Config\Source;

class Mode implements \Magento\Framework\Option\ArrayInterface
{
    const STAGE = 'stage';

    const PREPROD = 'preprod';

    const PROD = 'prod';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            self::STAGE => __('Stage'),
            self::PREPROD => __('Preprod'),
            self::PROD => __('Prod')
        ];
    }
}
