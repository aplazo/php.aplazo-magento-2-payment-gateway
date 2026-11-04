<?php

namespace LocalDev\AplazoStub\Service;

/**
 * LOCAL TEST ONLY. Tracking events are recorded locally instead of being sent.
 */
class TrackingService extends \Aplazo\AplazoPayment\Service\TrackingService
{
    public function sendEvent(string $eventName, array $properties, array $context = [], array $identity = [], int $eventVersion = 1): void
    {
        StubLog::write('tracking', 'sendEvent', ['event' => $eventName]);
    }
}
