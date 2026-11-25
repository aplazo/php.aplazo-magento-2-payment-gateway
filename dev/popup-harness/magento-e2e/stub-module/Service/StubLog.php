<?php

namespace LocalDev\AplazoStub\Service;

/**
 * Appends one JSON line per stubbed call to var/log/localdev_aplazo_stub.log (no payloads, tokens or buyer data).
 */
class StubLog
{
    public static function write(string $service, string $method, array $context = []): void
    {
        $line = json_encode(['at' => gmdate('c'), 'service' => $service, 'method' => $method] + $context, JSON_UNESCAPED_SLASHES);
        @file_put_contents(BP . '/var/log/localdev_aplazo_stub.log', $line . PHP_EOL, FILE_APPEND);
    }
}
