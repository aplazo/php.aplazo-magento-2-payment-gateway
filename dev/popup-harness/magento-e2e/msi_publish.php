<?php
// LOCAL TEST ONLY (BNPL-1664, MSI salability message). Publishes ONE synthetic inventory.reservations.updateSalabilityStatus
// message for a synthetic product on the default stock, built exactly as MSI's EnqueueAfterPlaceReservationsForSalesEvent
// builds it (ReservationData: skus + stock), through Magento's PublisherInterface. Used only to show what a message
// published AFTER the conversion's commit does. From the Magento root:
//   bin/clinotty php -- <APLAZO-1664-* sku> < dev/popup-harness/magento-e2e/msi_publish.php
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$sku = (string)($argv[1] ?? '');
if (strpos($sku, 'APLAZO-1664-') !== 0) {
    fwrite(STDERR, "only APLAZO-1664-* test products\n");
    exit(2);
}
$stockId = (int)$om->get(\Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface::class)->getId();
$data = $om->get(\Magento\InventoryIndexer\Model\Queue\ReservationDataFactory::class)->create(['skus' => [$sku], 'stock' => $stockId]);
$om->get(\Magento\Framework\MessageQueue\PublisherInterface::class)->publish('inventory.reservations.updateSalabilityStatus', $data);
echo json_encode(['published' => true, 'topic' => 'inventory.reservations.updateSalabilityStatus', 'skus' => [$sku], 'stock' => $stockId]), PHP_EOL;
