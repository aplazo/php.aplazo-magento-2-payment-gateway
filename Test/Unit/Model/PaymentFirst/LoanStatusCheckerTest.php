<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Test\Unit\Model\PaymentFirst;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptContext;
use Aplazo\AplazoPayment\Model\PaymentFirst\LoanStatusChecker;
use Aplazo\AplazoPayment\Model\PaymentFirst\StoreScope;
use Aplazo\AplazoPayment\Service\ApiService;
use PHPUnit\Framework\TestCase;

/**
 * The status API is queried by cartId and a cartId can list loans of other stores or tests (R-10, observed in STG):
 * only the loan persisted on the attempt, matched exactly by loanId, decides.
 */
class LoanStatusCheckerTest extends TestCase
{
    private const ATTEMPT = ['store_id' => 1, 'cart_id' => '000000635', 'loan_id' => '396107'];

    /**
     * @dataProvider answers
     */
    public function testStatusComesOnlyFromThisAttemptsLoan($answer, array $attempt, string $expected): void
    {
        $this->assertSame($expected, $this->checker($answer)->check($attempt));
    }

    public static function answers(): array
    {
        // The shape STG answered on 2026-11-24 for one cartId: four loans, two OUTSTANDING, loanId as integers.
        $stg = [
            ['loanId' => 396107, 'status' => 'OUTSTANDING'],
            ['loanId' => 235354, 'status' => 'REQUEST'],
            ['loanId' => 144051, 'status' => 'CANCELLED'],
            ['loanId' => 26701, 'status' => 'OUTSTANDING'],
        ];
        $ownUnpaid = [
            ['loanId' => 26701, 'status' => 'OUTSTANDING'],
            ['loanId' => 396107, 'status' => 'REQUEST'],
        ];

        return [
            'own loan paid among others of the same cartId' => [$stg, self::ATTEMPT, LoanStatusChecker::PAID],
            'own loan unpaid, another one paid' => [$ownUnpaid, self::ATTEMPT, LoanStatusChecker::NOT_PAID],
            'own loan unpaid alone' => [[['loanId' => '396107', 'status' => 'REQUEST']], self::ATTEMPT, LoanStatusChecker::NOT_PAID],
            'attempt without loan id, a foreign loan paid' => [$stg, ['loan_id' => null] + self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'attempt with empty loan id' => [$stg, ['loan_id' => ''] + self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'only other loan ids, one paid' => [[['loanId' => 26701, 'status' => 'OUTSTANDING']], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'entries without loan id' => [[['status' => 'OUTSTANDING']], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'own loan listed twice with different statuses' => [
                [['loanId' => 396107, 'status' => 'OUTSTANDING'], ['loanId' => '396107', 'status' => 'REQUEST']],
                self::ATTEMPT, LoanStatusChecker::UNKNOWN,
            ],
            'loan id only as a prefix' => [[['loanId' => 3961070, 'status' => 'OUTSTANDING']], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'empty list' => [[], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'communication failed or absent loan' => [false, self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'entry without status' => [[['loanId' => 396107]], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
        ];
    }

    public function testWithoutLoanIdAplazoIsNotAsked(): void
    {
        $api = $this->createMock(ApiService::class);
        $api->expects($this->never())->method('getLoanStatus');
        $scope = $this->createMock(StoreScope::class);
        $context = $this->createMock(AttemptContext::class);
        $context->method('mismatch')->willReturn(null);

        $this->assertSame(LoanStatusChecker::UNKNOWN, (new LoanStatusChecker($api, $scope, $context))->check(['loan_id' => null] + self::ATTEMPT));
    }

    private function checker($answer): LoanStatusChecker
    {
        $api = $this->createMock(ApiService::class);
        $api->method('getLoanStatus')->with('000000635')->willReturn($answer);
        $scope = $this->createMock(StoreScope::class);
        $scope->method('run')->willReturnCallback(fn (int $storeId, callable $fn) => $fn());
        $context = $this->createMock(AttemptContext::class);
        $context->method('mismatch')->willReturn(null);

        return new LoanStatusChecker($api, $scope, $context);
    }
}
