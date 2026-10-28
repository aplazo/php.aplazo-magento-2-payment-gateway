<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Logger\Logger;
use Aplazo\AplazoPayment\Service\LogService;

/**
 * Trace of the payment-first flow (BNPL-1664, finding H-2) without network inside its critical sections.
 *
 * LogService::send() is a synchronous POST to Aplazo (up to 7 s). Inside the conversion transaction or the attempt and
 * cart locks it would hold row locks and other requests for that long. add() writes the line to the module's own log
 * file (var/log/aplazo_payment/info.log) at once and keeps it; flush() hands what was kept to LogService, and is called
 * only after the locks are released. A slow or failing log service can therefore neither prolong a lock nor undo a
 * purchase (LogService already swallows its own errors). No personal data is passed in.
 */
class Trace
{
    private array $pending = [];

    public function __construct(
        private Logger $logger,
        private LogService $logService
    ) {
    }

    public function add(string $level, string $message, array $tags = [], array $attributes = []): void
    {
        $line = '[payment-first] ' . $message . ' ' . json_encode($attributes, JSON_UNESCAPED_SLASHES);
        $level === 'error' ? $this->logger->error($line) : $this->logger->info($line);
        $this->pending[] = [$level, $message, $tags, $attributes];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$level, $message, $tags, $attributes]) {
            $this->logService->send($level, $message, $tags, $attributes);
        }
    }
}
