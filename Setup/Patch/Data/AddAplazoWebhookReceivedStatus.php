<?php
namespace Aplazo\AplazoPayment\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;

class AddAplazoWebhookReceivedStatus implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    private $statusFactory;
    private $statusResourceFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param StatusFactory $statusFactory
     * @param StatusResourceFactory $statusResourceFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        StatusFactory $statusFactory,
        StatusResourceFactory $statusResourceFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->statusFactory = $statusFactory;
        $this->statusResourceFactory = $statusResourceFactory;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();
        $this->addAplazoWebhookReceivedStatus();
        $this->moduleDataSetup->endSetup();
    }

    /**
     * Add custom order status
     */
    private function addAplazoWebhookReceivedStatus()
    {
        $status = $this->statusFactory->create();

        $status->setData([
            'status' => 'aplazo_webhook_received',
            'label' => 'Aplazo Webhook Received'
        ]);

        $statusResource = $this->statusResourceFactory->create();
        $statusResource->save($status);

        $status->assignState('processing');

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
