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
        'mage/translate',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/set-payment-information',
        'Aplazo_AplazoPayment/js/model/popup-checkout',
        'Aplazo_AplazoPayment/js/model/webview',
        'mage/cookies'
    ],
    function ($, Component, url, quote, totals, priceUtils, $t, additionalValidators, setPaymentInformationAction,
              popupCheckout, webview) {
        'use strict';
        const months = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
        // How the page re-asks the server after a signal while the payment is not confirmed yet (UI cadence only).
        const CHECK_RETRY_MS = 3000,
            CHECK_RETRIES = 10;

        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: false,
                template: 'Aplazo_AplazoPayment/payment/form',
                code: 'aplazo_gateway',
                active: false,
                transactionResult: '',
                popupNotice: '',
                popupBusy: false
            },

            initObservable: function () {
                this._super().observe(['popupNotice', 'popupBusy']);
                this.popupAttempt = null;
                this.popupSession = null;
                this.checking = false;
                this.retries = 0;
                this.completed = false;
                $(window).on('focus', this.onPageFocus.bind(this));

                return this;
            },

            getCode: function () {
                return this.code;
            },

            getConfig: function () {
                return window.checkoutConfig.payment[this.getCode()];
            },

            getTitle: function () {
                return this.getConfig().title;
            },

            getPurchaseActionUrl: function () {
                return this.getConfig().actionUrl;
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

            /**
             * Popup (payment-first, BNPL-1664) when it is configured, its origin is valid and the page is not in a
             * known webview. Otherwise the redirect, unchanged and without opening any window.
             */
            getPopupOrigin: function () {
                var config = this.getConfig();

                if (config.checkoutMode !== 'popup' || !config.popupStartUrl || webview.isWebView(navigator.userAgent)) {
                    return null;
                }

                return popupCheckout.normalizeOrigin(config.aplazoOrigin);
            },

            /**
             * In the click: validations, then the window opens synchronously. If it cannot open, the existing redirect
             * runs before any popup loan was requested. If it opens, the checkout data is saved without placing an
             * order and the server starts the attempt.
             */
            placeOrder: function (data, event) {
                var origin, session;

                if (event) {
                    event.preventDefault();
                }
                if (this.popupBusy()) {
                    return false;
                }
                origin = this.getPopupOrigin();
                if (origin === null) {
                    return this._super(data, event);
                }
                if (!(this.validate() && additionalValidators.validate() && this.isPlaceOrderActionAllowed() === true)) {
                    return false;
                }
                session = popupCheckout.create(origin, this.getPopupHandlers());
                if (!session.open()) {
                    return this._super(data, event);
                }
                this.startPopup(session, data, event);

                return true;
            },

            startPopup: function (session, data, event) {
                var self = this;

                this.popupBusy(true);
                this.isPlaceOrderActionAllowed(false);
                this.popupNotice($t('Complete your payment in the Aplazo window.'));
                setPaymentInformationAction(this.messageContainer, this.getData()).done(function () {
                    $.ajax({
                        url: self.getConfig().popupStartUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {form_key: $.mage.cookies.get('form_key')}
                    }).done(function (response) {
                        self.onStart(session, response || {}, data, event);
                    }).fail(function () {
                        // The loan may have been requested: never fall back to a redirect that would request another.
                        session.close();
                        self.finishPopup($t('We could not confirm that Aplazo opened. Please try again.'));
                    });
                }).fail(function () {
                    session.close();
                    self.finishPopup('');
                });
            },

            onStart: function (session, response, data, event) {
                switch (response.status) {
                    case 'open':
                        if (!session.navigate(response.url)) {
                            this.finishPopup($t('We could not open Aplazo. Please try again.'));

                            return;
                        }
                        this.popupSession = session;
                        this.popupAttempt = response.attempt;
                        this.completed = false;
                        this.retries = 0;
                        session.focus();
                        break;
                    case 'use_redirect':
                        // Popup is not available now and the server requested no loan: today's redirect.
                        session.close();
                        this.finishPopup('');
                        Component.prototype.placeOrder.call(this, data, event);
                        break;
                    default:
                        session.close();
                        this.finishPopup(response.message || $t('We could not start the payment. Please try again.'));
                }
            },

            getPopupHandlers: function () {
                var self = this;

                return {
                    signal: function (state) {
                        if (state === 'pending') {
                            self.popupNotice($t('Aplazo is confirming your payment.'));

                            return;
                        }
                        // A terminal message ends Aplazo's window; only the server's answer says what happened.
                        if (self.popupSession) {
                            self.popupSession.close();
                        }
                        self.retries = state === 'success' ? CHECK_RETRIES : 0;
                        self.checkPayment();
                    },
                    closed: function () {
                        self.checkPayment();
                    }
                };
            },

            onPageFocus: function () {
                if (this.popupAttempt !== null && !this.completed && !(this.popupSession && this.popupSession.isOpen())) {
                    this.checkPayment();
                }
            },

            /**
             * Asks the server; only its answer decides what the page shows.
             */
            checkPayment: function () {
                var self = this;

                if (this.popupAttempt === null || this.checking || this.completed) {
                    return;
                }
                this.checking = true;
                $.ajax({
                    url: this.getConfig().popupCompleteUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {form_key: $.mage.cookies.get('form_key'), attempt: this.popupAttempt}
                }).done(function (response) {
                    self.checking = false;
                    self.onComplete(response || {});
                }).fail(function () {
                    self.checking = false;
                    self.onComplete({status: 'pending'});
                });
            },

            onComplete: function (response) {
                var open = this.popupSession !== null && this.popupSession.isOpen(),
                    self = this;

                switch (response.status) {
                    case 'completed':
                        this.completed = true;
                        if (this.popupSession) {
                            this.popupSession.close();
                        }
                        if (response.redirect) {
                            window.location.href = response.redirect;

                            return;
                        }
                        this.popupNotice(response.notice || '');
                        this.popupBusy(false);

                        return;
                    case 'not_paid':
                    case 'pending':
                        if (this.retries > 0) {
                            this.retries--;
                            window.setTimeout(function () {
                                self.checkPayment();
                            }, CHECK_RETRY_MS);
                        }
                        if (open) {
                            return;
                        }
                        this.finishPopup(response.status === 'not_paid'
                            ? $t('You closed the Aplazo window without completing the payment. Your cart is still available.')
                            : $t('We could not confirm your payment yet. If you paid, your order will be confirmed by email.'));

                        return;
                    default:
                        this.completed = true;
                        if (this.popupSession) {
                            this.popupSession.close();
                        }
                        this.popupNotice(response.message || '');
                        this.popupBusy(false);
                }
            },

            finishPopup: function (notice) {
                this.popupNotice(notice);
                this.popupBusy(false);
                this.isPlaceOrderActionAllowed(true);
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
