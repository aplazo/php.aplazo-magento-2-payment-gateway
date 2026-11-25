<?php
// LOCAL TEST ONLY (BNPL-1664, Mike's points 5-6). Which queries the plugin's payment-first code adds to ordinary store
// operations. With Magento's own DB profiler on, it measures, in one process:
//   quote_save          an active guest cart (synthetic product) has its quantity changed and is saved (repository)
//   cancel_checkmo      a check/money-order order is cancelled (OrderManagementInterface::cancel)
//   cancel_aplazo       an Aplazo redirect order is cancelled (the stubbed API answers createLoan/cancelLoan)
//   place_checkmo       a check/money-order cart is placed through CartManagementInterface::placeOrder (what checkout
//                       calls; SettlePaidAttemptsBeforePlaceOrder runs before it)
// Setup work (creating carts and orders) is not measured. For each step it prints the total number of queries and
// every query the payment-first code could issue: on aplazo_payment_attempt, FOR UPDATE / LOCK IN SHARE MODE reads,
// and invoice counts. Run once with popup off and once with popup on (control). From the Magento root:
//   bin/clinotty php -- <APLAZO-1664-* sku> < dev/popup-harness/magento-e2e/query_probe.php
// Synthetic data only (pf-probe@example.invalid); prints no tokens or customer data.
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
// Never against a real Aplazo environment: stub enabled, plugin services stubbed, local store (kit_guard.php).
define('KIT_GUARD_LIBRARY', true);
require BP . '/app/code/Aplazo/AplazoPayment/dev/popup-harness/magento-e2e/kit_guard.php';
kit_guard_or_exit($om);
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$sku = (string)($argv[1] ?? '');
if (strpos($sku, 'APLAZO-1664-') !== 0) {
    fwrite(STDERR, "only APLAZO-1664-* test products\n");
    exit(2);
}
// cancel_aplazo places and cancels an Aplazo order: only with the stubbed API, never against a real Aplazo environment.
if (!$om->get(\Magento\Framework\Module\Manager::class)->isEnabled('LocalDev_AplazoStub')) {
    fwrite(STDERR, "LocalDev_AplazoStub is disabled: refusing to run (the Aplazo order would reach the configured API)\n");
    exit(2);
}
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$profiler = $connection->getProfiler();
$helper = $om->get(\Aplazo\AplazoPayment\Helper\Data::class);
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore(1);
$storeManager->setCurrentStore(1);
$cartRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
$product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get($sku, false, 1, true);

$watch = '/aplazo_payment_attempt|FOR UPDATE|LOCK IN SHARE MODE|FROM `?sales_invoice`?\b/i';
$measure = function (string $name, callable $fn) use ($profiler, $watch) {
    $profiler->clear();
    $profiler->setEnabled(true);
    $error = null;
    try {
        $fn();
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    } finally {
        $profiler->setEnabled(false);
    }
    $all = [];
    foreach ($profiler->getQueryProfiles() ?: [] as $p) {
        $all[] = preg_replace('/\s+/', ' ', $p->getQuery());
    }
    return ['step' => $name, 'queries' => count($all), 'error' => $error,
        'payment_first_like' => array_values(array_filter($all, fn($q) => preg_match($watch, $q) === 1))];
};
$newQuote = function (string $method) use ($om, $store, $product, $cartRepository) {
    $quote = $om->create(\Magento\Quote\Model\Quote::class);
    $quote->setStore($store)->setCurrency()->setCustomerIsGuest(true)->setCustomerEmail('pf-probe@example.invalid')
        ->setCustomerGroupId(0);
    $quote->addProduct($product, 1);
    $region = $om->create(\Magento\Directory\Model\ResourceModel\Region\Collection::class)->addCountryFilter('MX')->getFirstItem();
    $address = ['firstname' => 'Demo', 'lastname' => 'Probe', 'street' => 'Calle Demo 3', 'city' => 'Ciudad de Mexico',
        'country_id' => 'MX', 'region_id' => (int)$region->getId(), 'postcode' => '06600', 'telephone' => '5500000000',
        'email' => 'pf-probe@example.invalid'];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()
        ->setShippingMethod('flatrate_flatrate');
    $quote->setPaymentMethod($method);
    $quote->setInventoryProcessed(false);
    $cartRepository->save($quote);
    $quote->getPayment()->setQuote($quote)->importData(['method' => $method]);
    $quote->collectTotals();
    $cartRepository->save($quote);
    return $quote;
};

$out = ['switch_store_1' => $helper->isPaymentFirstEnabled(1), 'in_use_store_1' => $helper->isPaymentFirstInUse(1),
    'in_use_any_store' => $helper->isPaymentFirstInUseInAnyStore(),
    'sku' => $sku, 'steps' => []];
try {
    $quote = $newQuote('checkmo');
    $quoteId = (int)$quote->getId();
    $out['steps'][] = $measure('quote_save', function () use ($cartRepository, $quoteId) {
        $q = $cartRepository->get($quoteId);
        foreach ($q->getAllVisibleItems() as $item) {
            $item->setQty(2);
        }
        $q->setTotalsCollectedFlag(false)->collectTotals();
        $cartRepository->save($q);
    });
    $cartRepository->delete($cartRepository->get($quoteId));

    $placeQuoteId = (int)$newQuote('checkmo')->getId();
    $placed = null;
    $out['steps'][] = $measure('place_checkmo', function () use ($om, $placeQuoteId, &$placed) {
        $placed = $om->get(\Magento\Quote\Api\CartManagementInterface::class)->placeOrder($placeQuoteId);
    }) + ['order_entity_id' => $placed];

    $management = $om->get(\Magento\Sales\Api\OrderManagementInterface::class);
    foreach (['checkmo' => 'cancel_checkmo', 'aplazo_gateway' => 'cancel_aplazo'] as $method => $step) {
        $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($newQuote($method));
        $orderId = (int)$order->getId();
        $out['steps'][] = $measure($step, function () use ($management, $orderId) {
            $management->cancel($orderId);
        }) + ['increment_id' => $order->getIncrementId()];
    }
    $out['ok'] = true;
} catch (\Throwable $e) {
    $out['ok'] = false;
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
