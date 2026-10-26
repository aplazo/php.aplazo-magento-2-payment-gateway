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
        'mage/translate',
        'Magento_Checkout/js/action/redirect-on-success',
        'Aplazo_AplazoPayment/js/model/popup-checkout',
    ],
    function ($, Component, url, quote, totals, priceUtils, ko, $t, redirectOnSuccessAction, popupCheckout) {
        'use strict';
        const months = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
        // Reopening stays off until order identity and stock compensation are settled (BNPL-1665).
        const POPUP_REOPEN_ENABLED = false;
        // Translated when shown: the literal $t() calls are what Magento collects into the JS translation dictionary.
        const popupNotices = {
            closedBeforeCheckout: function () {
                return $t('The Aplazo window closed before the checkout loaded. Your order was placed.');
            },
            lost: function () {
                return $t('The Aplazo window closed or lost contact with this page, so we cannot show the result here.');
            },
            lostPending: function () {
                return $t('Your payment was in progress when the Aplazo window closed or lost contact with this page. We cannot show the result here.');
            },
            pending: function () {
                return $t('Your payment is in progress in Aplazo. Keep this page open.');
            },
            failure: function () {
                return $t('Aplazo reported that the payment was not completed.');
            },
            close: function () {
                return $t('You left the Aplazo window. We cannot confirm the result of your payment here yet.');
            },
            reopenBlocked: function () {
                return $t('Your browser blocked the Aplazo window. Allow pop-ups for this site and try again.');
            },
            navigationFailed: function () {
                return $t('We could not load Aplazo in its window.');
            }
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
                this.popupCartUrl = ko.observable('');
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

            /**
             * Operations URL with an encoded query, for the popup flow.
             */
            getOperationUrl: function (params) {
                var actionUrl = this.getPurchaseActionUrl();

                return actionUrl + (actionUrl.indexOf('?') === -1 ? '?' : '&') + $.param(params);
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

                // A new attempt replaces any earlier session; its listener and watcher are released first.
                if (this.popupSession !== null) {
                    this.popupSession.dispose();
                }
                this.popupSession = null;
                if (origin !== null) {
                    this.popupNotice('');
                    this.popupCartUrl('');
                    this.popupSession = this.createPopupSession(origin);
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

            /**
             * Messages from Checkout (success, failure, close, pending) and a lost window ('lost': closed by the buyer,
             * or the reference cut by COOP, indistinguishable here) only change what this page shows. None of them
             * cancels the order or proves the payment state: the webhook confirms the payment.
             */
            /**
             * A session whose handlers act only while it is this renderer's current session, so an event from an
             * earlier session never changes what a later one shows.
             */
            createPopupSession: function (origin) {
                var holder = {},
                    session = popupCheckout.create(origin, this.getPopupHandlers(holder));

                holder.session = session;

                return session;
            },

            getPopupHandlers: function (holder) {
                var self = this,
                    handlers;

                handlers = {
                    pending: function () {
                        self.popupNotice(popupNotices.pending());
                    },
                    success: function () {
                        self.popupNotice('');
                        self.popupCanReopen(false);
                        self.popupCartUrl('');
                        // A UI signal only; the webhook still confirms the payment. The server checks that this popup's
                        // order belongs to the session before showing Magento's success page for it.
                        if (self.popupOrderId === null || self.popupOrderId === undefined) {
                            redirectOnSuccessAction.execute();

                            return;
                        }
                        self.redirectToSuccess(self.getOperationUrl({operation: 'popup_success', order: String(self.popupOrderId)}));
                    },
                    failure: function () {
                        self.showPopupRecovery(popupNotices.failure());
                    },
                    close: function () {
                        self.showPopupRecovery(popupNotices.close());
                    },
                    lost: function (detail) {
                        self.showPopupRecovery(detail.pending ? popupNotices.lostPending() : popupNotices.lost());
                    }
                };

                Object.keys(handlers).forEach(function (state) {
                    var handler = handlers[state];

                    handlers[state] = function (detail) {
                        if (holder && holder.session !== undefined && self.popupSession === holder.session) {
                            handler(detail);
                        }
                    };
                });

                return handlers;
            },

            /**
             * A neutral notice plus a visible way back to the cart. Following that link does not cancel the order.
             */
            showPopupRecovery: function (notice) {
                this.popupNotice(notice);
                this.popupCanReopen(POPUP_REOPEN_ENABLED && this.popupPurchaseUrl !== null);
                this.popupCartUrl(this.getPopupCartUrl());
            },

            getPopupCartUrl: function () {
                if (this.popupOrderId === null || this.popupOrderId === undefined) {
                    return url.build('checkout/cart');
                }

                return this.getOperationUrl({operation: 'popup_return', order: String(this.popupOrderId)});
            },

            /**
             * Navigates an already open window to purchase for the order just placed.
             */
            startPopupCheckout: function (session) {
                this.popupSession = session;
                this.popupCanReopen(false);
                if (!session.navigate(this.popupPurchaseUrl)) {
                    this.popupSession = null;
                    this.showPopupRecovery(popupNotices.navigationFailed());
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
                session = this.createPopupSession(origin);
                if (!session.open()) {
                    this.showPopupRecovery(popupNotices.reopenBlocked());

                    return false;
                }
                this.popupNotice('');
                this.startPopupCheckout(session);

                return false;
            },

            afterPlaceOrder: function () {
                var quoteid = quote.getQuoteId(),
                    session = this.popupSession,
                    params;

                // Redirect mode, or the browser blocked the window: today's redirect, same URL.
                if (session === null) {
                    this.redirectToPurchase(this.getPurchaseActionUrl() +'?operation=purchase&quoteid=' + quoteid);

                    return;
                }
                this.popupOrderPlaced(true);
                params = {operation: 'purchase', quoteid: quoteid};
                // Bound to this order: the server only opens it if it is the checkout session's last order. Without an id
                // (unexpected response) the server rejects the popup request instead of guessing the order.
                if (this.popupOrderId !== null && this.popupOrderId !== undefined) {
                    params.order = String(this.popupOrderId);
                }
                params.mode = 'popup';
                this.popupPurchaseUrl = this.getOperationUrl(params);
                if (!session.isOpen()) {
                    session.dispose();
                    this.popupSession = null;
                    this.showPopupRecovery(popupNotices.closedBeforeCheckout());

                    return;
                }
                this.startPopupCheckout(session);
            },

            redirectToPurchase: function (purchaseUrl) {
                window.location = purchaseUrl;
            },

            redirectToSuccess: function (successUrl) {
                window.location = successUrl;
            },

            getBannerUrl: function () {
                return window.checkoutConfig.payment.aplazo_gateway.banner;
            },
        })
    }
);
