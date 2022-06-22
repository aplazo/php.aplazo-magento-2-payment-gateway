<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Api\Data\SaleInterface;
use Aplazo\AplazoPayment\Api\Data\SaleInterfaceFactory;
use Magento\Framework\Api\DataObjectHelper;

class Sale extends \Magento\Framework\Model\AbstractModel
{
    protected $_eventPrefix = 'aplazo_aplazopayment_sale';
    protected $saleDataFactory;

    protected $dataObjectHelper;


    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param SaleInterfaceFactory $saleDataFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param \Aplazo\AplazoPayment\Model\ResourceModel\Sale $resource
     * @param \Aplazo\AplazoPayment\Model\ResourceModel\Sale\Collection $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        SaleInterfaceFactory $saleDataFactory,
        DataObjectHelper $dataObjectHelper,
        \Aplazo\AplazoPayment\Model\ResourceModel\Sale $resource,
        \Aplazo\AplazoPayment\Model\ResourceModel\Sale\Collection $resourceCollection,
        array $data = []
    ) {
        $this->saleDataFactory = $saleDataFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * Retrieve sale model with sale data
     * @return SaleInterface
     */
    public function getDataModel()
    {
        $saleData = $this->getData();

        $saleDataObject = $this->saleDataFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $saleDataObject,
            $saleData,
            SaleInterface::class
        );

        return $saleDataObject;
    }
}

