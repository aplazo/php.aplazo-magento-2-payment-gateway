<?php

namespace LocalDev\AplazoStub\Observer;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * LOCAL TEST ONLY (BNPL-1664, finding H-1). Holds one ordinary cart request with the quote it already read:
 * var/localdev_pf_quote_save_pause holds "<load|cart|save>:<quote_id>:<seconds>" — right after the quote is loaded
 * (sales_quote_load_after), when the cart is about to be saved (checkout_cart_save_before, after the request's last
 * read) or inside the quote save (sales_quote_save_before, after the module's own observers). The first matching event removes the
 * file, so the pause happens once and never in the finalizer's own load or save that comes later. While held,
 * var/localdev_pf_quote_save_pause.inside exists and records what the request had in memory.
 */
class QuoteSavePause implements ObserverInterface
{
    public function execute(Observer $observer)
    {
        $file = BP . '/var/localdev_pf_quote_save_pause';
        if (!is_file($file)) {
            return;
        }
        [$point, $quoteId, $seconds] = explode(':', trim((string)@file_get_contents($file))) + ['', 0, 0];
        [$quoteId, $seconds] = [(int)$quoteId, (int)$seconds];
        $name = $observer->getEvent()->getName();
        $event = ['sales_quote_load_after' => 'load', 'checkout_cart_save_before' => 'cart'][$name] ?? 'save';
        $quote = $event === 'cart' ? $observer->getEvent()->getCart()->getQuote() : $observer->getEvent()->getQuote();
        if ($point !== $event || !$quote || (int)$quote->getId() !== $quoteId || !@unlink($file)) {
            return;
        }
        $seen = ['point' => $event, 'quote_id' => $quoteId, 'is_active_in_memory' => (int)$quote->getIsActive(),
            'items_qty_in_memory' => (float)$quote->getItemsQty(), 'seconds' => min(60, max(0, $seconds))];
        StubLog::write('sync', 'quote_save_pause', $seen);
        @file_put_contents($file . '.inside', json_encode($seen));
        sleep($seen['seconds']);
        @unlink($file . '.inside');
    }
}
