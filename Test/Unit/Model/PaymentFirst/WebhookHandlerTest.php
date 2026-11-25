<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Test\Unit\Model\PaymentFirst;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Aplazo\AplazoPayment\Model\PaymentFirst\Finalizer;
use Aplazo\AplazoPayment\Model\PaymentFirst\Trace;
use Aplazo\AplazoPayment\Model\PaymentFirst\WebhookHandler;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * R-10: a valid signature, the merchant and the cartId do not attribute a loan to an attempt. A known loan id is never
 * replaced, a different one is refused, and an attempt without loan id is held for review, never converted.
 */
class WebhookHandlerTest extends TestCase
{
    private const ATTEMPT = ['entity_id' => 7, 'cart_id' => '000000635', 'store_id' => 1, 'merchant_id' => '37',
        'customer_id' => null, 'loan_id' => '396107', 'status' => 'open', 'last_error' => null];

    public function testDuplicateWebhooksForTheKnownLoanOnlySignalTheCheck(): void
    {
        $store = $this->createMock(AttemptStore::class);
        $store->expects($this->never())->method('transition');
        $finalizer = $this->createMock(Finalizer::class);
        $finalizer->expects($this->exactly(2))->method('finalize')
            ->with(self::ATTEMPT, Finalizer::SOURCE_WEBHOOK, 10)
            ->willReturnOnConsecutiveCalls(
                ['status' => Finalizer::COMPLETED, 'attempt' => self::ATTEMPT],
                ['status' => Finalizer::COMPLETED, 'attempt' => self::ATTEMPT]
            );
        $handler = $this->handler($store, $finalizer);
        $claims = ['merchantId' => 37, 'loanId' => 396107, 'cartId' => '000000635'];

        $this->assertTrue($handler->handle(self::ATTEMPT, $claims)['status']);
        $this->assertTrue($handler->handle(self::ATTEMPT, $claims)['status']);
    }

    public function testConflictingLoanIdIsRefusedAndNeverWritten(): void
    {
        $store = $this->createMock(AttemptStore::class);
        $store->expects($this->never())->method('transition');
        $finalizer = $this->createMock(Finalizer::class);
        $finalizer->expects($this->never())->method('finalize');

        $result = $this->handler($store, $finalizer)->handle(self::ATTEMPT, ['merchantId' => 37, 'loanId' => 26701]);

        $this->assertFalse($result['status']);
    }

    public function testAttemptWithoutLoanIdIsHeldAndNotConverted(): void
    {
        $attempt = ['loan_id' => null, 'status' => 'uncertain'] + self::ATTEMPT;
        $store = $this->createMock(AttemptStore::class);
        $store->expects($this->once())->method('transition')
            ->with($attempt, ['last_error' => WebhookHandler::UNATTRIBUTED . '26701'])
            ->willReturn(['last_error' => WebhookHandler::UNATTRIBUTED . '26701'] + $attempt);
        $finalizer = $this->createMock(Finalizer::class);
        $finalizer->expects($this->never())->method('finalize');

        $result = $this->handler($store, $finalizer)->handle($attempt, ['merchantId' => 37, 'loanId' => 26701]);

        $this->assertSame(['status' => false, 'message' => 'held_for_review'], $result);
    }

    public function testRepeatedAmbiguousWebhookWritesNothingMore(): void
    {
        $attempt = ['loan_id' => null, 'status' => 'uncertain', 'last_error' => WebhookHandler::UNATTRIBUTED . '26701'] + self::ATTEMPT;
        $store = $this->createMock(AttemptStore::class);
        $store->expects($this->never())->method('transition');
        $finalizer = $this->createMock(Finalizer::class);
        $finalizer->expects($this->never())->method('finalize');

        $this->assertFalse($this->handler($store, $finalizer)->handle($attempt, ['merchantId' => 37, 'loanId' => 26701])['status']);
    }

    public function testOtherMerchantIsRefused(): void
    {
        $store = $this->createMock(AttemptStore::class);
        $store->expects($this->never())->method('transition');
        $finalizer = $this->createMock(Finalizer::class);
        $finalizer->expects($this->never())->method('finalize');

        $this->assertFalse($this->handler($store, $finalizer)->handle(self::ATTEMPT, ['merchantId' => 137, 'loanId' => 396107])['status']);
    }

    private function handler(AttemptStore $store, Finalizer $finalizer): WebhookHandler
    {
        return new WebhookHandler($store, $finalizer, $this->createMock(ScopeConfigInterface::class), $this->createMock(Trace::class));
    }
}
