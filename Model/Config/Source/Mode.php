<?php

namespace Aplazo\AplazoPayment\Model\Config\Source;

class Mode implements \Magento\Framework\Option\ArrayInterface /*OK*/
{
    const STAGE = 'stage';

    const DEV = 'dev';

    const PROD = 'prod';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            self::STAGE => __('Stage'),
            self::DEV => __('Dev'),
            self::PROD => __('Prod')
        ];
    }
}
