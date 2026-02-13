<?php

namespace Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest;

use Aplazo\AplazoPayment\Model\RefundRequest as Model;
use Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}

