<?php

namespace Aplazo\AplazoPayment\Console\Command;

use Aplazo\AplazoPayment\Api\RefundQueueManagementInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessRefundQueueCommand extends Command
{
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(private RefundQueueManagementInterface $refundQueueManagement)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('aplazo:refund:process')
            ->setDescription('Process queued Aplazo refunds (manual trigger)')
            ->addOption(
                self::OPTION_BATCH_SIZE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Max items to attempt in this run',
                20
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $batchSize = (int)$input->getOption(self::OPTION_BATCH_SIZE);
        if ($batchSize <= 0) {
            $batchSize = 20;
        }

        $attempted = $this->refundQueueManagement->process($batchSize);
        $output->writeln('<info>Attempted:</info> ' . $attempted);

        return Command::SUCCESS;
    }
}

