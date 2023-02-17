
/*browser:true*/
/*global define*/
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
        let aplazoGatewayType = 'aplazo_gateway';
        if(window.checkoutConfig.payment[aplazoGatewayType].active) {
            rendererList.push(
                {
                    type: aplazoGatewayType,
                    component: 'Aplazo_AplazoPayment/js/view/payment/method-renderer/aplazo-gateway'
                }
            );
        }

        return Component.extend({});
    }
);
