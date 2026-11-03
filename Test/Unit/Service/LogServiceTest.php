<?php

namespace Aplazo\AplazoPayment\Test\Unit\Service;

use Aplazo\AplazoPayment\Service\LogService;
use Aplazo\AplazoPayment\Service\TokenCache;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LogServiceTest extends TestCase
{
    use TokenTestTrait;

    /** @var Curl&MockObject */
    private $curl;
    private array $sentHeaders = [];
    /** @var string[] */
    private array $postedUrls = [];
    private TokenCache $tokenCache;
    private LogService $logService;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('setHeaders')->willReturnCallback(function (array $headers) {
            $this->sentHeaders = $headers;
        });
        $this->curl->method('post')->willReturnCallback(function ($url) {
            $this->postedUrls[] = $url;
        });
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $helper = $this->helper();
        $this->tokenCache = new TokenCache($this->inMemoryCache(), $helper);
        $this->logService = new LogService(
            $curlFactory,
            $helper,
            $this->createMock(ModuleListInterface::class),
            $this->tokenCache
        );
    }

    public function testCachedTokenIsSentAsBearerWithoutCallingAuth(): void
    {
        $token = self::bearer(time() + 86400);
        $this->tokenCache->save($token);

        $this->logService->send('info', 'Creating loan');

        $this->assertSame($token, $this->sentHeaders['Authorization'] ?? null);
        $this->assertSame(['https://plugins.aplazo.net/api/v1/ps/logs'], $this->postedUrls);
    }

    public function testWithoutCachedTokenNoAuthorizationHeaderIsSent(): void
    {
        $this->logService->send('info', 'Creating loan');

        $this->assertArrayNotHasKey('Authorization', $this->sentHeaders);
        $this->assertSame('application/json', $this->sentHeaders['Content-Type']);
        $this->assertSame(['https://plugins.aplazo.net/api/v1/ps/logs'], $this->postedUrls);
    }

    public function testFailedLogRequestNeverReachesTheCaller(): void
    {
        $this->tokenCache->save(self::bearer(time() + 86400));
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willThrowException(new \Exception('Operation timed out after 5000 milliseconds'));
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);
        $logService = new LogService(
            $curlFactory,
            $this->helper(),
            $this->createMock(ModuleListInterface::class),
            $this->tokenCache
        );

        $logService->send('error', 'createLoan error');

        $this->addToAssertionCount(1);
    }
}
