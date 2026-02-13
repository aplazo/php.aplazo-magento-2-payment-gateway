<?php

namespace Aplazo\AplazoPayment\Cron;

use Aplazo\AplazoPayment\Api\RefundQueueManagementInterface;

class ProcessRefundQueue
{
    public function __construct(
        private RefundQueueManagementInterface $refundQueueManagement
    ) {
    }

    public function execute(): void
    {
        $this->refundQueueManagement->process();
    }
}

