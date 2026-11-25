<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Test\Unit\Model\PaymentFirst;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptContext;
use Aplazo\AplazoPayment\Model\PaymentFirst\LoanStatusChecker;
use Aplazo\AplazoPayment\Model\PaymentFirst\StoreScope;
use Aplazo\AplazoPayment\Service\ApiService;
use PHPUnit\Framework\TestCase;

class LoanStatusCheckerTest extends TestCase
{
    private const ATTEMPT = ['store_id' => 1, 'cart_id' => '000000100', 'loan_id' => 'L-1'];

    /**
     * @dataProvider answers
     */
    public function testStatusComesOnlyFromThisAttemptsLoan($answer, array $attempt, string $expected): void
    {
        $this->assertSame($expected, $this->checker($answer)->check($attempt));
    }

    public static function answers(): array
    {
        return [
            'paid' => [[['loanId' => 'L-1', 'status' => 'OUTSTANDING']], self::ATTEMPT, LoanStatusChecker::PAID],
            'not paid' => [[['loanId' => 'L-1', 'status' => 'REQUEST']], self::ATTEMPT, LoanStatusChecker::NOT_PAID],
            'answer without loan id' => [[['status' => 'REQUEST']], self::ATTEMPT, LoanStatusChecker::NOT_PAID],
            'attempt without loan id' => [[['loanId' => 'L-9', 'status' => 'REQUEST']], ['loan_id' => null] + self::ATTEMPT, LoanStatusChecker::NOT_PAID],
            'only another loan id' => [[['loanId' => 'L-2', 'status' => 'REQUEST']], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'another loan id paid' => [[['loanId' => 'L-2', 'status' => 'OUTSTANDING']], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'empty list' => [[], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'communication failed or absent loan' => [false, self::ATTEMPT, LoanStatusChecker::UNKNOWN],
            'entry without status' => [[['loanId' => 'L-1']], self::ATTEMPT, LoanStatusChecker::UNKNOWN],
        ];
    }

    private function checker($answer): LoanStatusChecker
    {
        $api = $this->createMock(ApiService::class);
        $api->method('getLoanStatus')->with('000000100')->willReturn($answer);
        $scope = $this->createMock(StoreScope::class);
        $scope->method('run')->willReturnCallback(fn (int $storeId, callable $fn) => $fn());
        $context = $this->createMock(AttemptContext::class);
        $context->method('mismatch')->willReturn(null);

        return new LoanStatusChecker($api, $scope, $context);
    }
}
