<?php
namespace Aplazo\AplazoPayment\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class CancelOrders extends Command
{
    /**
     * @var State
     */
    protected $state;

    /**
     * @var OrderService
     */
    protected $orderService;
    /**
     * @var TimezoneInterface
     */
    protected $timezone;

    /**
     * @var \Aplazo\AplazoPayment\Cron\CancelOrders
     */
    private $cancelOrdersClass;

    public function __construct(
        State $state,
        OrderService $orderService,
        TimezoneInterface $timezone,
        \Aplazo\AplazoPayment\Cron\CancelOrders $cancelOrders,
        string $name = null
    )
    {
        parent::__construct($name);
        $this->state = $state;
        $this->orderService = $orderService;
        $this->timezone = $timezone;
        $this->cancelOrdersClass = $cancelOrders;
    }

    protected function configure()
    {
        $this->setName('aplazo:cancel:orders');
        $this->setDescription('Cancel Aplazo orders.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->emulateAreaCode(
            \Magento\Framework\App\Area::AREA_ADMINHTML,
            [$this, 'cancelOrders'],
            [$input, $output]
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    public function cancelOrders(InputInterface $input, OutputInterface $output){
        $this->cancelOrdersClass->execute();
        $output->writeln("<comment>Revisar Logs</comment>");
        return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
    }
}

