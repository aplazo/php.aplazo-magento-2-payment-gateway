<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Sales\Api\Data\OrderInterface;

class TrackingService
{
    private const SOURCE_BACKEND = 'backend';
    private const SCHEMA_VERSION = 1;
    private const PLATFORM_PROPERTY = 'platform';
    private const PLATFORM_CONTEXT_KEY = 'platform';

    /**
     * Keep these stable; they become analytics contract.
     */
    public const EVENT_PLUGIN_INSTALLED = 'plugin_installed';
    public const EVENT_PLUGIN_UNINSTALLED = 'plugin_uninstalled';
    public const EVENT_ORDER_CREATED = 'order_created';
    public const EVENT_ORDER_PAID = 'order_paid';
    public const EVENT_REFUND_CREATED = 'refund_created';

    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 2;
    private const DEFAULT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private CurlFactory $curlFactory,
        private Data $aplazoHelper,
        private ModuleListInterface $moduleList,
        private ProductMetadataInterface $productMetadata
    ) {
    }

    public function trackModuleInstalled(): void
    {
        $this->sendEvent(
            self::EVENT_PLUGIN_INSTALLED,
            $this->buildModuleProperties()
        );
    }

    public function trackModuleUninstalled(): void
    {
        $this->sendEvent(
            self::EVENT_PLUGIN_UNINSTALLED,
            $this->buildModuleProperties()
        );
    }

    public function trackOrderCreated(OrderInterface $order): void
    {
        $properties = [
            self::PLATFORM_PROPERTY => $this->aplazoHelper->getPlatformCode(),
            'order_id' => (int)$order->getEntityId(),
            'order_increment_id' => (string)$order->getIncrementId(),
            'store_id' => (int)$order->getStoreId(),
            'grand_total' => (float)$order->getGrandTotal(),
            'currency' => (string)($order->getOrderCurrencyCode() ?: $order->getBaseCurrencyCode() ?: ''),
            'items_count' => (int)$order->getTotalItemCount(),
            'is_guest' => (bool)$order->getCustomerIsGuest(),
        ];

        $customerId = $order->getCustomerId();
        $identity = $customerId ? ['customerId' => (string)$customerId] : [];

        $this->sendEvent(self::EVENT_ORDER_CREATED, $properties, $this->defaultContext(), $identity);
    }

    public function trackOrderPaid(OrderInterface $order, string $loanId, string $aplazoStatus): void
    {
        $properties = [
            self::PLATFORM_PROPERTY => $this->aplazoHelper->getPlatformCode(),
            'order_id' => (int)$order->getEntityId(),
            'order_increment_id' => (string)$order->getIncrementId(),
            'store_id' => (int)$order->getStoreId(),
            'grand_total' => (float)$order->getGrandTotal(),
            'currency' => (string)($order->getOrderCurrencyCode() ?: $order->getBaseCurrencyCode() ?: ''),
            'loan_id' => $loanId,
            'aplazo_status' => $aplazoStatus,
        ];

        $customerId = $order->getCustomerId();
        $identity = $customerId ? ['customerId' => (string)$customerId] : [];

        $this->sendEvent(self::EVENT_ORDER_PAID, $properties, $this->defaultContext(), $identity);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $context
     * @param array<string, string> $identity
     */
    public function sendEvent(string $eventName, array $properties, array $context = [], array $identity = [], int $eventVersion = 1): void
    {
        if (!$this->aplazoHelper->getTrackingEnabled()) {
            return;
        }

        if (!isset($properties[self::PLATFORM_PROPERTY])) {
            $properties[self::PLATFORM_PROPERTY] = $this->aplazoHelper->getPlatformCode();
        }
        if (!isset($context[self::PLATFORM_CONTEXT_KEY])) {
            $context[self::PLATFORM_CONTEXT_KEY] = $this->aplazoHelper->getPlatformCode();
        }

        $merchantId = (string)$this->aplazoHelper->getMerchantId();

        $payload = [
            'eventId' => $this->uuidV4(),
            'eventName' => $eventName,
            'occurredAt' => $this->nowIso8601Zulu(),
            'source' => self::SOURCE_BACKEND,
            'environment' => $this->aplazoHelper->getTrackingEnvironment(),
            'schemaVersion' => self::SCHEMA_VERSION,
            'eventVersion' => $eventVersion,
            'properties' => (object)$properties,
            'context' => (object)$context,
        ];

        if ($merchantId !== '') {
            $payload['merchantId'] = $merchantId;
        }

        foreach ($identity as $key => $value) {
            if ($value === '') {
                continue;
            }
            $payload[$key] = $value;
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::DEFAULT_CONNECT_TIMEOUT_SECONDS);
            $curl->setOption(CURLOPT_TIMEOUT, self::DEFAULT_TIMEOUT_SECONDS);

            $headers = ['Content-Type' => 'application/json'];
            $curl->setHeaders($headers);

            $url = rtrim($this->aplazoHelper->getTrackingBaseUrl(), '/') . '/api/v1/tracking/events';
            $curl->post($url, json_encode($payload, JSON_UNESCAPED_SLASHES));

            $status = (int)$curl->getStatus();
            if (!in_array($status, [200, 201, 202], true)) {
                $this->aplazoHelper->log(
                    sprintf('Tracking event failed. status=%s body=%s', $status, (string)$curl->getBody())
                );
            }
        } catch (\Throwable $e) {
            // Never block commerce flows because of tracking.
            $this->aplazoHelper->log('Tracking event exception: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildModuleProperties(): array
    {
        $moduleInfo = $this->moduleList->getOne('Aplazo_AplazoPayment') ?: [];

        return [
            self::PLATFORM_PROPERTY => $this->aplazoHelper->getPlatformCode(),
            'module_name' => 'Aplazo_AplazoPayment',
            'module_setup_version' => (string)($moduleInfo['setup_version'] ?? ''),
            'module_composer_version' => (string)($moduleInfo['version'] ?? ''),
            'magento_version' => (string)$this->productMetadata->getVersion(),
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultContext(): array
    {
        return [self::PLATFORM_CONTEXT_KEY => $this->aplazoHelper->getPlatformCode()];
    }

    private function nowIso8601Zulu(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

