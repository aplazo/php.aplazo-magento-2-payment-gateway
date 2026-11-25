<?php
declare(strict_types=1);

namespace Aplazo\AplazoPayment\Model\PaymentFirst;

use Aplazo\AplazoPayment\Helper\Data as AplazoHelper;
use Magento\Quote\Model\Quote;

/**
 * The checkout data a popup loan is accepted for (BNPL-1664), reduced to a hash.
 *
 * Compared again on the quote that is converted, under the row locks of Finalizer::convert(), so a cart changed after
 * the loan was requested (other items, quantities, options, shipping, addresses, customer, coupon or totals) is never
 * converted, even when the total stays the same. Amounts are compared in integer minor units, never as floats.
 */
class QuoteFingerprint
{
    private const ADDRESS_FIELDS = [
        'firstname', 'middlename', 'lastname', 'company', 'street', 'city', 'region_id', 'region', 'postcode',
        'country_id', 'telephone'
    ];

    public function compute(Quote $quote): string
    {
        return hash('sha256', json_encode($this->data($quote), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Amount and currency sent to Aplazo, with the order rule (AplazoHelper::shouldUseDisplayAmounts) applied to the
     * quote: display amounts unless the display currency is not MXN and the base one is.
     *
     * @return array{0: int, 1: string} [amount in minor units, currency]
     */
    public function amount(Quote $quote): array
    {
        [$amount, $currency] = $this->useDisplayAmounts($quote)
            ? [$quote->getGrandTotal(), (string)$quote->getQuoteCurrencyCode()]
            : [$quote->getBaseGrandTotal(), (string)$quote->getBaseCurrencyCode()];

        return [self::toMinorUnits($amount), $currency];
    }

    public function useDisplayAmounts(Quote $quote): bool
    {
        $display = (string)$quote->getQuoteCurrencyCode();
        $base = (string)$quote->getBaseCurrencyCode();

        return $display === '' || $display === $base || $base !== AplazoHelper::APLAZO_CURRENCY;
    }

    /**
     * What was accepted, without personal data, kept on the attempt for diagnosis.
     */
    public function summary(Quote $quote): array
    {
        $data = $this->data($quote);
        unset($data['customer'], $data['addresses']);

        return $data;
    }

    public static function toMinorUnits($amount): int
    {
        return (int)round((float)$amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function data(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllItems() as $item) {
            $buyRequest = $item->getOptionByCode('info_buyRequest');
            $items[] = [
                'sku' => (string)$item->getSku(),
                'product_id' => (int)$item->getProductId(),
                'parent' => $item->getParentItem() ? (string)$item->getParentItem()->getSku() : null,
                'qty' => (string)(float)$item->getQty(),
                'options' => $buyRequest ? hash('sha256', (string)$buyRequest->getValue()) : null,
                'row_total' => self::toMinorUnits($item->getRowTotal()),
                'discount' => self::toMinorUnits($item->getDiscountAmount()),
                'tax' => self::toMinorUnits($item->getTaxAmount()),
            ];
        }
        usort($items, function ($a, $b) {
            return [$a['parent'], $a['sku'], $a['options']] <=> [$b['parent'], $b['sku'], $b['options']];
        });
        $shipping = $quote->getShippingAddress();
        $addresses = ['billing' => $this->address($quote->getBillingAddress())];
        if (!$quote->isVirtual()) {
            $addresses['shipping'] = $this->address($shipping);
        }

        return [
            'store_id' => (int)$quote->getStoreId(),
            'currency' => [(string)$quote->getQuoteCurrencyCode(), (string)$quote->getBaseCurrencyCode()],
            'customer' => [
                'id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : null,
                'group' => (int)$quote->getCustomerGroupId(),
                'email' => strtolower(trim((string)($quote->getCustomerEmail() ?: $quote->getBillingAddress()->getEmail()))),
            ],
            'items' => $items,
            'shipping' => $quote->isVirtual() ? null : [
                'method' => (string)$shipping->getShippingMethod(),
                'amount' => self::toMinorUnits($shipping->getShippingAmount()),
            ],
            'addresses' => $addresses,
            'coupon' => (string)$quote->getCouponCode(),
            'rules' => (string)$quote->getAppliedRuleIds(),
            'totals' => [
                'grand' => self::toMinorUnits($quote->getGrandTotal()),
                'base_grand' => self::toMinorUnits($quote->getBaseGrandTotal()),
            ],
        ];
    }

    private function address($address): array
    {
        $out = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $address ? $address->getData($field) : null;
            $out[$field] = is_array($value) ? implode("\n", $value) : trim((string)$value);
        }

        return $out;
    }
}
