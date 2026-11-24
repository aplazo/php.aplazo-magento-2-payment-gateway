#!/usr/bin/env python3
"""LOCAL TEST ONLY (BNPL-1664, Mike's UX points 1-4). Real checkout in the local Magento against the LOCAL MOCK (stub-module
for the Aplazo API, mock_https.py for Aplazo's window), same requirements as QA_SELECTION.md. Nothing reaches Aplazo.

  MAGENTO_DIR=~/Developer/Aplazo/magento248 python3 -I ux_e2e.py [scenario ...]

Scenarios (all by default):
  layer          the layer covers the checkout while Aplazo's window is open, and blocks it (clicks and focus)
  close_button   the layer's close button closes Aplazo's window; not paid -> layer removed, checkout usable, message
  closed_paid    the window closes with no message and the payment is recorded ~7 s later: loader, retries, success
                 page, and the minicart is empty
  closed_unpaid  the window closes with no message and nothing is ever paid: loader during the retries, then the
                 "closed without paying" message and the checkout usable again
Writes one line per scenario to ../.out/magento-e2e/pf.jsonl (like pf_e2e.py) and screenshots ux-*.png next to it.
"""
import base64, json, os, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import pf_e2e as k  # noqa: E402
from cdp import evaluate  # noqa: E402

OUT = os.path.join(HERE, '..', '.out', 'magento-e2e')

LAYER_JS = r"""
(() => {
  const o = document.querySelector('.aplazo-popup-overlay');
  const at = (el) => { if (!el) return null; el.scrollIntoView({block: 'center'}); const r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return !!(hit && o && o.contains(hit)); };
  const placeOrder = document.querySelector('input#aplazo_gateway') &&
    document.querySelector('input#aplazo_gateway').closest('.payment-method').querySelector('button.action.primary.checkout');
  const otherMethod = Array.from(document.querySelectorAll('input[name="payment[method]"]')).find(r => r.id !== 'aplazo_gateway');
  const blocked = Array.from(document.body.children).filter(el => el !== o);
  const spinner = o && o.querySelector('.aplazo-popup-overlay__spinner');
  const close = o && o.querySelector('.aplazo-popup-overlay__close'), link = o && o.querySelector('.aplazo-popup-overlay__focus');
  return {
    shown: !!o, state: o ? o.getAttribute('data-state') : null,
    message: o ? o.querySelector('.aplazo-popup-overlay__message').innerText.trim() : null,
    close_visible: !!(close && !close.hidden), focus_link_visible: !!(link && !link.hidden), focus_link_text: link ? link.innerText.trim() : null,
    spinner_visible: !!(spinner && getComputedStyle(spinner).display !== 'none'),
    page_children_inert: blocked.length > 0 && blocked.every(el => el.hasAttribute('inert')),
    place_order_covered: placeOrder ? at(placeOrder) : null,
    other_method_covered: otherMethod ? at(otherMethod) : null,
    minicart_covered: at(document.querySelector('.minicart-wrapper .action.showcart')),
    focus_inside_layer: !!(o && o.contains(document.activeElement)),
    notice: (document.querySelector('.aplazo-popup-notice') || {}).innerText || null,
    place_order_disabled: placeOrder ? placeOrder.disabled : null
  };
})()
"""
CLOSE_BUTTON_JS = ("(() => { const b = document.querySelector('.aplazo-popup-overlay__close'); const r = b.getBoundingClientRect(); "
                   "return {x: r.x + r.width / 2, y: r.y + r.height / 2}; })()")
MINICART_JS = r"""
(() => {
  let cart = null;
  try { cart = JSON.parse(localStorage['mage-cache-storage'] || '{}').cart || null; } catch (e) {}
  const n = document.querySelector('.minicart-wrapper .counter-number');
  return {path: location.pathname, summary_count: cart ? (cart.summary_count || 0) : null, items: cart && cart.items ? cart.items.length : null,
          counter_text: n ? n.innerText.trim() : null};
})()
"""


def layer(b):
    return evaluate(b.ws, LAYER_JS, timeout=20)


def shot(b, name):
    data = b.ws.send('Page.captureScreenshot', {'format': 'png'})['data']
    path = os.path.join(OUT, 'ux-%s.png' % name)
    with open(path, 'wb') as fh:
        fh.write(base64.b64decode(data))
    return path


def mouse_click(b, pos):
    for t in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        b.ws.send('Input.dispatchMouseEvent', {'type': t, 'x': pos['x'], 'y': pos['y'], 'button': 'left', 'clickCount': 1})


def t_layer():
    r = k.Run(); b = k.Browser(); k.default_loan('REQUEST')
    try:
        ok, a = k.open_popup(b, plan='stay')
        st = layer(b)
        png = shot(b, 'layer-open')
        good = (ok and st['shown'] and st['state'] == 'open' and st['close_visible'] and st['focus_link_visible']
                and st['page_children_inert'] and st['place_order_covered'] and st['minicart_covered']
                and st['other_method_covered'] in (True, None) and st['focus_inside_layer'] and len(b.popups()) == 1)
        k.record('ux_layer_while_open', good, layer=st, screenshot=png, attempt=a and a['cart_id'])
    finally:
        b.quit()


