<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Console\Command;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Aplazo\AplazoPayment\Model\PaymentFirst\Reconciler;
use Aplazo\AplazoPayment\Model\PaymentFirst\Trace;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operations view of the popup payment-first attempts (BNPL-1664), without personal data.
 *
 *   bin/magento aplazo:payment-first:attempts [--status=rejected] [--id=12 ...]     list (payment, conversion, reversal)
 *   bin/magento aplazo:payment-first:attempts --attention [--older-than=600]        what needs a person; exit code 1 if any
 *   bin/magento aplazo:payment-first:attempts --reconcile [--id=12 ...]             run the reconciliation now
 *   bin/magento aplazo:payment-first:attempts --resolve=12 --outcome=reversal_confirmed --reference=CASE-1 [--note=...] --confirm
 *
 * Anyone who can run bin/magento can run this (operating-system permission, no Magento ACL). The listing and
 * --attention only read. --reconcile can create orders, only for loans the status API reports paid, and can call
 * cancelLoan only if the reversal was turned on in di.xml (off by default, contract C5). --resolve changes no loan and
 * no order: it records what an operator confirmed with Aplazo or the merchant outside this module, and refuses to run
 * without --reference and --confirm, on an attempt that is not a paid attempt without order, or a second time.
 */
class PaymentAttemptsCommand extends Command
{
    private const COLUMNS = ['entity_id', 'cart_id', 'quote_id', 'store_id', 'status', 'order_id', 'reject_reason',
        'loan_cancel_state', 'paid_confirmed_at', 'check_count', 'next_check_at', 'last_error', 'resolution',
        'resolution_reference', 'resolved_by', 'resolved_at', 'created_at'];
    private const CRON_JOB = 'aplazo_payment_first_reconcile';
    private const CRON_STALE_SECONDS = 900;
    private const REVERSAL_TEXT = [
        AttemptStore::CANCEL_PENDING => 'not requested (pending contract C5)',
        AttemptStore::CANCEL_REQUESTED => 'request being sent',
        AttemptStore::CANCEL_ANSWERED => 'Aplazo answered the cancel request; reversal NOT verified',
        AttemptStore::CANCEL_UNKNOWN => 'cancel request failed or was interrupted; unknown whether Aplazo received it',
    ];

