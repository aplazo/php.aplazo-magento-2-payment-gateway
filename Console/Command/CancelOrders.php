<?php
namespace Aplazo\AplazoPayment\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Aplazo\AplazoPayment\Model\Service\OrderService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class CancelOrders extends Command
{
    const STORE_ID_OPTION = 'store_id';
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

    public function __construct(
        State $state,
        OrderService $orderService,
        TimezoneInterface $timezone,
        string $name = null
    )
    {
        parent::__construct($name);
        $this->state = $state;
        $this->orderService = $orderService;
        $this->timezone = $timezone;
    }

    protected function configure()
    {
        $this->setName('aplazo:cancel:orders');
        $this->setDescription('Cancel Aplazo orders.');
        $this->addOption(
            self::STORE_ID_OPTION,
            '-s',
            InputOption::VALUE_REQUIRED,
            'StoreID'
        );

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
        if ($store_id = $input->getOption(self::STORE_ID_OPTION)) {
            $output->writeln('<info>StoreID Ingresado `' . $store_id . '`</info>');
        }
        else{
            $output->writeln("<error>Ingrese un StoreID valido para continuar. Por ejemplo: php bin/magento aplazo:orders:cancel -s 1</error>");
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
        $minutes = '15';
        $output->writeln("Cancelando ordenes de Aplazo creadas hace $minutes minutos");

        $counter = $ordersCanceledCount = $ordersSkippedCount = 0;
        $dateTimeCancelLimit = $this->initTimeZone($store_id, $minutes);
        $orderCollection = $this->orderService->getOrderToCancelCollection($store_id);
        $ordersWithErrors = [];
        if(($orderCollectionCount = $orderCollection->getTotalCount()) > 0) {
            $output->writeln('Total de ordenes encontradas: ' . $orderCollectionCount);
            /**
             * @var Order $order
             */
            foreach ($orderCollection as $order) {
                $incrementId = $order->getIncrementId();
                if ($this->timezone->date(new \DateTime($order->getCreatedAt())) < $dateTimeCancelLimit) {
                    $cancelResponse = $this->orderService->cancelOrder($order->getId());
                    if($cancelResponse['success']){
                        $output->writeln("Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Cancelada exitosamente");
                        $ordersCanceledCount++;
                    }
                    else{
                        $output->writeln("<comment>Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Con detalles</comment>");
                        $ordersWithErrors[] = $incrementId . ' ' . $cancelResponse['message'];
                    }
                } else {
                    $output->writeln("Aplazo Cancel Orders: StoreId $store_id - $counter/$orderCollectionCount - #$incrementId - Omitida");
                    $ordersSkippedCount++;
                }
                $counter++;
            }
        }
        return $this->finish($output, $ordersCanceledCount, $ordersSkippedCount, $ordersWithErrors);
    }

    /**
     * @param $store_id
     * @param $minutes
     * @return \DateTime
     */
    private function initTimeZone($store_id, $minutes): \DateTime
    {
        $this->timezone->getConfigTimezone('store', $store_id);
        $currentDateTime = $this->timezone->date(new \DateTime());
        return $currentDateTime->modify("- $minutes minutes");
    }

    /**
     * @param $output
     * @param $ordersCanceledCount
     * @param $ordersSkippedCount
     * @param $ordersWithErrors
     * @return int
     */
    private function finish($output, $ordersCanceledCount, $ordersSkippedCount, $ordersWithErrors)
    {
        if($ordersCanceledCount == 0 && $ordersSkippedCount == 0 && count($ordersWithErrors) == 0){
            $output->writeln("<comment>No se encontraron ordenes de Aplazo para cancelar</comment>");
        }
        else {
            $output->writeln('');
            $output->writeln("<info>Total cancelados: $ordersCanceledCount.</info>");
            $output->writeln("<comment>Total omitidos: $ordersSkippedCount </comment>");
        }

        if(count($ordersWithErrors) > 0){
            $output->writeln('');
            $output->writeln("<comment>Ordenes con conflictos</comment>");
            foreach ($ordersWithErrors as $error){
                $output->writeln($error);
            }
        }

        return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
    }
}

