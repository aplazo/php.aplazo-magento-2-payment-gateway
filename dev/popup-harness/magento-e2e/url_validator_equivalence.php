<?php
// LOCAL TEST ONLY (BNPL-1664). Compares two versions of CheckoutUrlValidator (the laminas-uri one and the parse_url()
// one) on the same URLs: isOnOrigin() and popupUrl(). The old version is loaded under another namespace. From the
// Magento root:
//   bin/clinotty php -- <old CheckoutUrlValidator.php copy under var/> < dev/popup-harness/magento-e2e/url_validator_equivalence.php
// Prints one JSON object: totals and every row that differs.
require 'app/bootstrap.php';
\Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$old = file_get_contents((string)($argv[1] ?? ''));
$old = str_replace('declare(strict_types=1);', '', $old);
$old = str_replace('namespace Aplazo\AplazoPayment\Model\PaymentFirst;', 'namespace KitOld;', $old);
eval('?>' . $old); // nosemgrep: php.lang.security.eval-use.eval-use -- local one-off comparison (dev/ is export-ignore): evaluates the operator's own copy of the previous CheckoutUrlValidator
$o = new \KitOld\CheckoutUrlValidator();
$n = new \Aplazo\AplazoPayment\Model\PaymentFirst\CheckoutUrlValidator();
$origin = 'https://checkout.aplazo.net';
$urls = [
    'https://checkout.aplazo.net/main/abc?x=1', 'https://checkout.aplazo.net', 'https://checkout.aplazo.net/',
    'https://CHECKOUT.APLAZO.NET/p', 'https://checkout.aplazo.net:443/p', 'https://checkout.aplazo.net:8443/p',
    'https://checkout.aplazo.net:0/p', 'https://checkout.aplazo.net:/p', 'https://checkout.aplazo.net:99999/p',
    'http://checkout.aplazo.net/p', 'HTTPS://checkout.aplazo.net/p', '//checkout.aplazo.net/p', '/main/abc', '',
    'https://user@checkout.aplazo.net/p', 'https://user:pw@checkout.aplazo.net/p', 'https://@checkout.aplazo.net/p',
    'https://checkout.aplazo.net@evil.com/p', 'https://checkout.aplazo.net:443@evil.com/p', 'https://evil.com#@checkout.aplazo.net',
    'https://evil.com?@checkout.aplazo.net', 'https://checkout.aplazo.net.evil.com/p', 'https://evilcheckout.aplazo.net/p',
    'https://sub.checkout.aplazo.net/p', 'https://checkout.aplazo.net./p', 'https://checkout%2Eaplazo.net/p',
    "https://checkout.aplazo.net/p\n", 'https://checkout.aplazo.net/ p', 'https:\\\\checkout.aplazo.net/p',
    'https:/checkout.aplazo.net/p', 'https:checkout.aplazo.net/p', 'javascript:alert(1)//checkout.aplazo.net',
    'https://[::1]/p', 'https://checkout.aplazo.net/p?mode=redirect&a=1', 'https://checkout.aplazo.net/p?a=%20b&mode=x#frag',
    'https://checkout.aplazo.net/p?mod%65=x&b=2', 'https://checkout.aplazo.net/p?&&a=1', 'https://checkout.aplazo.net/p#only',
    'https://checkout.aplazo.net/p?a=1#f?mode=x', 'https://checkout.aplazo.net/p?a[]=1&a[]=2', 'https://checkout.aplazo.net/%7Euser',
    'https://checkout.aplazo.net/p?q=á', 'https://checkout.aplazo.net\t/p', 'https://checkout.aplazo.net/p?x=1&mode',
];
$rows = [];
$diff = [];
foreach ($urls as $u) {
    $r = ['url' => $u, 'old_on' => $o->isOnOrigin($u, $origin), 'new_on' => $n->isOnOrigin($u, $origin),
        'old_popup' => $o->popupUrl($u, $origin), 'new_popup' => $n->popupUrl($u, $origin)];
    $rows[] = $r;
    if ($r['old_on'] !== $r['new_on'] || $r['old_popup'] !== $r['new_popup']) {
        $diff[] = $r;
    }
}
echo json_encode(['urls' => count($rows), 'differences' => count($diff), 'diff' => $diff,
    'accepted' => array_values(array_map(fn($r) => $r['url'], array_filter($rows, fn($r) => $r['new_on'])))],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
