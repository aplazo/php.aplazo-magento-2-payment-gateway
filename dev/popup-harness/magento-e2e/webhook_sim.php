<?php
// LOCAL TEST ONLY (BNPL-1664). Simulated Aplazo webhook to the store's own REST callback, signed like merchant-partners
// (HS512 with the merchant's apiToken, as Notifications::validateJWT accepts it). Run from the Magento root:
//   bin/clinotty php -- <cartId> <loanId> [bodyStatus] [merchant|other-merchant|forged] < webhook_sim.php
// The JWT carries the configured merchantId (or another one, or a wrong key, to test rejection). Prints the HTTP
// code and the plugin's answer; never prints the apiToken or the merchant id.
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
[$_, $cartId, $loanId, $status, $mode] = array_pad($argv, 5, null);
$status = $status ?: 'Activo';
$mode = $mode ?: 'merchant';
$helper = $om->get(\Aplazo\AplazoPayment\Helper\Data::class);
$apiToken = (string)$helper->getApiToken();
$merchantId = (string)$helper->getMerchantId();
if ($apiToken === '' || $merchantId === '' || !$cartId) {
    fwrite(STDERR, "usage: php -- <cartId> <loanId> [status] [merchant|other-merchant|forged]; the store needs credentials\n");
    exit(2);
}
$key = $mode === 'forged' ? 'definitely-not-the-merchant-token' : \Aplazo\AplazoPayment\Model\Notifications::padHmacKey($apiToken);
$claims = [
    'sub' => $mode === 'other-merchant' ? '1' . $merchantId : $merchantId,
    // merchant-partners signs the confirmation with the NEW callback's data (dev/payment-first/SECURITY-NOTE-webhook-status.md).
    'status' => 'Nuevo',
    'loanId' => (int)$loanId ?: (string)$loanId,
    'cartId' => $cartId,
    'merchantId' => $mode === 'other-merchant' ? (int)('1' . $merchantId) : (int)$merchantId,
    'exp' => time() + 1800,
];
$b64u = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$h = $b64u(json_encode(['typ' => 'JWT', 'alg' => 'HS512']));
$p = $b64u(json_encode($claims));
$jwt = "$h.$p." . $b64u(hash_hmac('sha512', "$h.$p", $key, true));
$ch = curl_init('https://app:8443/rest/V1/aplazo/callback/');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => ['Host: magento.test', 'Content-Type: application/json', "Authorization: Bearer $jwt"],
    CURLOPT_POSTFIELDS => json_encode(['loanId' => (string)$loanId, 'status' => $status, 'cartId' => $cartId]),
]);
$body = (string)curl_exec($ch);
echo json_encode(['http' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => json_decode($body, true) ?? substr($body, 0, 200)]), PHP_EOL;
