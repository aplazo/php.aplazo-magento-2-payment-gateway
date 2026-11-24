<?php

namespace LocalDev\AplazoStub\Plugin;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Store\Model\StoreManagerInterface;

/**
 * LOCAL TEST ONLY (BNPL-1664, order email). Observes OrderSender::send without changing it: records the effective
 * area, current store, async setting and the order's email flags before and after, and the full class, message,
 * origin and first frames of anything it throws, which it then rethrows unchanged. Paths are trimmed to
 * vendor/ or app/code/; no addresses or buyer data.
 */
class OrderSenderProbe
{
    public function __construct(
        private State $state,
        private StoreManagerInterface $storeManager,
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    public function aroundSend(OrderSender $subject, callable $proceed, Order $order, $forceSyncMode = false)
    {
        $ctx = function () use ($order) {
            try { $area = $this->state->getAreaCode(); } catch (\Throwable $e) { $area = 'unset'; }
            try { $store = (int)$this->storeManager->getStore()->getId(); } catch (\Throwable $e) { $store = 'error'; }

            return ['order_id' => (string)$order->getIncrementId(), 'order_store' => (int)$order->getStoreId(),
                'area' => $area, 'current_store' => $store,
                'async' => (int)$this->scopeConfig->getValue('sales_email/general/async_sending'),
                'send_email' => $order->getSendEmail(), 'email_sent' => $order->getEmailSent()];
        };
        StubLog::write('probe', 'order_sender_before', $ctx() + ['force_sync' => (bool)$forceSyncMode]);
        $trim = fn($p) => preg_replace('#^.*/(vendor|app/code|generated)/#', '$1/', (string)$p);
        try {
            $result = $proceed($order, $forceSyncMode);
        } catch (\Throwable $e) {
            $chain = [];
            for ($x = $e; $x; $x = $x->getPrevious()) {
                $chain[] = ['class' => get_class($x), 'message' => $x->getMessage(), 'at' => $trim($x->getFile()) . ':' . $x->getLine(),
                    'frames' => array_map(fn($f) => $trim($f['file'] ?? '') . ':' . ($f['line'] ?? '') . ' '
                        . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''), array_slice($x->getTrace(), 0, 25))];
            }
            StubLog::write('probe', 'order_sender_threw', $ctx() + ['chain' => $chain]);
            throw $e;
        }
        StubLog::write('probe', 'order_sender_after', $ctx() + ['result' => $result]);

        return $result;
    }
}
