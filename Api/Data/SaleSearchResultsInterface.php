<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Api\Data;

interface SaleSearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{

    /**
     * Get Sale list.
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface[]
     */
    public function getItems();

    /**
     * Set quote_id list.
     * @param \Aplazo\AplazoPayment\Api\Data\SaleInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}

