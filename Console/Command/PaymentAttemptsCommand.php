<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Console\Command;

use Aplazo\AplazoPayment\Model\PaymentFirst\AttemptStore;
use Aplazo\AplazoPayment\Model\PaymentFirst\Reconciler;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operations view of the popup payment-first attempts (BNPL-1664), without personal data:
 *   bin/magento aplazo:payment-first:attempts [--status=rejected] [--reconcile [--id=12 --id=13]]
 * --reconcile runs the same reconciliation as the cron, optionally only for the given attempt ids.
 */
class PaymentAttemptsCommand extends Command
{
    private const COLUMNS = ['entity_id', 'cart_id', 'quote_id', 'store_id', 'status', 'order_id', 'reject_reason',
        'loan_cancel_state', 'check_count', 'next_check_at', 'last_error', 'created_at'];

    public function __construct(
        private ResourceConnection $resource,
        private Reconciler\Proxy $reconciler,
        private State $appState
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('aplazo:payment-first:attempts')
            ->setDescription('List Aplazo popup payment attempts, or reconcile them')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only attempts in this status')
            ->addOption('reconcile', null, InputOption::VALUE_NONE, 'Run the reconciliation now')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these attempt ids');
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
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->resource->getTableName(AttemptStore::TABLE), self::COLUMNS)
            ->order('entity_id DESC')->limit(100);
        if ($input->getOption('status')) {
            $select->where('status = ?', (string)$input->getOption('status'));
        }
        if ($ids) {
            $select->where('entity_id IN (?)', $ids);
        }
        $output->writeln(json_encode($connection->fetchAll($select), JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
