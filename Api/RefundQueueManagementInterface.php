<?php

namespace Aplazo\AplazoPayment\Api;

interface RefundQueueManagementInterface
{
    /**
     * Process queued refunds (best-effort).
     *
     * @param int $batchSize
     * @return int Number of items attempted
     */
    public function process(int $batchSize = 20): int;
}

