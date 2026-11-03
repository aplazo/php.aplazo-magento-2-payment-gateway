<?php

namespace Aplazo\AplazoPayment\Test\Unit\Service;

use Aplazo\AplazoPayment\Service\TokenCache;
use PHPUnit\Framework\TestCase;

class TokenCacheTest extends TestCase
{
    use TokenTestTrait;

    public function testSavedTokenIsReturnedWhileValid(): void
    {
        $tokenCache = new TokenCache($this->inMemoryCache(), $this->helper());
        $token = self::bearer(time() + 86400);

        $tokenCache->save($token);

        $this->assertSame($token, $tokenCache->get());
    }

    public function testTokenWithinTheExpiryMarginIsNotCached(): void
    {
        $tokenCache = new TokenCache($this->inMemoryCache(), $this->helper());

        $tokenCache->save(self::bearer(time() + TokenCache::EXPIRY_MARGIN_SECONDS - 1));

        $this->assertNull($tokenCache->get());
    }

    public function testTokenWithoutExpIsNotCached(): void
    {
        $tokenCache = new TokenCache($this->inMemoryCache(), $this->helper());

        $tokenCache->save('Bearer not-a-jwt');

        $this->assertNull($tokenCache->get());
    }

    public function testChangedCredentialsAreACacheMiss(): void
    {
        $cache = $this->inMemoryCache();
        (new TokenCache($cache, $this->helper('old-api-token')))->save(self::bearer(time() + 86400));

        $this->assertNull((new TokenCache($cache, $this->helper('new-api-token')))->get());
    }

    public function testCleanRemovesTheToken(): void
    {
        $tokenCache = new TokenCache($this->inMemoryCache(), $this->helper());
        $tokenCache->save(self::bearer(time() + 86400));

        $tokenCache->clean();

        $this->assertNull($tokenCache->get());
    }
}