def t_close_button():
    r = k.Run(); b = k.Browser(); k.default_loan('REQUEST')
    try:
        ok, a = k.open_popup(b, plan='stay')
        popups_before = len(b.popups())
        mouse_click(b, evaluate(b.ws, CLOSE_BUTTON_JS))
        time.sleep(0.3)
        during = layer(b)
        png1 = shot(b, 'close-confirming')
        st = k.wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste', timeout=20)
        after = layer(b)
        png2 = shot(b, 'close-not-paid')
        checks = len(r.calls('getLoanStatus'))
        # The close button asks once: "not paid" ends it at once (no retries), unlike a window seen closed.
        good = (ok and popups_before == 1 and len(b.popups()) == 0 and after['shown'] is False and st
                and st['placeOrderDisabled'] is False and not r.orders() and checks == 1)
        k.record('ux_close_button_not_paid', good, during=during, after=after, page=st, popups_left=len(b.popups()), loan_checks=checks,
                 screenshots=[png1, png2], orders=r.orders())
    finally:
        b.quit()


def t_closed_paid():
    r = k.Run(); b = k.Browser(); k.default_loan('REQUEST')
    try:
        ok, a = k.open_popup(b, plan='stay')
        before_cart = evaluate(b.ws, MINICART_JS, timeout=20)
        b.close_popups()
        time.sleep(1)
        during = layer(b)
        png1 = shot(b, 'closed-confirming')
        time.sleep(6)
        still = layer(b)
        k.loan(a['cart_id'], 'OUTSTANDING')  # Aplazo records the payment late
        k.wait_until(lambda: '/checkout/onepage/success' in (k.page_state(b.ws) or {}).get('path', ''), 40)
        time.sleep(4)  # sections reload on the success page
        cart = evaluate(b.ws, MINICART_JS, timeout=20)
        png2 = shot(b, 'closed-paid-success')
        o = r.orders()
        good = (ok and during['shown'] and during['state'] == 'confirming' and during['spinner_visible']
                and still['shown'] and still['state'] == 'confirming' and 'closed' not in (still['notice'] or '')
                and len(o) == 1 and o[0]['increment_id'] == a['cart_id'] and cart['summary_count'] in (0, None)
                and (cart['counter_text'] in ('', '0', None)))
        k.record('ux_closed_then_paid_late', good, loan_checks=len(r.calls('getLoanStatus')), before_cart=before_cart, during=during, still_confirming=still,
                 cart_after=cart, orders=o, screenshots=[png1, png2])
    finally:
        b.quit()


def t_closed_unpaid():
    r = k.Run(); b = k.Browser(); k.default_loan('REQUEST')
    try:
        ok, a = k.open_popup(b, plan='stay')
        started = time.time()
        b.close_popups()
        time.sleep(1)
        during = layer(b)
        time.sleep(8)
        mid = layer(b)
        st = k.wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste', timeout=45)
        elapsed = round(time.time() - started, 1)
        after = layer(b)
        png = shot(b, 'closed-unpaid')
        q = k.quote(a['quote_id'])
        checks = len(r.calls('getLoanStatus'))
        # One check when the window is seen closed, then 8 retries every 3 s (~24 s); only then the message.
        good = (ok and during['state'] == 'confirming' and mid['state'] == 'confirming' and 'closed' not in (mid['notice'] or '')
                and st and st['placeOrderDisabled'] is False and after['shown'] is False and 20 <= elapsed <= 40
                and checks == 9 and not r.orders() and q['is_active'] == '1')
        k.record('ux_closed_without_paying', good, loan_checks=checks, during=during, mid=mid, after=after, page=st, seconds_to_message=elapsed,
                 quote=q, orders=r.orders(), screenshot=png)
    finally:
        b.quit()


TESTS = {'layer': t_layer, 'close_button': t_close_button, 'closed_paid': t_closed_paid, 'closed_unpaid': t_closed_unpaid}

if __name__ == '__main__':
    if not k.M or not os.path.isfile(os.path.join(k.M, 'bin', 'clinotty')):
        sys.exit('MAGENTO_DIR must point at the docker-magento directory')
    k.require_stub()
    for n in sys.argv[1:] or list(TESTS):
        try:
            TESTS[n]()
        except Exception as e:
            import traceback
            k.record('ux_' + n, False, error='%s: %s' % (type(e).__name__, e), where=traceback.format_exc().splitlines()[-3:])
        finally:
            k.reset_switches()
    print('\nSUMMARY ' + ' '.join('%s=%s' % (n, 'PASS' if ok else 'FAIL') for n, ok in k.RESULTS))
