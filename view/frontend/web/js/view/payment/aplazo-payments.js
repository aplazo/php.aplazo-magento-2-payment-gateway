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
                component: 'Spro_AplazoPayment/js/view/payment/method-renderer/aplazo-method'
            }
        );

        return Component.extend({});
    }
);
