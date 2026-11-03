<?php

namespace Aplazo\AplazoPayment\Test\Unit\Service;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Framework\App\CacheInterface;

trait TokenTestTrait
{
    /** @var array<string, string> */
    private array $cacheStorage = [];

    private function inMemoryCache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn($id) => $this->cacheStorage[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->cacheStorage[$id] = $data;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($id) {
            unset($this->cacheStorage[$id]);
            return true;
        });
        return $cache;
    }

    private function helper(string $apiToken = 'api-token'): AplazoHelper
    {
        $helper = $this->createMock(AplazoHelper::class);
        $helper->method('getServiceUrl')->willReturn('https://api.aplazo.net');
        $helper->method('getMerchantId')->willReturn('1234');
        $helper->method('getApiToken')->willReturnCallback(fn() => $apiToken);
        $helper->method('getTrackingBaseUrl')->willReturn('https://plugins.aplazo.net');
        $helper->method('getTrackingEnvironment')->willReturn('stg');
        $helper->method('getStoreName')->willReturn('Store');
        $helper->method('getStoreBaseUrl')->willReturn('https://store.test');
        return $helper;
    }

    private static function bearer(int $exp): string
    {
        $b64 = fn(array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        return 'Bearer ' . $b64(['alg' => 'HS512']) . '.' . $b64(['sub' => '1234', 'exp' => $exp]) . '.signature';
    }
}
