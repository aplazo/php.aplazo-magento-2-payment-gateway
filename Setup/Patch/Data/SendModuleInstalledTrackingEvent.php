<?php

namespace Aplazo\AplazoPayment\Setup\Patch\Data;

use Aplazo\AplazoPayment\Service\TrackingService;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SendModuleInstalledTrackingEvent implements DataPatchInterface
{
    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private TrackingService $trackingService
    ) {
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            $this->trackingService->trackModuleInstalled();
        } catch (\Throwable $e) {
            // Never break setup:upgrade because of tracking.
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}

