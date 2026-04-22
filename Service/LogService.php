<?php

namespace Aplazo\AplazoPayment\Service;

use Aplazo\AplazoPayment\Helper\Data;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\ModuleListInterface;

class LogService
{
    private const LOG_PATH = '/api/v1/ps/logs';
    private const PLATFORM = 'magento';
    private const PLUGIN_NAME = 'aplazo-payment-gateway';
    private const CONNECT_TIMEOUT = 2;
    private const TIMEOUT = 5;

    private ?string $requestId = null;

    public function __construct(
        private CurlFactory $curlFactory,
        private Data $aplazoHelper,
        private ModuleListInterface $moduleList
    ) {
    }

    public function send(string $level, string $message, array $tags = [], array $attributes = []): void
    {
        try {
            if ($this->requestId === null) {
                $this->requestId = $this->uuidV4();
            }

            $moduleInfo = $this->moduleList->getOne('Aplazo_AplazoPayment') ?: [];
            $attributes['plugin_version'] = (string)($moduleInfo['setup_version'] ?? 'unknown');

            $baseUrl = $this->aplazoHelper->getServiceUrl();
            $storeName = $this->aplazoHelper->getStoreName();
            $storeUrl = $this->aplazoHelper->getStoreBaseUrl();
            $attributes['base_url'] = $baseUrl;
            $attributes['merchant_name'] = $storeName;
            $attributes['store_url'] = $storeUrl;

            $payload = [
                'platform' => self::PLATFORM,
                'merchant_id' => (string)$this->aplazoHelper->getMerchantId(),
                'merchant_name' => $storeName,
                'plugin' => self::PLUGIN_NAME,
                'environment' => $this->aplazoHelper->getTrackingEnvironment() === 'stg' ? 'staging' : 'production',
                'level' => $level,
                'message' => "[$storeUrl] $message",
                'request_id' => $this->requestId,
                'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
                'tags' => $tags,
                'attributes' => $attributes,
            ];

            $url = rtrim($this->aplazoHelper->getTrackingBaseUrl(), '/') . self::LOG_PATH;

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
            $curl->setHeaders(['Content-Type' => 'application/json']);
            $curl->post($url, json_encode($payload, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Fire-and-forget: never block commerce flows
        }
    }

    public function resetRequestId(): void
    {
        $this->requestId = null;
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
