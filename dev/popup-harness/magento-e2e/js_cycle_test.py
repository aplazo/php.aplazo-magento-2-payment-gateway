#!/usr/bin/env python3
"""LOCAL TEST ONLY (BNPL-1664, frontend late answers). Isolated test of aplazo-gateway.js outside Magento: the module
runs in the kit's headless Chrome on about:blank, with stubs for jQuery ($.ajax returns deferreds the test resolves by
hand), the Knockout component, the popup window (a fake session per opening) and window.setTimeout (queued, fired by
hand). Nothing is sent anywhere. Runs the same scenarios against the module given on the command line:
    python3 -I js_cycle_test.py <path/to/aplazo-gateway.js> [label]
and prints one JSON line per scenario."""
import json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from pf_e2e import Browser  # noqa: E402
from cdp import evaluate  # noqa: E402

HARNESS = r"""
(function () {
  var calls = [], timers = [], sessions = [], located = [];
  function D() {
    var d = {s: 'pending', v: null, ok: [], ko: []};
    d.done = function (f) { if (d.s === 'resolved') f(d.v); else d.ok.push(f); return d; };
    d.fail = function (f) { if (d.s === 'rejected') f(d.v); else d.ko.push(f); return d; };
    d.resolve = function (v) { d.s = 'resolved'; d.v = v; d.ok.forEach(function (f) { f(v); }); };
    d.reject = function (v) { d.s = 'rejected'; d.v = v; d.ko.forEach(function (f) { f(v); }); };
    return d;
  }
  var $ = function () { return {on: function () {}}; };
  $.ajax = function (o) { var d = D(); calls.push({url: o.url, data: o.data, d: d}); return d; };
  $.mage = {cookies: {get: function () { return 'fk'; }}};
  var Component = {extend: function (proto) {
    function make() {
      var inst = Object.create(proto);
      // Like uiComponent, the defaults become instance properties.
      Object.keys(proto.defaults || {}).forEach(function (k) { inst[k] = proto.defaults[k]; });
      inst._super = function () { return {observe: function (names) {
        names.forEach(function (n) { var v = proto.defaults[n]; inst[n] = function (x) { if (arguments.length) { v = x; } return v; }; });
        return inst; }}; };
      var allowed = true;
      inst.isPlaceOrderActionAllowed = function (x) { if (arguments.length) { allowed = x; } return allowed; };
      inst.validate = function () { return true; };
      inst.messageContainer = {};
      inst.initObservable();
      delete inst._super;
      return inst;
    }
    return {make: make};
  }};
  Component.prototype = {placeOrder: function () { located.push('redirect placeOrder'); }};
  var popupCheckout = {
    normalizeOrigin: function (o) { return o; },
    create: function (origin, handlers) {
      var s = {id: sessions.length + 1, handlers: handlers, opened: false, closedCount: 0, isOpenFlag: false,
        open: function () { s.opened = true; s.isOpenFlag = true; return true; },
        navigate: function () { return true; }, focusCount: 0, focus: function () { s.focusCount++; },
        close: function () { s.closedCount++; s.isOpenFlag = false; },
        isOpen: function () { return s.isOpenFlag; }};
      sessions.push(s);
      return s;
    }
  };
  var overlays = [], invalidated = [];
  var popupOverlay = {create: function (labels) {
    var o = {labels: labels, states: [], state: null, text: null, handlers: {},
      showOpen: function (t, h) { o.state = 'open'; o.text = t; o.handlers = h || {}; o.states.push('open'); },
      showConfirming: function (t) { o.state = 'confirming'; o.text = t; o.handlers = {}; o.states.push('confirming'); },
      setText: function (t) { o.text = t; },
      hide: function () { if (o.state !== null) { o.states.push('hidden'); } o.state = null; o.handlers = {}; },
      isShown: function () { return o.state !== null; }};
    overlays.push(o);
    return o;
  }};
  var customerData = {invalidate: function (names) { invalidated.push(names.join(',')); }};
  var setPaymentInformation = function () { var d = D(); d.resolve(); return d; };
  window.setTimeout = function (f) { timers.push(f); return timers.length; };
  window.checkoutConfig = {payment: {aplazo_gateway: {checkoutMode: 'popup', popupStartUrl: '/start', popupCompleteUrl: '/complete', aplazoOrigin: 'https://checkout.aplazo.net'}}};
  window.__h = {calls: calls, timers: timers, sessions: sessions, located: located, overlays: overlays, invalidated: invalidated};
  window.define = function (deps, factory) {
    var map = {'jquery': $, 'Magento_Checkout/js/view/payment/default': Component, 'mage/url': {},
      'Magento_Checkout/js/model/quote': {}, 'Magento_Checkout/js/model/totals': {}, 'Magento_Catalog/js/price-utils': {},
      'mage/translate': function (t) { return t; }, 'Magento_Checkout/js/model/payment/additional-validators': {validate: function () { return true; }},
      'Magento_Checkout/js/action/set-payment-information': setPaymentInformation,
      'Aplazo_AplazoPayment/js/model/popup-checkout': popupCheckout,
      'Aplazo_AplazoPayment/js/model/webview': {isWebView: function () { return false; }}, 'mage/cookies': {},
      'Aplazo_AplazoPayment/js/model/popup-overlay': popupOverlay, 'Magento_Customer/js/customer-data': customerData};
    window.__C = factory.apply(null, deps.map(function (n) { return map[n]; }));
  };
})();
"""

