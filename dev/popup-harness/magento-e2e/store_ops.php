<?php
// LOCAL TEST ONLY (BNPL-1664, findings H-4/H-9). Second website/store and cross-store collisions for the kit.
// Run from the Magento root:  bin/clinotty php -- <command> [args] < dev/popup-harness/magento-e2e/store_ops.php
//   store                          creates website/group/store "pf2" if missing (root category 2); prints its store id
//   order <store_id> <increment> <sku>  places a guest check/money-order order in that store with that increment
//                                  (the collision two independent sequences could produce, forced here)
// Prints one JSON line. Never prints tokens or customer data.
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$state = $om->get(\Magento\Framework\App\State::class);
$state->setAreaCode('frontend');
[$_, $command] = array_pad($argv, 2, null);
$out = ['command' => $command];
try {
    if ($command === 'store') {
        $websiteRepo = $om->get(\Magento\Store\Api\WebsiteRepositoryInterface::class);
        try {
            $website = $websiteRepo->get('pf2');
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $website = $om->create(\Magento\Store\Model\Website::class);
            $website->setCode('pf2')->setName('BNPL-1664 kit website')->save();
        }
        $group = $om->create(\Magento\Store\Model\Group::class)->load($website->getDefaultGroupId());
        if (!$group->getId()) {
            $group = $om->create(\Magento\Store\Model\Group::class);
            $group->setWebsiteId($website->getId())->setName('BNPL-1664 kit group')->setCode('pf2_group')->setRootCategoryId(2)->save();
            $website->setDefaultGroupId($group->getId())->save();
        }
        $store = $om->create(\Magento\Store\Model\Store::class)->load('pf2', 'code');
        if (!$store->getId()) {
            $store = $om->create(\Magento\Store\Model\Store::class);
            $store->setCode('pf2')->setName('BNPL-1664 kit store')->setWebsiteId($website->getId())
                ->setGroupId($group->getId())->setIsActive(1)->save();
            $group->setDefaultStoreId($store->getId())->save();
        }
        $out += ['website_id' => (int)$website->getId(), 'store_id' => (int)$store->getId()];
    } elseif ($command === 'order') {
        [$storeId, $increment, $sku] = [(int)$argv[2], (string)$argv[3], (string)$argv[4]];
        if (strpos($sku, 'APLAZO-1664-') !== 0) {
            throw new \InvalidArgumentException('only APLAZO-1664- test products');
        }
        $storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
        $storeManager->setCurrentStore($storeId);
        $store = $storeManager->getStore($storeId);
        $productRepo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $product = $productRepo->get($sku, true);
        if (!in_array((int)$store->getWebsiteId(), array_map('intval', $product->getWebsiteIds()), true)) {
            $product->setWebsiteIds(array_merge($product->getWebsiteIds(), [(int)$store->getWebsiteId()]));
            $productRepo->save($product);
            $product = $productRepo->get($sku, false, $storeId, true);
        }
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store)->setCurrency()->setCustomerIsGuest(true)->setCustomerEmail('pf-foreign@example.invalid')
            ->setCustomerGroupId(0);
        $quote->addProduct($product, 1);
        $address = ['firstname' => 'Demo', 'lastname' => 'Foreign', 'street' => 'Calle Demo 2', 'city' => 'Ciudad de Mexico',
            'country_id' => 'MX', 'region_id' => 0, 'postcode' => '06600', 'telephone' => '5500000000',
            'email' => 'pf-foreign@example.invalid'];
        $region = $om->create(\Magento\Directory\Model\ResourceModel\Region\Collection::class)
            ->addCountryFilter('MX')->getFirstItem();
        $address['region_id'] = (int)$region->getId();
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()
            ->setShippingMethod('flatrate_flatrate');
        $cartRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
        $quote->setPaymentMethod('checkmo');
        $quote->setInventoryProcessed(false);
        $cartRepository->save($quote);
        $quote->getPayment()->setQuote($quote)->importData(['method' => 'checkmo']);
        $quote->collectTotals();
        $quote->setReservedOrderId($increment);
        $cartRepository->save($quote);
        $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);
        $out += ['entity_id' => (int)$order->getId(), 'increment_id' => $order->getIncrementId(), 'store_id' => (int)$order->getStoreId()];
    } else {
        throw new \InvalidArgumentException('unknown command');
    }
    $out['ok'] = true;
} catch (\Throwable $e) {
    $out['ok'] = false;
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL;
