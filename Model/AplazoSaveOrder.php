<?php

declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model;

use Aplazo\AplazoPayment\Model\Data\Sale;
use Aplazo\AplazoPayment\Model\SaleRepository;
use Aplazo\AplazoPayment\Api\Data\SaleInterfaceFactory;
use Aplazo\AplazoPayment\Logger\Logger as AplazoLogger;
use Magento\Framework\Exception\LocalizedException;

class AplazoSaveOrder
{

    private $logger;
    private $saleRepository;
    private $saleFactory;

    public function __construct(
        AplazoLogger             $logger,
        SaleInterfaceFactory $saleDataFactory,
        SaleRepository $saleRepository
    )
    {
        $this->_logger = $logger;
        $this->saleRepository = $saleRepository;
        $this->saleFactory = $saleDataFactory;
    }

    /**
     * @param \Magento\Quote\Model\Quote $quote
     * @param $message
     * @return void
     */
    public function addAplazoOrder($quote)
    {
        try {
            $aplazoModelOrder = $this->saleRepository->getByQuoteId($quote->getEntityId());
            $message = $aplazoModelOrder->getMessage() . ' . New try to create an order by ' . $quote->getCustomerEmail();
        } catch (LocalizedException $e) {
            $aplazoModelOrder = $this->saleFactory->create();
            $message = 'New order by ' . $quote->getCustomerEmail();
        }
        $aplazoModelOrder->setEmail($quote->getCustomerEmail())
            ->setQuoteId($quote->getEntityId())
            ->setFirstname($quote->getCustomerFirstname())
            ->setLastname($quote->getCustomerLastname())
            ->setReservedOrderId($quote->getReservedOrderId())
            ->setMessage($message)
            ->setStatus(Sale::STATUS_PENDING);
        try {
            $this->saleRepository->save($aplazoModelOrder);
        } catch (\Exception $e) {
            $this->_logger->debug('*** Pre orden - ' . $quote->getReservedOrderId() . ': Error al guardar en tabla aplazo_aplazopayment_sale > ' . $e->getMessage());
        }
    }

    /**
     * @param $quoteId
     * @param $status
     * @param $message
     * @return void
     */
    public function updateAplazoOrder($quoteId, $status, $message)
    {
        try {
            $aplazoModelOrder = $this->saleRepository->getByQuoteId($quoteId);
            $aplazoModelOrder->setMessage($message)
                ->setStatus($status);
            $this->saleRepository->save($aplazoModelOrder);
        } catch (LocalizedException $e) {
            $this->_logger->debug('*** Pre orden quote id - ' . $quoteId . ': Error al actualizar en tabla aplazo_aplazopayment_sale > ' . $e->getMessage());
        }
    }
}
