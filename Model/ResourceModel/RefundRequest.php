<?php

namespace Aplazo\AplazoPayment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RefundRequest extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('aplazo_refund_request', 'entity_id');
    }
}

