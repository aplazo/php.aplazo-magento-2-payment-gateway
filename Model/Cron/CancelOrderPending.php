<?php
namespace Aplazo\AplazoPayment\Cron;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Class CancelOrderPending
 */
class CancelOrderPending
{

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var FilterGroup
     */
    private $filterGroup;

    /**
     * @var OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     *  Time to cancel pending orders
     */
    const APLAZO_ORDER_CANCELLATION_TIME = 'payment/aplazo_payment/order_cancellation_time';

    /**
     * CancelOrderPending constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroup $filterGroup
     * @param OrderManagementInterface $orderManagement
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        FilterGroup $filterGroup,
        OrderManagementInterface $orderManagement,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->orderRepository       = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder         = $filterBuilder;
        $this->filterGroup           = $filterGroup;
        $this->orderManagement       = $orderManagement;
        $this->scopeConfig           = $scopeConfig;
    }

    /**
     * @return mixed 
     */
    public function getOrderCancellationTime(){
        $orderCancellationTime = self::APLAZO_ORDER_CANCELLATION_TIME;
        if($orderCancellationTime == "" || $orderCancellationTime < 15)
            return 30;
        else
            return $this->scopeConfig->getValue(self::APLAZO_ORDER_CANCELLATION_TIME); 
    }
    
    /**
     * @throws \Exception
     */
    public function execute()
    {
        $orderCancellationTime = $this->getOrderCancellationTime();
        $today          = date("Y-m-d h:i:s");
        $to             = strtotime('-'.$orderCancellationTime.' min', strtotime($today));
        $to             = date('Y-m-d h:i:s', $to);

        $filterGroupDate      = $this->filterGroup;
        $filterGroupStatus    = clone($filterGroupDate);

        $filterDate      = $this->filterBuilder
            ->setField('updated_at')
            ->setConditionType('to')
            ->setValue($to)
            ->create();
        $filterStatus    = $this->filterBuilder
            ->setField('status')
            ->setConditionType('eq')
            ->setValue('pending')
            ->create();

        $filterGroupDate->setFilters([$filterDate]);
        $filterGroupStatus->setFilters([$filterStatus]);

        $searchCriteria = $this->searchCriteriaBuilder->setFilterGroups(
            [$filterGroupDate, $filterGroupStatus]
        );
        $searchResults  = $this->orderRepository->getList($searchCriteria->create());

        /** @var Order $order */
        foreach ($searchResults->getItems() as $order) {
            $this->orderManagement->cancel($order->getId());
        }
    }
}