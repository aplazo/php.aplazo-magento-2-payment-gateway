<?php

namespace LocalDev\AplazoStub\Observer;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * LOCAL TEST ONLY (BNPL-1664). Synchronization points for the payment-first finalizer's events, so a test can order
 * concurrent requests and stop a process at a precise step. var/localdev_pf_<event> holds:
 *   pause:<seconds>  sleep there; var/localdev_pf_<event>.inside exists while sleeping
 *   crash            the file is removed and the PHP process exits there (a process dying mid-way)
 * <event> is the event name without the "aplazo_payment_first_" prefix: convert_before, order_saved, approve_before.
 */
class SyncPoint implements ObserverInterface
{
    public function execute(Observer $observer)
    {
        $name = substr($observer->getEvent()->getName(), strlen('aplazo_payment_first_'));
        $file = BP . '/var/localdev_pf_' . $name;
        $plan = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        if ($plan === 'crash') {
            @unlink($file);
            StubLog::write('sync', $name, ['plan' => 'crash']);
            exit(1);
        }
        if (strpos($plan, 'pause:') === 0) {
            $seconds = min(60, max(0, (int)substr($plan, 6)));
            StubLog::write('sync', $name, ['plan' => 'pause', 'seconds' => $seconds]);
            @touch($file . '.inside');
            sleep($seconds);
            @unlink($file . '.inside');
        }
    }
}
