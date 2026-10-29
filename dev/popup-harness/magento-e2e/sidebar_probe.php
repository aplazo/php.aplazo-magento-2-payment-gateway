<?php
// LOCAL TEST ONLY (BNPL-1664, H-1 crash window). Replays the storefront quantity update of
// checkout/sidebar/updateItemQty for one quote, holding it between its read and its save, and prints where a refusal
// comes from (exception class, message and the first frames, vendor paths only). Run from the Magento root:
//   bin/clinotty php -- <quote_id> <hold_seconds> < dev/popup-harness/magento-e2e/sidebar_probe.php
// While held, var/localdev_pf_sidebar_probe.inside exists. Prints one JSON line; no personal data.
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
[$_, $quoteId, $hold] = array_pad($argv, 3, 0);
$out = ['quote_id' => (int)$quoteId];
try {
    $quote = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->get((int)$quoteId);
    $om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore((int)$quote->getStoreId());
    $om->get(\Magento\Checkout\Model\Session::class)->setQuoteId((int)$quoteId);
    $item = null;
    foreach ($quote->getAllVisibleItems() as $candidate) {
        $item = $candidate;
        break;
    }
    $sidebar = $om->get(\Magento\Checkout\Model\Sidebar::class);
    $sidebar->checkQuoteItem((int)$item->getId());
    $out['read_is_active'] = (int)$om->get(\Magento\Checkout\Model\Cart::class)->getQuote()->getIsActive();
    @touch(BP . '/var/localdev_pf_sidebar_probe.inside');
    sleep(min(60, (int)$hold));
    @unlink(BP . '/var/localdev_pf_sidebar_probe.inside');
    $sidebar->updateQuoteItem((int)$item->getId(), 3);
    $out['saved'] = true;
} catch (\Throwable $e) {
    $out['saved'] = false;
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
    $out['frames'] = array_map(function ($f) {
        return preg_replace('#^.*/(vendor|app/code)/#', '$1/', (string)($f['file'] ?? '')) . ':' . ($f['line'] ?? '')
            . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
    }, array_slice($e->getTrace(), 0, 12));
    $out['thrown_at'] = preg_replace('#^.*/(vendor|app/code)/#', '$1/', $e->getFile()) . ':' . $e->getLine();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL;
