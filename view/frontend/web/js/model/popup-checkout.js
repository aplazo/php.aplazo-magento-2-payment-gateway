/*browser:true*/
/*global define*/
/**
 * Aplazo checkout opened in its own window (BNPL-1607).
 *
 * The message contract is provisional until Checkout confirms it:
 * { type: 'aplazo:checkout:<success|failure|close|pending>', version: 1 }
 * Messages are a UI signal only: they never mark an order as paid, and any URL they carry is ignored.
 */
define([], function () {
    'use strict';

    var MESSAGE_PREFIX = 'aplazo:checkout:',
        MESSAGE_VERSION = 1,
        WINDOW_NAME_PREFIX = 'aplazo_checkout_',
        WINDOW_FEATURES = 'width=480,height=760',
        WATCH_INTERVAL = 500,
        TERMINAL_STATES = ['success', 'failure', 'close'];

    /**
     * Returns the origin when it is a bare https origin (http only for localhost test servers).
     */
    function normalizeOrigin(value) {
        var parsed;

        if (typeof value !== 'string' || value === '') {
            return null;
        }
        try {
            parsed = new URL(value);
        } catch (e) {
            return null;
        }
        if (parsed.origin !== value) {
            return null;
        }
        if (parsed.protocol === 'https:') {
            return parsed.origin;
        }
        if (parsed.protocol === 'http:' && (parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1')) {
            return parsed.origin;
        }

        return null;
    }

    function PopupCheckout(origin, handlers) {
        this.origin = origin;
        this.handlers = handlers || {};
        this.popup = null;
        this.listener = null;
        this.watcher = null;
        this.pending = false;
        this.disposeOnPageHide = this.dispose.bind(this);
    }

    /**
     * Must run synchronously inside the click so the browser does not block the window.
     * Each attempt gets its own window name, so another tab's Aplazo window is never reused.
     * Any error opening the window is treated as a blocked popup.
     */
    PopupCheckout.prototype.open = function () {
        var name = WINDOW_NAME_PREFIX + Date.now().toString(36) + Math.random().toString(36).slice(2);

        try {
            this.popup = window.open('', name, WINDOW_FEATURES) || null;
        } catch (e) {
            this.popup = null;
        }

        return this.popup !== null;
    };

    PopupCheckout.prototype.isOpen = function () {
        return this.popup !== null && !this.popup.closed;
    };

    /**
     * Starts listening before the window leaves about:blank, so no early message is lost.
     * Returns false, with everything released, when the window cannot be navigated.
     */
    PopupCheckout.prototype.navigate = function (url) {
        this.listen();
        try {
            this.popup.location.href = url;
        } catch (e) {
            this.close();

            return false;
        }
        this.watch();

        return true;
    };

    PopupCheckout.prototype.focus = function () {
        try {
            this.popup.focus();
        } catch (e) {
            // Focusing is best effort.
        }
    };

    PopupCheckout.prototype.listen = function () {
        this.stopListening();
        this.listener = this.onMessage.bind(this);
        window.addEventListener('message', this.listener);
        window.addEventListener('pagehide', this.disposeOnPageHide);
    };

    PopupCheckout.prototype.onMessage = function (event) {
        var data = event.data,
            state;

        if (event.origin !== this.origin || this.popup === null || event.source !== this.popup) {
            return;
        }
        if (!data || typeof data !== 'object' || data.version !== MESSAGE_VERSION ||
            typeof data.type !== 'string' || data.type.indexOf(MESSAGE_PREFIX) !== 0
        ) {
            return;
        }
        state = data.type.slice(MESSAGE_PREFIX.length);

        if (state === 'pending') {
            this.pending = true;
            this.notify('pending');

            return;
        }
        if (TERMINAL_STATES.indexOf(state) === -1) {
            return;
        }
        this.close();
        this.notify(state);
    };

    /**
     * popup.closed may also mean the opener lost its reference (for example COOP on the checkout page),
     * so it is reported as lost communication, never as a cancellation.
     */
    PopupCheckout.prototype.watch = function () {
        var self = this;

        this.stopWatching();
        this.watcher = window.setInterval(function () {
            if (self.popup !== null && self.popup.closed) {
                self.stopWatching();
                if (!self.pending) {
                    self.stopListening();
                }
                self.notify('lost', {pending: self.pending});
            }
        }, WATCH_INTERVAL);
    };

    PopupCheckout.prototype.notify = function (state, detail) {
        if (typeof this.handlers[state] === 'function') {
            this.handlers[state](detail || {});
        }
    };

    PopupCheckout.prototype.stopListening = function () {
        if (this.listener !== null) {
            window.removeEventListener('message', this.listener);
            this.listener = null;
        }
        window.removeEventListener('pagehide', this.disposeOnPageHide);
    };

    PopupCheckout.prototype.stopWatching = function () {
        if (this.watcher !== null) {
            window.clearInterval(this.watcher);
            this.watcher = null;
        }
    };

    PopupCheckout.prototype.dispose = function () {
        this.stopWatching();
        this.stopListening();
    };

    PopupCheckout.prototype.close = function () {
        this.dispose();
        try {
            if (this.popup !== null && !this.popup.closed) {
                this.popup.close();
            }
        } catch (e) {
            // The window is already unreachable.
        }
    };

    return {
        MESSAGE_PREFIX: MESSAGE_PREFIX,
        MESSAGE_VERSION: MESSAGE_VERSION,
        normalizeOrigin: normalizeOrigin,
        create: function (origin, handlers) {
            return new PopupCheckout(origin, handlers);
        }
    };
});
