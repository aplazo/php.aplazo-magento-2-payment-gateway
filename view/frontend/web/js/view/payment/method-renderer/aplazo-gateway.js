/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'mage/url',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/totals',
        'Magento_Catalog/js/price-utils',
    ],
    function ($, Component, url, quote, totals, priceUtils) {
        'use strict';
        const months = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: false,
                template: 'Aplazo_AplazoPayment/payment/form',
                code: 'aplazo_gateway',
                active: false,
                transactionResult: ''
            },

            getCode: function () {
                return this.code;
            },

            getTitle: function () {
                return window.checkoutConfig.payment[this.getCode()].title;
            },

            getPurchaseActionUrl: function () {
                return window.checkoutConfig.payment[this.getCode()].actionUrl;
            },

            getData: function () {
                return {
                    'method': this.getCode(),
                };
            },

            getTotalSegment: function (){
                let total = totals.getSegment('grand_total').value;
                return priceUtils.formatPrice(total, quote.getPriceFormat());
            },

            getInstallmentAmount: function (){
                let total = totals.getSegment('grand_total').value;
                return priceUtils.formatPrice(total/5, quote.getPriceFormat());
            },

            getInstallmentData: function () {
                var installmentData = [];
                var biweeklyInstallment = this.getInstallmentAmount();

                installmentData.push({label: 'Hoy', amount: biweeklyInstallment, class:'aplazo-font-text'})
                var futureDate = new Date();
                futureDate.setDate(futureDate.getDate()+ 15)
                for (let i = 0; i < 4; i++){
                    let labelDate = futureDate.getDate() + ' de ' + months[futureDate.getMonth()];
                    installmentData.push({label: labelDate, amount: biweeklyInstallment, class:'aplazo-font-text-item'})
                    futureDate.setDate(futureDate.getDate() + 15)
                }

                return installmentData;
            },

            afterPlaceOrder: function () {
                var quoteid = quote.getQuoteId();
                window.location = this.getPurchaseActionUrl() +'?operation=purchase&quoteid=' + quoteid;
            },

            getBannerUrl: function () {
                return window.checkoutConfig.payment.aplazo_gateway.banner;
            },
        })
    }
);
