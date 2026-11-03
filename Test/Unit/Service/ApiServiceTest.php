<?php

namespace Aplazo\AplazoPayment\Test\Unit\Service;

use Aplazo\AplazoPayment\Service\ApiService;
use Aplazo\AplazoPayment\Service\LogService;
use Aplazo\AplazoPayment\Service\TokenCache;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiServiceTest extends TestCase
{
    use TokenTestTrait;

    private const AUTH_URL = 'https://api.aplazo.net/api/auth';
    private const LOAN_URL = 'https://api.aplazo.net/api/loan';

    /** @var Curl&MockObject */
    private $curl;
    /** @var string[] */
    private array $postedUrls = [];
    /** @var int[] status code per URL, consumed in order */
    private array $statuses = [];
    private int $lastStatus = 0;
    private int $issuedTokens = 0;

    private ApiService $apiService;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('post')->willReturnCallback(function ($url) {
            $this->postedUrls[] = $url;
            $this->lastStatus = empty($this->statuses[$url]) ? 200 : array_shift($this->statuses[$url]);
        });
        $this->curl->method('getStatus')->willReturnCallback(fn() => $this->lastStatus);
        $this->curl->method('getBody')->willReturnCallback(function () {
            if (end($this->postedUrls) === self::AUTH_URL) {
                $this->issuedTokens++;
                return json_encode(['Authorization' => self::bearer(time() + 86400 + $this->issuedTokens)]);
            }
            return json_encode(['url' => 'https://checkout.aplazo.net']);
        });
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $helper = $this->helper();
        $this->apiService = new ApiService(
            $curlFactory,
            $helper,
            $this->createMock(LogService::class),
            new TokenCache($this->inMemoryCache(), $helper)
        );
    }

    public function testTwoCheckoutsWithinTheTokenLifetimeAuthenticateOnce(): void
    {
        foreach ([1, 2] as $checkout) {
            $token = $this->apiService->getAuthorizationToken();
            $this->apiService->createLoan(['cartId' => $checkout], $token);
        }

        $this->assertSame(1, $this->countPosts(self::AUTH_URL));
        $this->assertSame(2, $this->countPosts(self::LOAN_URL));
    }

    public function testWithoutCacheAlwaysCallsAuth(): void
    {
        $this->apiService->getAuthorizationToken();
        $this->apiService->getAuthorizationToken(false);
        $this->apiService->getAuthorizationToken(false);

        $this->assertSame(3, $this->countPosts(self::AUTH_URL));
    }

    public function testFreshTokenRefreshesTheCache(): void
    {
        $this->apiService->getAuthorizationToken();
        $fresh = $this->apiService->getAuthorizationToken(false);

        $this->assertSame($fresh, $this->apiService->getAuthorizationToken());
        $this->assertSame(2, $this->countPosts(self::AUTH_URL));
    }

    public function testFailedAuthClearsTheCache(): void
    {
        $this->apiService->getAuthorizationToken();
        $this->statuses[self::AUTH_URL] = [401];

        try {
            $this->apiService->getAuthorizationToken(false);
            $this->fail('Expected an authentication exception');
        } catch (\Magento\Framework\Exception\AuthenticationException $e) {
        }
        $this->apiService->getAuthorizationToken();

        $this->assertSame(3, $this->countPosts(self::AUTH_URL));
    }

    public function testRejectedCachedTokenIsReplacedAndTheLoanRetriedOnce(): void
    {
        $cached = $this->apiService->getAuthorizationToken();
        $this->statuses[self::LOAN_URL] = [401];

        $response = $this->apiService->createLoan(['cartId' => 1], $cached);

        $this->assertSame(['url' => 'https://checkout.aplazo.net'], $response);
        $this->assertSame(2, $this->countPosts(self::AUTH_URL));
        $this->assertSame(2, $this->countPosts(self::LOAN_URL));
    }

    public function testOtherLoanErrorsAreNotRetried(): void
    {
        $token = $this->apiService->getAuthorizationToken();
        $this->statuses[self::LOAN_URL] = [500];

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        try {
            $this->apiService->createLoan(['cartId' => 1], $token);
        } finally {
            $this->assertSame(1, $this->countPosts(self::LOAN_URL));
            $this->assertSame(1, $this->countPosts(self::AUTH_URL));
        }
    }

    private function countPosts(string $url): int
    {
        return count(array_keys($this->postedUrls, $url, true));
    }
}
