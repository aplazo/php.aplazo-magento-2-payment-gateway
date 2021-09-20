define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'aplazo_payment',
                component: 'Aplazo_AplazoPayment/js/view/payment/method-renderer/aplazo-method'
            },
            {
                type: 'aplazo_payment',
                component: 'Aplazo_AplazoPayment/js/view/payment/method-renderer/aplazo-widgets'
            }
        );

        return Component.extend({});
    }
);
