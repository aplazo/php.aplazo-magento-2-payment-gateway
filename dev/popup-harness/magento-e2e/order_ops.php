<?php
// LOCAL TEST ONLY (BNPL-1665). Order and product operations through Magento's own service contracts, for the E2E kit.
// Run from the Magento root:  bin/clinotty php -- <command> [args] < dev/popup-harness/magento-e2e/order_ops.php
//   cancel <entity_id>          OrderManagementInterface::cancel (what the admin "Cancel" button calls)
//   ship <entity_id>            ShipOrderInterface::execute (default source)
//   product <sku> <qty>         creates a simple test product with that quantity on the default source if missing
// Prints one JSON line. Never prints tokens or customer data.
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
[$_, $command] = array_pad($argv, 2, null);
$out = ['command' => $command];
try {
    if ($command === 'cancel') {
        $out['result'] = $om->get(\Magento\Sales\Api\OrderManagementInterface::class)->cancel((int)$argv[2]);
    } elseif ($command === 'ship') {
        $out['shipment_id'] = $om->get(\Magento\Sales\Api\ShipOrderInterface::class)->execute((int)$argv[2]);
    } elseif ($command === 'product') {
        $sku = (string)$argv[2];
        $qty = (float)$argv[3];
        if (strpos($sku, 'APLAZO-1665-') !== 0) {
            throw new \InvalidArgumentException('test products must start with APLAZO-1665-');
        }
        $repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        try {
            $repo->get($sku);
            $out['created'] = false;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $product = $om->create(\Magento\Catalog\Api\Data\ProductInterface::class);
            $product->setSku($sku)->setName('BNPL-1665 test ' . $sku)->setAttributeSetId(4)->setTypeId('simple')
                ->setPrice(100)->setStatus(1)->setVisibility(4)->setWebsiteIds([1])->setUrlKey(strtolower($sku))
                ->setStockData(['qty' => $qty, 'is_in_stock' => 1, 'manage_stock' => 1, 'use_config_manage_stock' => 1]);
            $repo->save($product);
            $sourceItem = $om->create(\Magento\InventoryApi\Api\Data\SourceItemInterface::class);
            $sourceItem->setSku($sku);
            $sourceItem->setSourceCode('default');
            $sourceItem->setQuantity($qty);
            $sourceItem->setStatus(1);
            $om->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)->execute([$sourceItem]);
            $out['created'] = true;
        }
        $out['url_key'] = strtolower($sku);
    } else {
        throw new \InvalidArgumentException('unknown command');
    }
    $out['ok'] = true;
} catch (\Throwable $e) {
    $out['ok'] = false;
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL;
