<?php

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\Data\RefundRequestInterface;
use Aplazo\AplazoPayment\Api\Data\RefundRequestSearchResultsInterface;
use Aplazo\AplazoPayment\Api\Data\RefundRequestSearchResultsInterfaceFactory;
use Aplazo\AplazoPayment\Api\RefundRequestRepositoryInterface;
use Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest as RefundRequestResource;
use Aplazo\AplazoPayment\Model\ResourceModel\RefundRequest\CollectionFactory as RefundRequestCollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;

class RefundRequestRepository implements RefundRequestRepositoryInterface
{
    public function __construct(
        private RefundRequestResource $resource,
        private RefundRequestFactory $refundRequestFactory,
        private RefundRequestCollectionFactory $collectionFactory,
        private RefundRequestSearchResultsInterfaceFactory $searchResultsFactory,
        private CollectionProcessorInterface $collectionProcessor
    ) {
    }

    public function save(RefundRequestInterface $refundRequest): RefundRequestInterface
    {
        if (!$refundRequest instanceof AbstractModel) {
            throw new CouldNotSaveException(__('Invalid refund request model instance.'));
        }
        /** @var AbstractModel $refundRequest */

        try {
            $this->resource->save($refundRequest);
        } catch (AlreadyExistsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save refund request: %1', $e->getMessage()), $e);
        }

        return $refundRequest;
    }

    public function getById(int $entityId): RefundRequestInterface
    {
        $model = $this->refundRequestFactory->create();
        $this->resource->load($model, $entityId);
        if (!$model->getId()) {
            throw new NoSuchEntityException(__('Refund request with id "%1" does not exist.', $entityId));
        }
        return $model;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): RefundRequestSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setTotalCount((int)$collection->getSize());
        $searchResults->setItems($collection->getItems());

        return $searchResults;
    }

    public function delete(RefundRequestInterface $refundRequest): bool
    {
        try {
            $this->resource->delete($refundRequest);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('Could not delete refund request: %1', $e->getMessage()), $e);
        }
        return true;
    }

    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->getById($entityId));
    }
}

