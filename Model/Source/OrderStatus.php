<?php

namespace Aplazo\AplazoPayment\Model\Source;

class OrderStatus {

    public function toOptionArray() {
        return [
            [
                'value' => __('processing'),
                'label' => 'Processing'
            ],
            [
                'value' => __('pending'),
                'label' => 'Pending'
            ],
            [
                'value' => __('complete'),
                'label' => 'Complete'
            ],
            [
                'value' => __('payment_review'),
                'label' => 'Payment Review'
            ],
            [
                'value' => __('holded'),
                'label' => 'On Hold'
            ],
            [
                'value' => __('procesado'),
                'label' => 'Procesado'
            ]            
        ];
    }

}