    public function __construct(
        private ResourceConnection $resource,
        private Reconciler\Proxy $reconciler,
        private State $appState,
        private AttemptStore\Proxy $attemptStore,
        private Trace\Proxy $trace
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('aplazo:payment-first:attempts')
            ->setDescription('List Aplazo popup payment attempts, show what needs attention, reconcile or record a resolution')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only attempts in this status')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these attempt ids')
            ->addOption('attention', null, InputOption::VALUE_NONE, 'Paid attempts without order, unresolved rejections, stale cron')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Seconds a paid attempt may wait for its order', '600')
            ->addOption('reconcile', null, InputOption::VALUE_NONE, 'Run the reconciliation now')
            ->addOption('resolve', null, InputOption::VALUE_REQUIRED, 'Attempt id to record a resolution for')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED, implode('|', AttemptStore::RESOLUTIONS))
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Aplazo case / reversal id, or the order increment created by hand')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Short note, no personal data')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Write the resolution (without it, only shows what would be written)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ids = array_map('intval', (array)$input->getOption('id'));
        if ($input->getOption('reconcile')) {
            $report = $this->appState->emulateAreaCode(Area::AREA_CRONTAB, function () use ($ids) {
                return $this->reconciler->run($ids ?: null);
            });
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }
        if ($input->getOption('resolve') !== null) {
            return $this->resolve($input, $output);
        }
        if ($input->getOption('attention')) {
            $attention = $this->attention(max(0, (int)$input->getOption('older-than')));
            $output->writeln(json_encode($attention, JSON_PRETTY_PRINT));

            return array_filter($attention) ? Command::FAILURE : Command::SUCCESS;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName(AttemptStore::TABLE), self::COLUMNS)
            ->order('entity_id DESC')->limit(100);
        if ($input->getOption('status')) {
            $select->where('status = ?', (string)$input->getOption('status'));
        }
        if ($ids) {
            $select->where('entity_id IN (?)', $ids);
        }
        $output->writeln(json_encode(array_map([$this, 'describe'], $connection->fetchAll($select)), JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    /**
     * Payment, conversion and reversal said separately, so "the order was not created" is never read as "the money
     * was returned".
     */
    private function describe(array $row): array
    {
        $paid = !empty($row['paid_confirmed_at'])
            || in_array($row['status'], [AttemptStore::COMPLETED, AttemptStore::ORDER_CREATED, AttemptStore::REJECTED], true);
        $row['payment'] = $paid ? 'confirmed by the status API' : 'not confirmed';
        $row['conversion'] = [
            AttemptStore::COMPLETED => 'order created and approved',
            AttemptStore::ORDER_CREATED => 'order created, approval pending',
            AttemptStore::REJECTED => 'no order: the cart could not become this order (' . $row['reject_reason'] . ')',
            AttemptStore::NEEDS_REVIEW => 'no live order: needs a person (' . $row['last_error'] . ')',
        ][$row['status']] ?? 'no order yet';
        $row['reversal'] = $row['status'] === AttemptStore::REJECTED || !empty($row['loan_cancel_state'])
            ? (self::REVERSAL_TEXT[$row['loan_cancel_state']] ?? 'none') : 'not applicable';
        if (!empty($row['resolution'])) {
            $row['reversal'] .= '; operator recorded: ' . $row['resolution'] . ' (' . $row['resolution_reference'] . ')';
        }

        return $row;
    }

    private function attention(int $olderThan): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(AttemptStore::TABLE);
        $cut = gmdate('Y-m-d H:i:s', time() - $olderThan);
        $ids = function ($select) use ($connection) {
            return array_map('intval', $connection->fetchCol($select));
        };
        $base = function () use ($connection, $table) {
            return $connection->select()->from(['a' => $table], ['entity_id'])->where('a.resolution IS NULL');
        };
        $lastCron = $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('cron_schedule'), ['MAX(finished_at)'])
                ->where('job_code = ?', self::CRON_JOB)->where('status = ?', 'success')
        );
        $waiting = (int)$connection->fetchOne($connection->select()->from($table, ['COUNT(*)'])
            ->where('status IN (?)', AttemptStore::RECONCILED)->where('resolution IS NULL'));
        $cronStale = $waiting > 0 && (!$lastCron || strtotime($lastCron . ' UTC') < time() - self::CRON_STALE_SECONDS);

        return [
            // The status API said paid, and there is still no approved order after the grace period.
            'paid_without_order' => $ids($base()->where('a.paid_confirmed_at IS NOT NULL')->where('a.paid_confirmed_at <= ?', $cut)
                ->where('a.status IN (?)', array_merge(AttemptStore::PAYABLE, [AttemptStore::ORDER_CREATED]))),
            'rejected_unresolved' => $ids($base()->where('a.status = ?', AttemptStore::REJECTED)),
            'needs_review_unresolved' => $ids($base()->where('a.status = ?', AttemptStore::NEEDS_REVIEW)),
            'context_changed' => $ids($base()->where('a.last_error LIKE ?', 'context_changed:%')
                ->where('a.status IN (?)', AttemptStore::PAYABLE)),
            'order_canceled' => $ids($base()->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = a.order_id', [])
                ->where('o.state = ?', 'canceled')),
            'cron_stale' => $cronStale ? [['job' => self::CRON_JOB, 'last_success' => $lastCron ?: null, 'attempts_waiting' => $waiting]] : [],
        ];
    }

    private function resolve(InputInterface $input, OutputInterface $output): int
    {
        $attempt = $this->attemptStore->get((int)$input->getOption('resolve'));
        $outcome = (string)$input->getOption('outcome');
        $reference = trim((string)$input->getOption('reference'));
        $note = substr(trim((string)$input->getOption('note')), 0, 255);
        $error = null;
        if ($attempt === null) {
            $error = 'attempt not found';
        } elseif (!in_array($attempt['status'], [AttemptStore::REJECTED, AttemptStore::NEEDS_REVIEW], true)) {
            $error = 'only a rejected or needs_review attempt (paid, no live order) can be resolved';
        } elseif (!empty($attempt['resolution'])) {
            $error = 'already resolved: ' . $attempt['resolution'];
        } elseif (!in_array($outcome, AttemptStore::RESOLUTIONS, true)) {
            $error = '--outcome must be one of ' . implode(', ', AttemptStore::RESOLUTIONS);
        } elseif (preg_match('/^[A-Za-z0-9 _.:\/#-]{3,64}$/', $reference) !== 1) {
            $error = '--reference is required (3-64 characters: letters, digits, space, _ . : / # -)';
        } elseif ($outcome === AttemptStore::RESOLUTION_FULFILLED_MANUALLY && !$this->orderExists($reference)) {
            $error = 'for fulfilled_manually, --reference must be the increment id of an existing order';
        }
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');

            return Command::FAILURE;
        }
        $changes = [
            'resolution' => $outcome,
            'resolution_reference' => $reference,
            'resolution_note' => $note ?: null,
            'resolved_by' => substr('cli:' . $this->osUser(), 0, 64),
            'resolved_at' => gmdate('Y-m-d H:i:s'),
            'next_check_at' => null,
        ];
        if (!$input->getOption('confirm')) {
            $output->writeln(json_encode(['would_write' => $changes, 'attempt' => $this->describe($attempt)], JSON_PRETTY_PRINT));
            $output->writeln('Nothing written. Add --confirm to record it. This records what you confirmed outside this module; it does not move money.');

            return Command::SUCCESS;
        }
        $resolved = $this->attemptStore->transition($attempt, $changes);
        if ($resolved === null) {
            $output->writeln('<error>the attempt changed meanwhile; run it again</error>');

            return Command::FAILURE;
        }
        $this->trace->add('info', 'Payment-first attempt resolved by an operator', ['module:operations'],
            ['cart_id' => $resolved['cart_id'], 'resolution' => $outcome, 'reference' => $reference, 'by' => $changes['resolved_by']]);
        $this->trace->flush();
        $output->writeln(json_encode($this->describe($resolved), JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    private function orderExists(string $increment): bool
    {
        $connection = $this->resource->getConnection();

        return (bool)$connection->fetchOne($connection->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
            ->where('increment_id = ?', $increment)->limit(1));
    }

    private function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (!empty($info['name'])) {
                return (string)$info['name'];
            }
        }

        return (string)get_current_user();
    }
}
