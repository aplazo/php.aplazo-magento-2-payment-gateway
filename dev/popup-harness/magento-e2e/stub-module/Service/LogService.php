<?php

namespace LocalDev\AplazoStub\Service;

/**
 * LOCAL TEST ONLY. Remote logs are recorded locally (message only) instead of being posted. With
 * var/localdev_aplazo_stub_log_delay holding N, each send waits N seconds first, like a slow log service (BNPL-1664 H-2).
 */
class LogService extends \Aplazo\AplazoPayment\Service\LogService
{
    public function send(string $level, string $message, array $tags = [], array $attributes = []): void
    {
        $file = BP . '/var/localdev_aplazo_stub_log_delay';
        $delay = is_file($file) ? min(30, max(0, (int)trim((string)@file_get_contents($file)))) : 0;
        if ($delay > 0) {
            sleep($delay);
        }
        StubLog::write('log', $level, ['message' => $message, 'order_id' => (string)($attributes['order_id'] ?? ''),
            'cart_id' => (string)($attributes['cart_id'] ?? ''), 'delay_seconds' => $delay]);
    }
}
