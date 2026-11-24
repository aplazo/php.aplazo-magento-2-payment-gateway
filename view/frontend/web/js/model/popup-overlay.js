/*browser:true*/
/*global define*/
/**
 * Layer over the whole checkout while Aplazo's window is open or the payment is being confirmed (BNPL-1664), as
 * PayPal's in-context checkout does. While it is shown nothing behind it can be used: the rest of the page is made
 * inert (no clicks, focus or keyboard), so the buyer cannot change the payment method, edit the cart or pay again.
 *
 * Three states:
 *  - open: the payment happens in Aplazo's window. A link brings that window to the front (on macOS full screen,
 *    and on phones, it is a tab) and a close button closes it; what happens next is decided by the renderer.
 *  - confirming: the window is gone and the server is being asked; a loader, no actions.
 *  - message: the server could not show that the loan was not paid (uncertain), or it was paid without an order. The
 *    checkout stays blocked; an optional action (e.g. "Check again") asks the server once more.
 * Plain DOM, no Knockout: the element lives on <body>, outside the payment method template.
 */
define([], function () {
    'use strict';

    var CLASS = 'aplazo-popup-overlay';

    function Overlay(labels) {
        this.labels = labels || {};
        this.root = null;
        this.inerted = [];
        this.handlers = {};
    }

    Overlay.prototype.build = function () {
        var doc = window.document,
            box, focusLink, closeButton, spinner, message, actionButton;

        if (this.root !== null) {
            return;
        }
        this.root = doc.createElement('div');
        this.root.className = CLASS;
        this.root.setAttribute('role', 'dialog');
        this.root.setAttribute('aria-modal', 'true');
        this.root.setAttribute('aria-live', 'polite');

        box = doc.createElement('div');
        box.className = CLASS + '__box';

        closeButton = doc.createElement('button');
        closeButton.type = 'button';
        closeButton.className = CLASS + '__close';
        closeButton.setAttribute('aria-label', this.labels.close || 'Close');
        closeButton.textContent = '×';
        closeButton.addEventListener('click', this.fire.bind(this, 'close'));

        spinner = doc.createElement('div');
        spinner.className = CLASS + '__spinner';
        spinner.setAttribute('aria-hidden', 'true');

        message = doc.createElement('p');
        message.className = CLASS + '__message';

        focusLink = doc.createElement('a');
        focusLink.href = '#';
        focusLink.className = CLASS + '__focus';
        focusLink.textContent = this.labels.focus || '';
        focusLink.addEventListener('click', function (event) {
            event.preventDefault();
            this.fire('focus');
        }.bind(this));

        actionButton = doc.createElement('button');
        actionButton.type = 'button';
        actionButton.className = CLASS + '__action action primary';
        actionButton.addEventListener('click', this.fire.bind(this, 'action'));

        box.appendChild(closeButton);
        box.appendChild(spinner);
        box.appendChild(message);
        box.appendChild(focusLink);
        box.appendChild(actionButton);
        this.root.appendChild(box);
        this.message = message;
        this.closeButton = closeButton;
        this.focusLink = focusLink;
        this.actionButton = actionButton;
        doc.body.appendChild(this.root);
    };

    Overlay.prototype.fire = function (name) {
        if (typeof this.handlers[name] === 'function') {
            this.handlers[name]();
        }
    };

    /**
     * @param {String} text
     * @param {{focus: Function, close: Function}} handlers
     */
    Overlay.prototype.showOpen = function (text, handlers) {
        this.handlers = handlers || {};
        this.render('open', text);
    };

    Overlay.prototype.showConfirming = function (text) {
        this.handlers = {};
        this.render('confirming', text);
    };

    /**
     * The checkout stays blocked with a message. With an action, one button runs it.
     *
     * @param {String} text
     * @param {{label: String, handler: Function}|null} action
     */
    Overlay.prototype.showMessage = function (text, action) {
        this.handlers = action ? {action: action.handler} : {};
        this.actionLabel = action ? action.label : '';
        this.render('message', text);
    };

    Overlay.prototype.setText = function (text) {
        if (this.root !== null) {
            this.message.textContent = text || '';
        }
    };

    Overlay.prototype.render = function (state, text) {
        this.build();
        this.state = state;
        this.root.setAttribute('data-state', state);
        this.root.setAttribute('aria-busy', state === 'confirming' ? 'true' : 'false');
        this.message.textContent = text || '';
        this.closeButton.hidden = state !== 'open';
        this.focusLink.hidden = state !== 'open';
        this.actionButton.textContent = state === 'message' ? (this.actionLabel || '') : '';
        this.actionButton.hidden = state !== 'message' || !this.actionLabel;
        this.blockPage();
        if (state === 'open') {
            this.focusLink.focus();
        } else if (!this.actionButton.hidden) {
            this.actionButton.focus();
        }
    };

    Overlay.prototype.isShown = function () {
        return this.root !== null;
    };

    /**
     * Everything on <body> except the layer becomes inert; what was already inert is left as it was.
     */
    Overlay.prototype.blockPage = function () {
        var children = window.document.body.children,
            i, el;

        for (i = 0; i < children.length; i++) {
            el = children[i];
            if (el === this.root || el.hasAttribute('inert') || this.isBlocked(el)) {
                continue;
            }
            this.inerted.push({el: el, ariaHidden: el.getAttribute('aria-hidden')});
            el.setAttribute('inert', '');
            el.setAttribute('aria-hidden', 'true');
        }
    };

    Overlay.prototype.isBlocked = function (el) {
        return this.inerted.some(function (entry) {
            return entry.el === el;
        });
    };

    Overlay.prototype.hide = function () {
        this.inerted.forEach(function (entry) {
            entry.el.removeAttribute('inert');
            if (entry.ariaHidden === null) {
                entry.el.removeAttribute('aria-hidden');
            } else {
                entry.el.setAttribute('aria-hidden', entry.ariaHidden);
            }
        });
        this.inerted = [];
        this.handlers = {};
        this.state = null;
        if (this.root !== null && this.root.parentNode) {
            this.root.parentNode.removeChild(this.root);
        }
        this.root = null;
    };

    return {
        create: function (labels) {
            return new Overlay(labels);
        }
    };
});
