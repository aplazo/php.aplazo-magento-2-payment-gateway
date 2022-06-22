<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Api;

use Magento\Framework\Api\SearchCriteriaInterface;

interface SaleRepositoryInterface
{

    /**
     * Save Sale
     * @param \Aplazo\AplazoPayment\Api\Data\SaleInterface $sale
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        \Aplazo\AplazoPayment\Api\Data\SaleInterface $sale
    );

    /**
     * Retrieve Sale
     * @param string $saleId
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get($saleId);

    /**
     * Retrieve Sale by QuoteId
     * @param string $quoteId
     * @return \Aplazo\AplazoPayment\Api\Data\SaleInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getByQuoteId($quoteId);

    /**
     * Retrieve Sale matching the specified criteria.
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Aplazo\AplazoPayment\Api\Data\SaleSearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    );

    /**
     * Delete Sale
     * @param \Aplazo\AplazoPayment\Api\Data\SaleInterface $sale
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        \Aplazo\AplazoPayment\Api\Data\SaleInterface $sale
    );

    /**
     * Delete Sale by ID
     * @param string $saleId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($saleId);
}

