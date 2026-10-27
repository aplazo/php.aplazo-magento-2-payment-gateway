<?php

namespace Aplazo\AplazoPayment\Observer;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Aplazo\AplazoPayment\Model\CheckoutModeMarker;
use Aplazo\AplazoPayment\Model\Config\Source\CheckoutMode;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote\Payment as QuotePayment;

/**
 * payment_method_assign_data_aplazo_gateway: records on the quote payment whether this attempt is a popup checkout
 * (BNPL-1665). The mark is written or removed on every assignData, so a popup mark from an earlier attempt never
 * survives a later redirect attempt on the same quote; Magento copies it to the order payment when the order is
 * created. It is accepted only when popup mode is effective for the quote's store. Order payments are never touched
 * here: once the order exists, its mark does not change.
 */
class AssignCheckoutMode extends AbstractDataAssignObserver
{
    private AplazoHelper $aplazoHelper;

    public function __construct(AplazoHelper $aplazoHelper)
    {
        $this->aplazoHelper = $aplazoHelper;
    }

    public function execute(Observer $observer)
    {
        $payment = $this->readPaymentModelArgument($observer);
        if (!$payment instanceof QuotePayment) {
            return;
        }
        $additionalData = $this->readDataArgument($observer)->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        $requested = is_array($additionalData)
            && ($additionalData[CheckoutModeMarker::KEY] ?? null) === CheckoutModeMarker::POPUP;
        $quote = $payment->getQuote();
        $storeId = $quote ? (int)$quote->getStoreId() : null;

        if ($requested && $storeId !== null && $this->aplazoHelper->getCheckoutMode($storeId) === CheckoutMode::MODE_POPUP) {
            $payment->setAdditionalInformation(CheckoutModeMarker::KEY, CheckoutModeMarker::POPUP);
        } else {
            $payment->unsAdditionalInformation(CheckoutModeMarker::KEY);
        }
    }
}
