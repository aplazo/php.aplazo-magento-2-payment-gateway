<?php

namespace Aplazo\AplazoPayment\Setup;

use Aplazo\AplazoPayment\Service\TrackingService;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    public function __construct(
        private TrackingService $trackingService
    ) {
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        try {
            $this->trackingService->trackModuleUninstalled();
        } catch (\Throwable $e) {
            // Never break module uninstall because of tracking.
        } finally {
            $setup->endSetup();
        }
    }
}

