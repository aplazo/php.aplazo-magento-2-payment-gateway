<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class TrackingService
{
    private const SCHEMA_VERSION = 1;

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
        private ProductMetadataInterface $productMetadata,
        private StoreManagerInterface $storeManager
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

        $this->sendEvent(self::EVENT_ORDER_CREATED, $properties, $this->buildContextForStoreId((int)$order->getStoreId()), $identity);
    }

    public function trackOrderPaid(OrderInterface $order, string $loanId, string $aplazoStatus): void
    {
        $properties = [
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

        $this->sendEvent(self::EVENT_ORDER_PAID, $properties, $this->buildContextForStoreId((int)$order->getStoreId()), $identity);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $context
     * @param array<string, string> $identity
     */
    public function sendEvent(string $eventName, array $properties, array $context = [], array $identity = [], int $eventVersion = 1): void
    {
        if (!isset($context['shopName']) || !is_string($context['shopName']) || $context['shopName'] === '') {
            $context['shopName'] = $this->inferShopNameFromBaseUrl();
        }

        $merchantId = (string)$this->aplazoHelper->getMerchantId();
        $properties = array_merge($properties, $context);
        if ($merchantId !== '') {
            $properties['merchantId'] = $merchantId;
        }
        foreach ($identity as $key => $value) {
            if ($value === '') {
                continue;
            }
            $properties[$key] = $value;
        }

        $payload = [
            'eventId' => $this->uuidV4(),
            'eventName' => $eventName,
            'occurredAt' => $this->nowIso8601Zulu(),
            // Tracking team requested using platform code here (SHOPI/PRESTA/WOO/MGT).
            'source' => $this->aplazoHelper->getPlatformCode(),
            'environment' => $this->aplazoHelper->getTrackingEnvironment(),
            'schemaVersion' => self::SCHEMA_VERSION,
            'eventVersion' => $eventVersion,
            'properties' => (object)$properties,
        ];

        $url = rtrim($this->aplazoHelper->getTrackingBaseUrl(), '/') . '/api/v1/tracking/events';

        try {
            $this->aplazoHelper->log(
                sprintf(
                    "Tracking event request.\nEVENT: %s\nURL: %s\nPAYLOAD: %s",
                    $eventName,
                    $url,
                    (string)json_encode($payload, JSON_UNESCAPED_SLASHES)
                )
            );

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::DEFAULT_CONNECT_TIMEOUT_SECONDS);
            $curl->setOption(CURLOPT_TIMEOUT, self::DEFAULT_TIMEOUT_SECONDS);

            $headers = ['Content-Type' => 'application/json'];
            $curl->setHeaders($headers);
            $curl->post($url, json_encode($payload, JSON_UNESCAPED_SLASHES));

            $status = (int)$curl->getStatus();
            $responseBody = (string)$curl->getBody();

            $this->aplazoHelper->log(
                sprintf(
                    "Tracking event response.\nEVENT: %s\nURL: %s\nSTATUS: %s\nBODY: %s",
                    $eventName,
                    $url,
                    $status,
                    $responseBody
                )
            );

            if (!in_array($status, [200, 201, 202], true)) {
                $this->aplazoHelper->log(
                    sprintf(
                        "Tracking event failed.\nEVENT: %s\nURL: %s\nSTATUS: %s\nBODY: %s",
                        $eventName,
                        $url,
                        $status,
                        $responseBody
                    )
                );
            }
        } catch (\Throwable $e) {
            // Never block commerce flows because of tracking.
            $this->aplazoHelper->log(
                sprintf(
                    "Tracking event exception.\nEVENT: %s\nURL: %s\nMESSAGE: %s",
                    $eventName,
                    $url,
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildModuleProperties(): array
    {
        $moduleInfo = $this->moduleList->getOne('Aplazo_AplazoPayment') ?: [];

        return [
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
    private function buildContextForStoreId(?int $storeId): array
    {
        $baseUrl = '';
        try {
            /** @var Store $store */
            $store = $storeId !== null ? $this->storeManager->getStore($storeId) : $this->storeManager->getStore();
            $baseUrl = (string)$store->getBaseUrl();
        } catch (\Throwable $e) {
            $baseUrl = '';
        }

        $shopName = $this->inferShopNameFromBaseUrl($baseUrl);

        return $shopName !== '' ? ['shopName' => $shopName] : [];
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

    private function inferShopNameFromBaseUrl(?string $baseUrl = null): string
    {
        $url = (string)($baseUrl ?: '');
        if ($url === '') {
            try {
                /** @var Store $store */
                $store = $this->storeManager->getStore();
                $url = (string)$store->getBaseUrl();
            } catch (\Throwable $e) {
                $url = '';
            }
        }

        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        $host = strtolower(trim($host));
        if ($host !== '') {
            return $host;
        }

        return rtrim($url, '/');
    }
}

