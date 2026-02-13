<?php

namespace Aplazo\AplazoPayment\Api;

use Aplazo\AplazoPayment\Api\Data\RefundRequestInterface;
use Aplazo\AplazoPayment\Api\Data\RefundRequestSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

interface RefundRequestRepositoryInterface
{
    /**
     * @throws AlreadyExistsException
     * @throws LocalizedException
     */
    public function save(RefundRequestInterface $refundRequest): RefundRequestInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): RefundRequestInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): RefundRequestSearchResultsInterface;

    /**
     * @throws LocalizedException
     */
    public function delete(RefundRequestInterface $refundRequest): bool;

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function deleteById(int $entityId): bool;
}

