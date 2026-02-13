<?php

namespace Aplazo\AplazoPayment\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface RefundRequestSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Aplazo\AplazoPayment\Api\Data\RefundRequestInterface[]
     */
    public function getItems();

    /**
     * @param \Aplazo\AplazoPayment\Api\Data\RefundRequestInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}