SCENARIOS = r"""
(function () { try {
  var H = window.__h, out = {};
  function view() { return window.__C.make(); }
  function completeCalls() { return H.calls.filter(function (c) { return c.url === '/complete'; }); }
  function last(url) { var l = H.calls.filter(function (c) { return c.url === url; }); return l[l.length - 1]; }
  function snapshot(v, s) { return {busy: v.popupBusy(), notice: v.popupNotice(), completed: v.completed, attempt: v.popupAttempt,
    allowed: v.isPlaceOrderActionAllowed(), session_closed_count: s ? s.closedCount : null, session_open: s ? s.isOpen() : null}; }

  // Opening 1 reaches a state where the page can be used again while an earlier check and a retry timer are still
  // pending: Aplazo says success (retries armed), the window is closed, the check answers "pending" (a retry timer is
  // queued; the window is closed, so the page is released), and a focus starts one more check that stays in flight.
  function cycleOneLeavesWorkPending(v, noInflight) {
    v.placeOrder({}, null);
    var s1 = H.sessions[H.sessions.length - 1];
    last('/start').d.resolve({status: 'open', url: 'https://checkout.aplazo.net/1', attempt: 'A1'});
    s1.isOpenFlag = false;
    s1.handlers.signal('success');
    last('/complete').d.resolve({status: 'pending'});
    var timersBefore = H.timers.length;
    // The retry budget is spent (as after the last retry): the next answer releases the page, the timer stays queued.
    v.retries = 0;
    v.onPageFocus();
    last('/complete').d.resolve({status: 'pending'});
    var inflight = null;
    if (!noInflight) {
      v.onPageFocus();
      inflight = last('/complete');
    }
    return {s1: s1, inflight: inflight, staleTimer: H.timers[timersBefore - 1], released: !v.popupBusy()};
  }
  function cycleTwoOpens(v) {
    v.placeOrder({}, null);
    var s2 = H.sessions[H.sessions.length - 1];
    last('/start').d.resolve({status: 'open', url: 'https://checkout.aplazo.net/2', attempt: 'A2'});
    return s2;
  }

  // 1. A late SUCCESSFUL answer of opening 1 arrives during opening 2.
  (function () {
    var v = view(), p = cycleOneLeavesWorkPending(v), s2 = cycleTwoOpens(v);
    var before = snapshot(v, s2);
    p.inflight.d.resolve({status: 'completed', notice: 'old opening completed'});
    out.late_success = {page_released_after_opening_1: p.released, check_sent_for: p.inflight.data.attempt, before: before, after: snapshot(v, s2)};
  })();
  // 2. A late FAILED answer of opening 1 arrives during opening 2.
  (function () {
    var v = view(), p = cycleOneLeavesWorkPending(v), s2 = cycleTwoOpens(v);
    var before = snapshot(v, s2), timersBefore = H.timers.length, checksBefore = completeCalls().length;
    p.inflight.d.reject();
    out.late_failure = {before: before, after: snapshot(v, s2), new_timers: H.timers.length - timersBefore,
      new_checks: completeCalls().length - checksBefore};
  })();
  // 3. The retry timer of opening 1 fires during opening 2 (no check of opening 1 in flight).
  (function () {
    var v = view(), p = cycleOneLeavesWorkPending(v, true), s2 = cycleTwoOpens(v);
    var checksBefore = completeCalls().length;
    p.staleTimer();
    var sent = completeCalls().slice(checksBefore).map(function (c) { return c.data.attempt; });
    out.stale_timer = {checks_started_by_old_timer: sent.length, for_attempts: sent, state: snapshot(v, s2)};
  })();
  // 4. An unanswered check of opening 1 must not block the checks of opening 2.
  (function () {
    var v = view(), p = cycleOneLeavesWorkPending(v), s2 = cycleTwoOpens(v);
    var checksBefore = completeCalls().length;
    s2.isOpenFlag = false;
    s2.handlers.closed();
    var sent = completeCalls().slice(checksBefore).map(function (c) { return c.data.attempt; });
    out.not_blocked = {old_check_still_pending: p.inflight.d.s === 'pending', checks_sent_for_opening_2: sent};
  })();
  // 5. A window event of opening 1 (its handlers) during opening 2.
  (function () {
    var v = view(), p = cycleOneLeavesWorkPending(v), s2 = cycleTwoOpens(v);
    var checksBefore = completeCalls().length, before = snapshot(v, s2);
    p.s1.handlers.signal('failure');
    p.s1.handlers.closed();
    out.stale_window_events = {checks_started: completeCalls().length - checksBefore, before: before, after: snapshot(v, s2)};
  })();
  // 6. Normal completion of one opening.
  (function () {
    var v = view();
    v.placeOrder({}, null);
    var s = H.sessions[H.sessions.length - 1];
    last('/start').d.resolve({status: 'open', url: 'https://checkout.aplazo.net/n', attempt: 'AN'});
    var opened = snapshot(v, s);
    s.handlers.signal('success');
    var check = last('/complete');
    check.d.resolve({status: 'completed', notice: 'Order placed'});
    out.normal_completion = {opened: opened, check_for: check.data.attempt, after: snapshot(v, s)};
  })();
  // 7. The start request itself fails (AJAX error): the message shown.
  (function () {
    var v = view();
    v.placeOrder({}, null);
    var s = H.sessions[H.sessions.length - 1];
    last('/start').d.reject();
    out.start_ajax_failure = {state: snapshot(v, s), redirect_fallback: H.located.length};
  })();

  // ---- BNPL-1664 UX (Mike, 20-nov): layer, close button, loader, retries after the window closes, minicart. ----
  // Only for a module that has the layer (an older aplazo-gateway.js runs scenarios 1-7 only).
  if (view().overlay) {
  function overlayOf(v) { return v.overlay; }
  function openOne(v, attempt) {
    v.placeOrder({}, null);
    var s = H.sessions[H.sessions.length - 1];
    last('/start').d.resolve({status: 'open', url: 'https://checkout.aplazo.net/' + attempt, attempt: attempt});
    return s;
  }
  function answerChecks(list) { list.forEach(function (st) { last('/complete').d.resolve(st); var t = H.timers[H.timers.length - 1]; if (t && !t.fired) { t.fired = true; t(); } }); }
  // 8. The layer is shown while the window is open, with the focus link and the close button.
  (function () {
    var v = view(), s = openOne(v, 'L1'), o = overlayOf(v);
    o.handlers.focus();
    out.layer_while_open = {layer: o.state, has_focus_link: typeof o.handlers.focus === 'function',
      has_close: typeof o.handlers.close === 'function', window_focused: s.focusCount, busy: v.popupBusy(), allowed: v.isPlaceOrderActionAllowed()};
  })();
  // 9. Close button: the window is closed, the server asked once; not paid -> layer removed, checkout usable again.
  (function () {
    var v = view(), s = openOne(v, 'L2'), o = overlayOf(v), checks = completeCalls().length;
    o.handlers.close();
    var during = {layer: o.state, window_closed: s.closedCount > 0, checks: completeCalls().length - checks};
    last('/complete').d.resolve({status: 'not_paid'});
    out.close_button_not_paid = {during: during, after_layer: o.state, notice: v.popupNotice(), busy: v.popupBusy(),
      allowed: v.isPlaceOrderActionAllowed(), retry_timers: 0};
  })();
  // 10. Window seen closed (no message): loader, retries every 3 s while Aplazo says not paid, then paid -> success.
  (function () {
    var v = view(), s = openOne(v, 'L3'), o = overlayOf(v), checks = completeCalls().length;
    s.isOpenFlag = false;
    s.handlers.closed();
    var states = [];
    ['not_paid', 'not_paid', 'pending'].forEach(function (st) {
      var timersBefore = H.timers.length;
      last('/complete').d.resolve({status: st});
      states.push({answer: st, layer: o.state, notice: v.popupNotice(), busy: v.popupBusy(), timer_queued: H.timers.length > timersBefore});
      H.timers[H.timers.length - 1]();
    });
    var inv = H.invalidated.length;
    last('/complete').d.resolve({status: 'completed', redirect: 'about:blank#success'});
    out.closed_then_paid = {steps: states, checks: completeCalls().length - checks, layer_at_redirect: o.state,
      invalidated: H.invalidated.slice(inv)};
  })();
  // 11. Window seen closed and never paid: "closed without paying" only after the last retry (8 retries).
  (function () {
    var v = view(), s = openOne(v, 'L4'), o = overlayOf(v), checks = completeCalls().length, notices = [];
    s.isOpenFlag = false;
    s.handlers.closed();
    for (var i = 0; i < 20; i++) {
      var timersBefore = H.timers.length;
      last('/complete').d.resolve({status: 'not_paid'});
      notices.push(v.popupNotice());
      if (H.timers.length === timersBefore) { break; }
      H.timers[H.timers.length - 1]();
    }
    out.closed_never_paid = {checks: completeCalls().length - checks, released_after: notices.length,
      notice_before_last: notices[notices.length - 2], final_notice: v.popupNotice(), layer: o.state,
      busy: v.popupBusy(), allowed: v.isPlaceOrderActionAllowed()};
  })();
  // 12. Close button before the start answered: no navigation; the answer triggers a check, not a reopened window.
  (function () {
    var v = view();
    v.placeOrder({}, null);
    var s = H.sessions[H.sessions.length - 1], o = overlayOf(v), checks = completeCalls().length;
    o.handlers.close();
    var layerBefore = o.state;
    last('/start').d.resolve({status: 'open', url: 'https://checkout.aplazo.net/L5', attempt: 'L5'});
    var sent = completeCalls().slice(checks).map(function (c) { return c.data.attempt; });
    last('/complete').d.resolve({status: 'not_paid'});
    out.close_before_start_answer = {layer_after_close: layerBefore, checks_for: sent, after_layer: o.state,
      notice: v.popupNotice(), allowed: v.isPlaceOrderActionAllowed()};
  })();
  // 13. Completed on a page whose session holds another cart (notice, no redirect): layer removed, caches invalidated.
  (function () {
    var v = view(), s = openOne(v, 'L6'), o = overlayOf(v), inv = H.invalidated.length;
    s.handlers.signal('success');
    var layerChecking = o.state;
    last('/complete').d.resolve({status: 'completed', notice: 'Your order 1 was confirmed.'});
    out.completed_notice = {layer_while_checking: layerChecking, after_layer: o.state, notice: v.popupNotice(),
      busy: v.popupBusy(), invalidated: H.invalidated.slice(inv)};
  })();
  }
  return JSON.stringify(out);
} catch (e) { return JSON.stringify({harness_error: String(e && e.stack || e)}); } })()
"""


def main():
    path, label = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else os.path.basename(sys.argv[1]))
    source = open(path, encoding='utf-8').read()
    b = Browser()
    try:
        b.ws.send('Page.navigate', {'url': 'about:blank'})
        evaluate(b.ws, HARNESS + '; true')
        loaded = evaluate(b.ws, '(function () { try {' + source + '; return typeof window.__C; } catch (e) { return "load error: " + e; } })()')
        if loaded != 'object':
            print(json.dumps({'module': label, 'load': loaded}))
        result = json.loads(evaluate(b.ws, SCENARIOS, timeout=60))
        for name, data in result.items():
            print(json.dumps({'module': label, 'scenario': name, 'result': data}, ensure_ascii=False))
    finally:
        b.quit()


if __name__ == '__main__':
    main()
