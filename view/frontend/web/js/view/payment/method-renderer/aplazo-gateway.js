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
        'ko',
        'Magento_Checkout/js/action/redirect-on-success',
        'Aplazo_AplazoPayment/js/model/popup-checkout',
    ],
    function ($, Component, url, quote, totals, priceUtils, ko, redirectOnSuccessAction, popupCheckout) {
        'use strict';
        const months = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
        // Reopening stays off until order identity and stock compensation are settled (BNPL-1607 review).
        const POPUP_REOPEN_ENABLED = false;
        const popupNotices = {
            closedBeforeCheckout: 'La ventana de Aplazo se cerró antes de cargar el checkout. Tu pedido quedó registrado.',
            lost: 'La ventana de Aplazo se cerró o perdió comunicación con esta página, así que no podemos mostrar aquí el resultado.',
            lostPending: 'Tu pago estaba en proceso cuando la ventana de Aplazo se cerró o perdió comunicación con esta página. No podemos mostrar aquí el resultado.',
            pending: 'Tu pago está en proceso en Aplazo. Mantén esta página abierta.',
            failure: 'Aplazo indicó que el pago no se completó.',
            close: 'Saliste de la ventana de Aplazo. Aún no podemos confirmar aquí el resultado de tu pago.',
            reopenBlocked: 'Tu navegador bloqueó la ventana de Aplazo. Permite las ventanas emergentes de este sitio y vuelve a intentarlo.',
            navigationFailed: 'No pudimos cargar Aplazo en su ventana. Puedes volver a intentarlo.'
        };

        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: false,
                template: 'Aplazo_AplazoPayment/payment/form',
                code: 'aplazo_gateway',
                active: false,
                transactionResult: ''
            },

            initialize: function () {
                this._super();
                this.popupNotice = ko.observable('');
                this.popupOrderPlaced = ko.observable(false);
                this.popupCanReopen = ko.observable(false);
                this.popupSession = null;
                this.popupPurchaseUrl = null;
                this.popupOrderId = null;

                return this;
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

            getPopupOrigin: function () {
                var config = window.checkoutConfig.payment[this.getCode()];

                return config.checkoutMode === 'popup' ? popupCheckout.normalizeOrigin(config.aplazoOrigin) : null;
            },

            /**
             * Once the order exists, recovery never places it again.
             */
            placeOrder: function (data, event) {
                if (this.popupOrderPlaced()) {
                    if (event) {
                        event.preventDefault();
                    }

                    return false;
                }

                return this._super(data, event);
            },

            /**
             * The base renderer calls this synchronously inside the click, only after validation passed and
             * with no request in flight, so the window opens here, before the asynchronous request starts.
             */
            getPlaceOrderDeferredObject: function () {
                var self = this,
                    origin = this.getPopupOrigin();

                this.popupSession = null;
                if (origin !== null) {
                    this.popupNotice('');
                    this.popupSession = popupCheckout.create(origin, this.getPopupHandlers());
                    if (!this.popupSession.open()) {
                        this.popupSession = null;
                    }
                }

                this.popupOrderId = null;

                // Registered before the base renderer's done(), so afterPlaceOrder() already has the id.
                return this._super().done(function (orderId) {
                    self.popupOrderId = orderId;
                }).fail(function () {
                    if (self.popupSession !== null) {
                        self.popupSession.close();
                        self.popupSession = null;
                    }
                });
            },

            getPopupHandlers: function () {
                var self = this;

                return {
                    pending: function () {
                        self.popupNotice(popupNotices.pending);
                    },
                    success: function () {
                        self.popupNotice('');
                        self.popupCanReopen(false);
                        // Same success page as the redirect flow; the webhook still confirms the payment.
                        redirectOnSuccessAction.execute();
                    },
                    failure: function () {
                        self.showPopupRecovery(popupNotices.failure);
                    },
                    close: function () {
                        self.showPopupRecovery(popupNotices.close);
                    },
                    lost: function (detail) {
                        self.showPopupRecovery(detail.pending ? popupNotices.lostPending : popupNotices.lost);
                    }
                };
            },

            showPopupRecovery: function (notice) {
                this.popupNotice(notice);
                this.popupCanReopen(POPUP_REOPEN_ENABLED && this.popupPurchaseUrl !== null);
            },

            /**
             * Navigates an already open window to purchase. The server reuses the order and its checkout URL and
             * applies the stock compensation at most once, so this is safe for the first opening and for reopening.
             */
            startPopupCheckout: function (session) {
                this.popupSession = session;
                this.popupCanReopen(false);
                if (!session.navigate(this.popupPurchaseUrl + '&mode=popup')) {
                    this.popupSession = null;
                    this.showPopupRecovery(popupNotices.navigationFailed);
                }
            },

            /**
             * Explicit click only: reopens Aplazo for the order already placed, never placing it again.
             */
            reopenPopup: function () {
                var origin = this.getPopupOrigin(),
                    session;

                if (!POPUP_REOPEN_ENABLED) {
                    return false;
                }
                if (this.popupSession !== null && this.popupSession.isOpen()) {
                    this.popupSession.focus();

                    return false;
                }
                if (origin === null || this.popupPurchaseUrl === null) {
                    return false;
                }
                if (this.popupSession !== null) {
                    this.popupSession.dispose();
                    this.popupSession = null;
                }
                session = popupCheckout.create(origin, this.getPopupHandlers());
                if (!session.open()) {
                    this.showPopupRecovery(popupNotices.reopenBlocked);

                    return false;
                }
                this.popupNotice('');
                this.startPopupCheckout(session);

                return false;
            },

            afterPlaceOrder: function () {
                var quoteid = quote.getQuoteId(),
                    purchaseUrl = this.getPurchaseActionUrl() +'?operation=purchase&quoteid=' + quoteid,
                    session = this.popupSession;

                // Redirect mode, or the browser blocked the window: keep today's redirect.
                if (session === null) {
                    this.redirectToPurchase(purchaseUrl);

                    return;
                }
                this.popupOrderPlaced(true);
                // Bound to this order: the server only opens it if it was placed in this checkout session.
                // Without an id (unexpected response) purchase falls back to the session's last order, as redirect does.
                this.popupPurchaseUrl = this.popupOrderId === null || this.popupOrderId === undefined
                    ? purchaseUrl
                    : purchaseUrl + '&order=' + encodeURIComponent(String(this.popupOrderId));
                if (!session.isOpen()) {
                    session.dispose();
                    this.popupSession = null;
                    this.showPopupRecovery(popupNotices.closedBeforeCheckout);

                    return;
                }
                this.startPopupCheckout(session);
            },

            redirectToPurchase: function (purchaseUrl) {
                window.location = purchaseUrl;
            },

            getBannerUrl: function () {
                return window.checkoutConfig.payment.aplazo_gateway.banner;
            },
        })
    }
);
