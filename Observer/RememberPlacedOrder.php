<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Model\PlacedOrders;
use Aplazo\AplazoPayment\Model\Ui\ConfigProvider;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Remembers each Aplazo order in the buyer's checkout session once it is saved (checkout_submit_all_after), so the
 * popup flow can name it by entity id (BNPL-1664).
 */
class RememberPlacedOrder implements ObserverInterface
{
    private PlacedOrders $placedOrders;

    public function __construct(PlacedOrders $placedOrders)
    {
        $this->placedOrders = $placedOrders;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if ($order instanceof OrderInterface && $order->getPayment() !== null
            && $order->getPayment()->getMethod() === ConfigProvider::CODE
        ) {
            $this->placedOrders->remember($order);
        }

        return $this;
    }
}
