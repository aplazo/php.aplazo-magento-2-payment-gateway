<?php

namespace Aplazo\AplazoPayment\Model;

use Magento\Framework\Lock\LockManagerInterface;

class RefundLock
{
    private const LOCK_PREFIX = 'aplazo_refund_lock_';

    public function __construct(private LockManagerInterface $lockManager)
    {
    }

    public function acquire(string $key, int $timeoutSeconds = 0): bool
    {
        // Uses Magento lock backend (DB / cache-backed) which provides atomic acquisition.
        return $this->lockManager->lock($this->toLockName($key), $timeoutSeconds);
    }

    public function release(string $key): void
    {
        $this->lockManager->unlock($this->toLockName($key));
    }

    private function toLockName(string $key): string
    {
        // Avoid backend name length limits and illegal characters.
        return self::LOCK_PREFIX . sha1($key);
    }
}

