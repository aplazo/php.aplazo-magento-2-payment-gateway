<?php

namespace Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        $this->addFilterToMap('entity_id', 'main_table.entity_id');
        return parent::_initSelect();
    }
}

