<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\Data\SaleInterfaceFactory;
use Aplazo\AplazoPayment\Api\Data\SaleSearchResultsInterfaceFactory;
use Aplazo\AplazoPayment\Api\SaleRepositoryInterface;
use Aplazo\AplazoPayment\Model\ResourceModel\Sale as ResourceSale;
use Aplazo\AplazoPayment\Model\ResourceModel\Sale\CollectionFactory as SaleCollectionFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Store\Model\StoreManagerInterface;

class SaleRepository implements SaleRepositoryInterface
{

    protected $dataSaleFactory;

    protected $saleCollectionFactory;

    protected $dataObjectHelper;

    protected $extensibleDataObjectConverter;
    private $collectionProcessor;

    private $storeManager;

    protected $searchResultsFactory;

    protected $resource;

    protected $extensionAttributesJoinProcessor;

    protected $dataObjectProcessor;

    protected $saleFactory;


    /**
     * @param ResourceSale $resource
     * @param SaleFactory $saleFactory
     * @param SaleInterfaceFactory $dataSaleFactory
     * @param SaleCollectionFactory $saleCollectionFactory
     * @param SaleSearchResultsInterfaceFactory $searchResultsFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param DataObjectProcessor $dataObjectProcessor
     * @param StoreManagerInterface $storeManager
     * @param CollectionProcessorInterface $collectionProcessor
     * @param JoinProcessorInterface $extensionAttributesJoinProcessor
     * @param ExtensibleDataObjectConverter $extensibleDataObjectConverter
     */
    public function __construct(
        ResourceSale $resource,
        SaleFactory $saleFactory,
        SaleInterfaceFactory $dataSaleFactory,
        SaleCollectionFactory $saleCollectionFactory,
        SaleSearchResultsInterfaceFactory $searchResultsFactory,
        DataObjectHelper $dataObjectHelper,
        DataObjectProcessor $dataObjectProcessor,
        StoreManagerInterface $storeManager,
        CollectionProcessorInterface $collectionProcessor,
        JoinProcessorInterface $extensionAttributesJoinProcessor,
        ExtensibleDataObjectConverter $extensibleDataObjectConverter
    ) {
        $this->resource = $resource;
        $this->saleFactory = $saleFactory;
        $this->saleCollectionFactory = $saleCollectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->dataSaleFactory = $dataSaleFactory;
        $this->dataObjectProcessor = $dataObjectProcessor;
        $this->storeManager = $storeManager;
        $this->collectionProcessor = $collectionProcessor;
        $this->extensionAttributesJoinProcessor = $extensionAttributesJoinProcessor;
        $this->extensibleDataObjectConverter = $extensibleDataObjectConverter;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        \Aplazo\AplazoPayment\Api\Data\SaleInterface $sale
    ) {
        /* if (empty($sale->getStoreId())) {
            $storeId = $this->storeManager->getStore()->getId();
            $sale->setStoreId($storeId);
        } */

        $saleData = $this->extensibleDataObjectConverter->toNestedArray(
            $sale,
            [],
            \Aplazo\AplazoPayment\Api\Data\SaleInterface::class
        );

        $saleModel = $this->saleFactory->create()->setData($saleData);

        try {
            $this->resource->save($saleModel);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__(
                'Could not save the sale: %1',
                $exception->getMessage()
            ));
        }
        return $saleModel->getDataModel();
    }

    /**
     * {@inheritdoc}
     */
    public function get($saleId)
    {
        $sale = $this->saleFactory->create();
        $this->resource->load($sale, $saleId);
        if (!$sale->getId()) {
            throw new NoSuchEntityException(__('Sale with id "%1" does not exist.', $saleId));
        }
        return $sale->getDataModel();
    }


    /**
     * {@inheritdoc}
     */
    public function getByQuoteId($quoteId)
    {
        $sale = $this->saleFactory->create();
        $this->resource->load($sale, $quoteId, 'quote_id');
        if (!$sale->getId()) {
            throw new NoSuchEntityException(__('Sale with quote id "%1" does not exist.', $quoteId));
        }
        return $sale->getDataModel();
    }


    /**
     * {@inheritdoc}
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $criteria
    ) {
        $collection = $this->saleCollectionFactory->create();

        $this->extensionAttributesJoinProcessor->process(
            $collection,
            \Aplazo\AplazoPayment\Api\Data\SaleInterface::class
        );

        $this->collectionProcessor->process($criteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($criteria);

        $items = [];
        foreach ($collection as $model) {
            $items[] = $model->getDataModel();
        }

        $searchResults->setItems($items);
        $searchResults->setTotalCount($collection->getSize());
        return $searchResults;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(
        \Aplazo\AplazoPayment\Api\Data\SaleInterface $sale
    ) {
        try {
            $saleModel = $this->saleFactory->create();
            $this->resource->load($saleModel, $sale->getSaleId());
            $this->resource->delete($saleModel);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__(
                'Could not delete the Sale: %1',
                $exception->getMessage()
            ));
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById($saleId)
    {
        return $this->delete($this->get($saleId));
    }
}

