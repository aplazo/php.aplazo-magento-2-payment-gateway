<?php
// LOCAL TEST ONLY (BNPL-1665, BNPL-1664). Order and product operations through Magento's own service contracts, for the E2E kit.
// Run from the Magento root:  bin/clinotty php -- <command> [args] < dev/popup-harness/magento-e2e/order_ops.php
//   cancel <entity_id>          OrderManagementInterface::cancel (what the admin "Cancel" button calls)
//   ship <entity_id>            ShipOrderInterface::execute (default source)
//   product <sku> <qty>         creates a simple test product with that quantity on the default source if missing
//   stock <sku> <qty>           sets the default source quantity of an APLAZO-1664- test product
//   customer <email> <password> creates a synthetic pf-*@example.invalid customer if missing
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
        if (strpos($sku, 'APLAZO-1665-') !== 0 && strpos($sku, 'APLAZO-1664-') !== 0) {
            throw new \InvalidArgumentException('test products must start with APLAZO-1664- or APLAZO-1665-');
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
    } elseif ($command === 'customer') {
        // A synthetic registered customer for the BNPL-1664 tests (example.invalid addresses only).
        [$email, $password] = [(string)$argv[2], (string)$argv[3]];
        if (!preg_match('/^pf-[a-z0-9-]+@example\.invalid$/', $email)) {
            throw new \InvalidArgumentException('only pf-*@example.invalid test customers');
        }
        $repo = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        try {
            $out['customer_id'] = (int)$repo->get($email, 1)->getId();
            $out['created'] = false;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $customer = $om->create(\Magento\Customer\Api\Data\CustomerInterface::class);
            $customer->setEmail($email)->setFirstname('Demo')->setLastname('Registered')->setWebsiteId(1)->setGroupId(1);
            // As a storefront registration: created in the adminhtml area the password does not authenticate.
            $out['customer_id'] = (int)$om->get(\Magento\Framework\App\State::class)->emulateAreaCode('frontend',
                function () use ($om, $customer, $password) {
                    return $om->get(\Magento\Customer\Api\AccountManagementInterface::class)->createAccount($customer, $password)->getId();
                });
            $out['created'] = true;
        }
    } elseif ($command === 'stock') {
        // Sets the default source quantity of an existing test product (BNPL-1664 last-unit test).
        $sku = (string)$argv[2];
        if (strpos($sku, 'APLAZO-1664-') !== 0) {
            throw new \InvalidArgumentException('only APLAZO-1664- test products');
        }
        $sourceItem = $om->create(\Magento\InventoryApi\Api\Data\SourceItemInterface::class);
        $sourceItem->setSku($sku);
        $sourceItem->setSourceCode('default');
        $sourceItem->setQuantity((float)$argv[3]);
        $sourceItem->setStatus(1);
        $om->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)->execute([$sourceItem]);
    } else {
        throw new \InvalidArgumentException('unknown command');
    }
    $out['ok'] = true;
} catch (\Throwable $e) {
    $out['ok'] = false;
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL;
