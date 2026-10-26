<?php

namespace LocalDev\AplazoStub\Service;

/**
 * LOCAL TEST ONLY. Remote logs are recorded locally (message only) instead of being posted.
 */
class LogService extends \Aplazo\AplazoPayment\Service\LogService
{
    public function send(string $level, string $message, array $tags = [], array $attributes = []): void
    {
        StubLog::write('log', $level, ['message' => $message, 'order_id' => (string)($attributes['order_id'] ?? '')]);
    }
}
