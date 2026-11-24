<?php

namespace Aplazo\AplazoPayment\Test\Unit\Model;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\CredentialsValidator;
use Aplazo\AplazoPayment\Service\ApiService;
use PHPUnit\Framework\TestCase;

class CredentialsValidatorTest extends TestCase
{
    public function testValidationAlwaysCallsAuthInsteadOfTheCache(): void
    {
        $helper = $this->createMock(AplazoHelper::class);
        $helper->method('getMerchantId')->willReturn('1234');
        $helper->method('getApiToken')->willReturn('api-token');
        $apiService = $this->createMock(ApiService::class);
        $apiService->expects($this->once())
            ->method('getAuthorizationToken')
            ->with(false)
            ->willReturn('Bearer token');

        $result = (new CredentialsValidator($helper, $apiService))->validateCredentials();

        $this->assertSame(CredentialsValidator::USER_AUTHENTICATED, $result);
    }
}
