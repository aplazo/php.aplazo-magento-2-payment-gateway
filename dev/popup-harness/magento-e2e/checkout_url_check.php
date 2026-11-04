<?php
// LOCAL TEST ONLY (BNPL-1664). Checks PopupCheckout::getPopupCheckoutUrl() against the configured checkout origin with
// in-memory orders: no database writes, no orders, no network. Run from the Magento root with this module mounted:
//   bin/clinotty php < dev/popup-harness/magento-e2e/checkout_url_check.php
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$helper = new class extends \Aplazo\AplazoPayment\Helper\Data {
    public function __construct() {}
    public function getCheckoutOrigin(): ?string { return 'https://checkout.aplazo.net'; }
};
$pc = $om->create(\Aplazo\AplazoPayment\Model\PopupCheckout::class, ['aplazoHelper' => $helper]);
$order = function (string $stored) use ($om) {
    return $om->create(\Magento\Sales\Model\Order::class)->setData('aplazo_checkout_url', $stored);
};

$accepted = [
    'https://checkout.aplazo.net/main/abc?quoteid=1&token=a+b%2Fc=#/r?x=1||tok'
        => 'https://checkout.aplazo.net/main/abc?quoteid=1&token=a+b%2Fc=&mode=popup#/r?x=1',
    'https://checkout.aplazo.net/x||tok' => 'https://checkout.aplazo.net/x?mode=popup',
    'https://CHECKOUT.aplazo.net/x?mode=redirect' => null,  // value checked below: host compare is case-insensitive
    'https://checkout.aplazo.net:443/x' => null,
];
$rejected = [
    'empty' => '',
    'relative path' => '/main/abc',
    'scheme-relative' => '//checkout.aplazo.net/main',
    'http' => 'http://checkout.aplazo.net/main',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,hi',
    'credentials' => 'https://user:pass@checkout.aplazo.net/main',
    'user only' => 'https://user@checkout.aplazo.net/main',
    'suffix host' => 'https://checkout.aplazo.net.evil.example/main',
    'prefix host' => 'https://evilcheckout.aplazo.net/main',
    'subdomain' => 'https://x.checkout.aplazo.net/main',
    'parent domain' => 'https://aplazo.net/main',
    'trailing dot' => 'https://checkout.aplazo.net./main',
    'other port' => 'https://checkout.aplazo.net:8443/main',
    'backslash' => 'https://checkout.aplazo.net\\@evil.example/main',
    'space' => 'https://checkout.aplazo.net /main',
    'control char' => "https://checkout.aplazo.net/\x01main",
    'malformed' => 'https://[::1/main',
    'no host' => 'https:///main',
    'only token' => '||tok',
];

$results = [];
foreach ($accepted as $stored => $expected) {
    $url = $pc->getPopupCheckoutUrl($order($stored));
    $ok = $expected === null ? ($url !== null && substr($url, -10) === 'mode=popup') : $url === $expected;
    $results[] = [$ok ? 'PASS' : 'FAIL', 'accepted: ' . $stored . ' -> ' . var_export($url, true)];
}
foreach ($rejected as $label => $stored) {
    $url = $pc->getPopupCheckoutUrl($order($stored));
    $results[] = [$url === null ? 'PASS' : 'FAIL', 'rejected: ' . $label . ($url === null ? '' : ' -> ' . $url)];
}
$noOrigin = $om->create(\Aplazo\AplazoPayment\Model\PopupCheckout::class, ['aplazoHelper' => new class extends \Aplazo\AplazoPayment\Helper\Data {
    public function __construct() {}
    public function getCheckoutOrigin(): ?string { return null; }
}]);
$results[] = [$noOrigin->getPopupCheckoutUrl($order('https://checkout.aplazo.net/x')) === null ? 'PASS' : 'FAIL',
    'rejected: no configured origin (production)'];

foreach ($results as [$status, $name]) {
    echo $status, '  ', $name, PHP_EOL;
}
$failed = count(array_filter($results, function ($r) { return $r[0] === 'FAIL'; }));
echo count($results), ' checks, ', $failed, ' failed', PHP_EOL;
exit($failed ? 1 : 0);
