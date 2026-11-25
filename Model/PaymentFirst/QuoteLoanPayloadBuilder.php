<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Quote\Model\Quote;

/**
 * createLoan payload for a popup payment-first attempt (BNPL-1664), built from the quote with the same fields and
 * amount rules as OrderService::createLoan builds it from an order. Only the URLs differ: Aplazo's window ends on a
 * store page that closes itself (Controller/Popup/Landing), never on the cancel operation, which cancels orders.
 */
class QuoteLoanPayloadBuilder
{
    public function __construct(
        private AplazoHelper $aplazoHelper,
        private QuoteFingerprint $fingerprint
    ) {
    }

    public function build(Quote $quote, array $attempt): array
    {
        $display = $this->fingerprint->useDisplayAmounts($quote);
        $billing = $quote->getBillingAddress();
        $shipping = $quote->getShippingAddress();
        // Discount and tax totals live on the billing address of a virtual quote.
        $totals = $quote->isVirtual() ? $billing : $shipping;
        $products = [];
        foreach ($quote->getAllItems() as $item) {
            if ($item->getProductType() != 'configurable') {
                $products[] = [
                    "count" => intval($item->getQty() * ($item->getParentItem() ? $item->getParentItem()->getQty() : 1)),
                    "description" => $item->getDescription(),
                    "id" => $item->getProductId(),
                    "imageUrl" => '',
                    "price" => ($display ? $item->getRowTotal() : $item->getBaseRowTotal()) ?? 0,
                    "title" => $item->getName()
                ];
            }
        }
        $landing = $this->aplazoHelper->getUrl('aplazo/popup/landing', ['attempt' => $attempt['public_id']]);

        return [
            "buyer" => [
                "addressLine" => trim($billing->getStreetLine(1) . ' ' . $billing->getStreetLine(2)),
                "email" => $billing->getEmail() ?: $quote->getCustomerEmail(),
                "firstName" => $billing->getFirstname(),
                "lastName" => $billing->getLastname(),
                "phone" => $billing->getTelephone(),
                "postalCode" => $billing->getPostcode()
            ],
            "cartId" => $attempt['cart_id'],
            "cartUrl" => $landing,
            "discount" => [
                "price" => abs((float)($display ? $totals->getDiscountAmount() : $totals->getBaseDiscountAmount())),
                "title" => $totals->getDiscountDescription()
            ],
            "errorUrl" => $landing,
            "products" => $products,
            "shipping" => [
                "price" => abs((float)($display ? $shipping->getShippingAmount() : $shipping->getBaseShippingAmount())),
                "title" => $shipping->getShippingDescription()
            ],
            "shopId" => $attempt['cart_id'],
            "successUrl" => $landing,
            "taxes" => [
                "price" => $display ? $totals->getTaxAmount() : $totals->getBaseTaxAmount(),
                "title" => __('Tax'),
            ],
            "extOrderId" => $quote->getId(),
            "totalPrice" => $display ? $quote->getGrandTotal() : $quote->getBaseGrandTotal(),
            "webHookUrl" => $this->aplazoHelper->getUrl('rest/V1/aplazo') . 'callback'
        ];
    }
}
