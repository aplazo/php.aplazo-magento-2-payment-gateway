<?php

namespace Aplazo\AplazoPayment\Console\Command;

use Aplazo\AplazoPayment\Cron\CancelOrders;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CancelOrdersCommand extends Command
{
    public function __construct(private CancelOrders $cancelOrdersCron)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('aplazo:orders:cancel')
            ->setDescription('Run Aplazo cancel orders cron manually');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cancelOrdersCron->execute();
        $output->writeln('<info>Done.</info>');
        return Command::SUCCESS;
    }
}

