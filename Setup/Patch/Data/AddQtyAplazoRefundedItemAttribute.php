<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Setup\Patch\Data;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class AddQtyAplazoRefundedItemAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const RMA_ITEM_CLASS = 'Magento\\Rma\\Model\\Item';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * Constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            // Only Adobe Commerce ships with the RMA module.
            if (!class_exists(self::RMA_ITEM_CLASS)) {
                return;
            }

            $rmaEntityType = constant(self::RMA_ITEM_CLASS . '::ENTITY');

            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $eavSetup->addAttribute(
                $rmaEntityType,
                'qty_aplazo_refunded',
                [
                    'type' => 'decimal',
                    'label' => 'qty_aplazo_refunded',
                    'input' => 'price',
                    'source' => '',
                    'frontend' => '',
                    'required' => false,
                    'backend' => '',
                    'default' => null,
                    'user_defined' => true,
                    'unique' => false,
                    'group' => 'General',
                ]
            );
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }
    }

    public function revert()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            // In Open Source the RMA classes do not exist, so there's nothing to revert.
            if (!class_exists(self::RMA_ITEM_CLASS)) {
                return;
            }

            $rmaEntityType = constant(self::RMA_ITEM_CLASS . '::ENTITY');

            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $eavSetup->removeAttribute($rmaEntityType, 'qty_aplazo_refunded');
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [

        ];
    }
}
