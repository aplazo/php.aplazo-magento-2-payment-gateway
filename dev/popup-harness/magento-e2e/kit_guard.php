<?php
// LOCAL TEST ONLY (BNPL-1664). Proves, inside the Magento the kit is about to use, that nothing it triggers can reach a
// real Aplazo environment: LocalDev_AplazoStub is enabled, the plugin's ApiService, LogService and TrackingService
// resolve to the stub's classes, and the store's base URL is local. Two uses, from the Magento root:
//   bin/clinotty php < dev/popup-harness/magento-e2e/kit_guard.php     prints {"ok": true|false, ...}; exit 3 if not ok
//   define('KIT_GUARD_LIBRARY', true); require <this file>; kit_guard_or_exit($om);   from another kit script
// Prints no tokens, merchant ids or customer data.

function kit_guard_check($om): array
{
    $problems = [];
    if (!$om->get(\Magento\Framework\Module\Manager::class)->isEnabled('LocalDev_AplazoStub')) {
        $problems[] = 'LocalDev_AplazoStub is not enabled';
    }
    $services = [
        \Aplazo\AplazoPayment\Service\ApiService::class => 'LocalDev\\AplazoStub\\Service\\ApiService',
        \Aplazo\AplazoPayment\Service\LogService::class => 'LocalDev\\AplazoStub\\Service\\LogService',
        \Aplazo\AplazoPayment\Service\TrackingService::class => 'LocalDev\\AplazoStub\\Service\\TrackingService',
    ];
    $resolved = [];
    foreach ($services as $service => $stub) {
        try {
            $instance = $om->get($service);
            $resolved[$service] = get_class($instance);
            if (!($instance instanceof $stub)) {
                $problems[] = $service . ' resolves to ' . get_class($instance) . ', not the stub';
            }
        } catch (\Throwable $e) {
            $problems[] = $service . ' could not be built: ' . get_class($e);
        }
    }
    $hosts = [];
    foreach ($om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStores() as $store) {
        $host = (string)parse_url((string)$store->getBaseUrl(), PHP_URL_HOST);
        $hosts[] = $host;
        if (!in_array($host, ['magento.test', 'localhost', '127.0.0.1'], true)) {
            $problems[] = 'store ' . $store->getCode() . ' base URL host is ' . $host . ', not local';
        }
    }

    return ['ok' => $problems === [], 'problems' => $problems, 'resolved' => $resolved, 'hosts' => array_values(array_unique($hosts))];
}

function kit_guard_or_exit($om): void
{
    $result = kit_guard_check($om);
    if (!$result['ok']) {
        fwrite(STDERR, 'kit guard: refusing to run: ' . implode('; ', $result['problems']) . PHP_EOL);
        exit(3);
    }
}

if (!defined('KIT_GUARD_LIBRARY')) {
    require 'app/bootstrap.php';
    $om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
    $result = kit_guard_check($om);
    echo json_encode($result), PHP_EOL;
    exit($result['ok'] ? 0 : 3);
}
