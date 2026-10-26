"""LOCAL TEST ONLY (BNPL-1664). Drives a real local Magento checkout in a test Chrome with the Aplazo integrations
replaced by the stub module in stub-module/. See ../VALIDATION-BNPL-1664.md for the setup.

  MAGENTO_DIR=~/path/to/docker-magento python3 -I run.py <test> [<test> ...]

Every test that reaches Place Order creates one local order. Nothing pays, cancels or calls Aplazo: the stub answers
the API, and the popup lands on mock_https.py through Chrome's --host-resolver-rules (only this test browser).

Environment: MAGENTO_DIR (docker-magento directory with bin/), MAGENTO_BASE_URL (https://magento.test),
MAGENTO_PRODUCT_PATH (aplazo-producto-prueba.html), MAGENTO_PRODUCT_SKU (APLAZO-TEST-001), CHROME, OUT.
"""
import json, os, shutil, ssl, subprocess, sys, tempfile, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
from cdp import WS, evaluate, wait_for  # noqa: E402

M = os.path.expanduser(os.environ.get('MAGENTO_DIR', ''))
BASE = os.environ.get('MAGENTO_BASE_URL', 'https://magento.test').rstrip('/')
PRODUCT_PATH = os.environ.get('MAGENTO_PRODUCT_PATH', 'aplazo-producto-prueba.html').lstrip('/')
SKU = os.environ.get('MAGENTO_PRODUCT_SKU', 'APLAZO-TEST-001')
CHROME = os.environ.get('CHROME', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
OUT = os.path.abspath(os.environ.get('OUT', os.path.join(HERE, '..', '.out', 'magento-e2e')))
RESULTS = os.path.join(OUT, 'results.jsonl')
CTX = ssl.create_default_context(); CTX.check_hostname = False; CTX.verify_mode = ssl.CERT_NONE


# ---------- Magento (through docker-magento's bin/ scripts) ----------
def sql(q):
    return subprocess.run(['bin/mysql', '-N', '-e', q], cwd=M, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout.strip()


def in_container(cmd):
    return subprocess.run(['bin/clinotty', 'sh', '-c', cmd], cwd=M, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout


def last_order_id():
    return int(sql('SELECT COALESCE(MAX(entity_id),0) FROM sales_order') or 0)


def orders_after(eid):
    rows = sql('SELECT entity_id, increment_id, state, status FROM sales_order WHERE entity_id > %d ORDER BY entity_id' % eid)
    return [dict(zip(('entity_id', 'increment_id', 'state', 'status'), r.split('\t'))) for r in rows.splitlines() if r]


def snapshot(*incs):
    with open(os.path.join(HERE, 'snapshot.php')) as fh:
        out = subprocess.run(['bin/clinotty', 'php', '--', SKU] + list(incs), cwd=M, capture_output=True, text=True, stdin=fh).stdout
    return json.loads(out[out.index('{'):])


def reservations(inc):
    return snapshot(inc)['orders'][inc]['reservations']


def stub_calls(inc):
    rows = [json.loads(l) for l in in_container('cat var/log/localdev_aplazo_stub.log 2>/dev/null').splitlines() if l.startswith('{')]
    return [r.get('method') for r in rows if inc and inc in json.dumps(r) and r.get('service') == 'api']


def set_delay(seconds):
    in_container('echo %d > var/localdev_aplazo_stub_delay' % seconds if seconds else 'rm -f var/localdev_aplazo_stub_delay')


# ---------- mock ----------
def mock(path, method='GET'):
    with urllib.request.urlopen(urllib.request.Request('https://127.0.0.1:8443' + path, method=method), context=CTX) as r:
        body = r.read()
    return json.loads(body) if body else None


def scenario(name):
    mock('/__scenario?name=' + name, 'POST')


def hits_for(inc):
    return [h for h in mock('/__hits') if ('stub-' + inc) in json.dumps(h)]


# ---------- browser ----------
class Browser:
    def __init__(self, port, block_popups=False, flags=()):
        self.port = port
        self.profile = tempfile.mkdtemp(prefix='bnpl1664-e2e-')
        if block_popups:
            os.makedirs(os.path.join(self.profile, 'Default'))
            with open(os.path.join(self.profile, 'Default', 'Preferences'), 'w') as fh:
                json.dump({'profile': {'content_settings': {'exceptions': {'popups': {BASE + ',*': {'setting': 2}}}}}}, fh)
        args = [CHROME, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                '--ignore-certificate-errors', '--host-resolver-rules=MAP checkout.aplazo.net 127.0.0.1:8443',
                '--remote-debugging-port=%d' % port, '--user-data-dir=' + self.profile, '--window-size=1280,1000'] + list(flags)
        self.proc = subprocess.Popen(args + ['about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(80):
            try:
                page = [t for t in self.targets() if t['type'] == 'page'][0]
                break
            except Exception:
                time.sleep(0.25)
        self.main_id = page['id']
        self.ws = self.attach(page)

    def targets(self):
        return json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % self.port))

    def attach(self, target):
        ws = WS(target['webSocketDebuggerUrl'])
        ws.s.settimeout(150)
        ws.send('Page.enable'); ws.send('Runtime.enable'); ws.send('Log.enable')
        return ws

    def others(self):
        return [t for t in self.targets() if t['type'] == 'page' and t['id'] != self.main_id]

    def close_others(self):
        for t in self.others():
            urllib.request.urlopen('http://127.0.0.1:%d/json/close/%s' % (self.port, t['id']))
        time.sleep(1.5)

    def quit(self):
        self.proc.kill(); self.proc.wait()
        shutil.rmtree(self.profile, ignore_errors=True)


FILL = r"""
(async (email) => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const set = (sel, v) => { const el = document.querySelector(sel); if (!el) return false;
    el.value = v; el.dispatchEvent(new Event('change', {bubbles:true})); el.dispatchEvent(new Event('keyup', {bubbles:true})); return true; };
  for (let i = 0; i < 60 && !document.querySelector('#customer-email'); i++) await sleep(500);
  set('#customer-email', email);
  set('[name="firstname"]', 'Demo'); set('[name="lastname"]', 'BNPL1664');
  set('[name="street[0]"]', 'Calle Demo 1'); set('[name="city"]', 'Ciudad de Mexico');
  set('[name="country_id"]', 'MX'); await sleep(800);
  const region = document.querySelector('[name="region_id"]');
  if (region && region.options.length > 1) { region.selectedIndex = 1; region.dispatchEvent(new Event('change', {bubbles:true})); }
  set('[name="postcode"]', '06600'); set('[name="telephone"]', '5500000000');
  for (let i = 0; i < 40 && !document.querySelector('#checkout-shipping-method-load input[type=radio]'); i++) await sleep(500);
  const rate = document.querySelector('#checkout-shipping-method-load input[type=radio]'); if (rate) rate.click();
  await sleep(1000);
  const next = document.querySelector('#shipping-method-buttons-container button, button.continue');
  if (next) next.click();
  for (let i = 0; i < 60 && !document.querySelector('input#aplazo_gateway'); i++) await sleep(500);
  const radio = document.querySelector('input#aplazo_gateway'); if (radio) radio.click();
  for (let i = 0; i < 20 && !(radio && radio.checked); i++) await sleep(300);
  await sleep(1500);
  return !!radio && radio.checked;
})
"""
STATE_JS = r"""
(() => {
  const box = document.querySelector('input#aplazo_gateway') && document.querySelector('input#aplazo_gateway').closest('.payment-method');
  const q = s => box ? box.querySelector(s) : null;
  const btn = q('button.action.primary.checkout'), n = q('.aplazo-popup-notice'), a = q('.aplazo-popup-cart a');
  return {href: location.href, notice: n ? n.innerText.trim() : null, cartHref: a ? a.getAttribute('href') : null,
          cartText: a ? a.innerText.trim() : null, reopenRendered: !!q('.aplazo-popup-reopen'),
          placeOrderDisabled: btn ? btn.disabled : null};
})()
"""
BUTTON_JS = ("(() => { const b = document.querySelector('input#aplazo_gateway').closest('.payment-method')"
             ".querySelector('button.action.primary.checkout'); b.scrollIntoView({block: 'center'}); const r = b.getBoundingClientRect(); "
             "return {x: r.x + r.width / 2, y: r.y + r.height / 2}; })()")


def start_checkout(b, email):
    ws = b.ws
    ws.send('Page.navigate', {'url': BASE + '/' + PRODUCT_PATH})
    wait_for(ws, "document.readyState==='complete' && !!document.querySelector('#product-addtocart-button:not([disabled])')", 60)
    time.sleep(2)
    evaluate(ws, "document.querySelector('#product-addtocart-button').click(); true")
    wait_for(ws, "(window.localStorage['mage-cache-storage']||'').indexOf(%s)>-1" % json.dumps(SKU), 40)
    time.sleep(2)
    ws.send('Page.navigate', {'url': BASE + '/checkout/'})
    wait_for(ws, "!!document.querySelector('#checkout-step-shipping')", 90)
    ok = evaluate(ws, '(%s)(%s)' % (FILL, json.dumps(email)), timeout=120)
    cfg = evaluate(ws, "JSON.stringify(((window.checkoutConfig.payment||{}).aplazo_gateway)||null)")
    cfg = json.loads(cfg) if cfg else None
    return ok, {k: cfg.get(k) for k in ('checkoutMode', 'aplazoOrigin')} if cfg else None


def click_place_order(b, trusted=True, clicks=1):
    if not trusted:
        evaluate(b.ws, BUTTON_JS.replace('b.scrollIntoView', 'b.click(); b.scrollIntoView'))
        return
    evaluate(b.ws, BUTTON_JS); time.sleep(0.4)
    r = evaluate(b.ws, BUTTON_JS)
    for _ in range(clicks):
        for t in ('mouseMoved', 'mousePressed', 'mouseReleased'):
            b.ws.send('Input.dispatchMouseEvent', {'type': t, 'x': r['x'], 'y': r['y'], 'button': 'left', 'clickCount': 1})


def wait_new_order(after, timeout=60):
    end = time.time() + timeout
    while time.time() < end:
        o = orders_after(after)
        if o:
            return o
        time.sleep(0.5)
    return []


def state(b):
    return evaluate(b.ws, STATE_JS, timeout=20)


def record(name, data):
    os.makedirs(OUT, exist_ok=True)
    data = dict(test=name, at=time.strftime('%Y-%m-%d %H:%M:%S %Z'), **data)
    with open(RESULTS, 'a') as fh:
        fh.write(json.dumps(data, ensure_ascii=False) + '\n')
    print(json.dumps(data, ensure_ascii=False, indent=1))


# ---------- tests ----------
def popup_flow(name, plan, after_open=None, settle=4):
    """Trusted Place Order in popup mode; the popup reaches the mock with `plan`."""
    scenario(plan)
    before = last_order_id()
    b = Browser(9531)
    try:
        ok, cfg = start_checkout(b, name + '@example.invalid')
        click_place_order(b)
        orders = wait_new_order(before)
        inc = orders[0]['increment_id'] if orders else None
        time.sleep(settle)
        extra = after_open(b, inc) if after_open else {}
        time.sleep(1.5)
        res = {'reached_payment': ok, 'config': cfg, 'orders': orders, 'page': state(b),
               'windows_open': [t['url'][:90] for t in b.others()], 'mock_hits': hits_for(inc) if inc else [],
               'api_calls': stub_calls(inc), 'reservations': reservations(inc) if inc else None, 'extra': extra}
    finally:
        b.quit()
    record(name, res)
    return res


def t_terminal(plan):
    return lambda: popup_flow('popup_' + plan, plan)


def t_lost():
    popup_flow('popup_lost', 'stay', after_open=lambda b, inc: (b.close_others(), {})[1])


def t_pending_then_lost():
    def act(b, inc):
        pending = state(b)
        open_while_pending = len(b.others())
        b.close_others()
        return {'pending_state': pending, 'windows_open_while_pending': open_while_pending}
    popup_flow('popup_pending_then_lost', 'pending_stay', after_open=act)


def t_repeat_purchase():
    """Reload and back in the popup, and a repeated GET of the purchase URL (BNPL-1665 / preexisting redirect defect)."""
    def act(b, inc):
        p = b.attach(b.others()[0])
        r0 = reservations(inc)
        p.send('Page.reload'); time.sleep(3)
        r1 = reservations(inc)
        url = evaluate(b.ws, "require('ko').dataFor(document.querySelector('input#aplazo_gateway')).popupPurchaseUrl")
        p.send('Page.navigate', {'url': url}); time.sleep(3)
        r2 = reservations(inc)
        evaluate(p, 'history.back(); true'); time.sleep(3)
        return {'after_open': r0, 'after_reload': r1, 'after_repeat_get': r2, 'after_back': reservations(inc)}
    popup_flow('popup_repeat_purchase', 'stay', after_open=act)


def t_closed_before_checkout():
    set_delay(4)
    scenario('stay')
    before = last_order_id()
    b = Browser(9532)
    try:
        ok, cfg = start_checkout(b, 'popup_early_close@example.invalid')
        click_place_order(b)
        time.sleep(1.0)
        closed = len(b.others())
        b.close_others()
        orders = wait_new_order(before, 30)
        inc = orders[0]['increment_id'] if orders else None
        time.sleep(3)
        res = {'reached_payment': ok, 'closed_during_place_order': closed, 'orders': orders, 'page': state(b),
               'mock_hits': hits_for(inc) if inc else [], 'reservations': reservations(inc) if inc else None}
    finally:
        b.quit()
        set_delay(0)
    record('popup_closed_before_checkout', res)


def t_redirect(name='redirect_default'):
    scenario('stay')
    before = last_order_id()
    b = Browser(9533)
    try:
        ok, cfg = start_checkout(b, name + '@example.invalid')
        click_place_order(b)
        orders = wait_new_order(before)
        inc = orders[0]['increment_id'] if orders else None
        time.sleep(5)
        res = {'reached_payment': ok, 'config': cfg, 'orders': orders, 'main_url': evaluate(b.ws, 'location.href'),
               'mock_hits': hits_for(inc) if inc else [], 'after_redirect': reservations(inc) if inc else None}
        if inc:
            b.ws.send('Page.navigate', {'url': BASE + '/aplazo/order/operations?operation=purchase&quoteid=x'}); time.sleep(3)
            res['after_repeat_get'] = reservations(inc)
            evaluate(b.ws, 'history.back(); true'); time.sleep(3)
            res['after_back'] = reservations(inc)
    finally:
        b.quit()
    record(name, res)


def t_blocked_initial():
    scenario('stay')
    before = last_order_id()
    b = Browser(9534, block_popups=True)
    try:
        ok, cfg = start_checkout(b, 'popup_blocked@example.invalid')
        click_place_order(b, trusted=False)
        orders = wait_new_order(before)
        inc = orders[0]['increment_id'] if orders else None
        time.sleep(5)
        res = {'reached_payment': ok, 'orders': orders, 'main_url': evaluate(b.ws, 'location.href'),
               'windows_open': [t['url'][:90] for t in b.others()], 'mock_hits': hits_for(inc) if inc else []}
    finally:
        b.quit()
    record('popup_blocked_initial', res)


def t_double_click():
    scenario('stay')
    before = last_order_id()
    b = Browser(9535)
    try:
        ok, cfg = start_checkout(b, 'popup_double@example.invalid')
        click_place_order(b, clicks=2)
        orders = wait_new_order(before)
        time.sleep(5)
        res = {'reached_payment': ok, 'orders': orders, 'windows_open': [t['url'][:90] for t in b.others()]}
    finally:
        b.quit()
    record('popup_double_click', res)


def t_ajax_error():
    before = last_order_id()
    b = Browser(9536)
    try:
        ok, cfg = start_checkout(b, 'popup_ajax@example.invalid')
        b.ws.send('Fetch.enable', {'patterns': [{'urlPattern': '*/payment-information*', 'requestStage': 'Request'}]})
        click_place_order(b)
        failed, end = 0, time.time() + 15
        while time.time() < end and not failed:
            msg = b.ws.recv()
            if msg.get('method') == 'Fetch.requestPaused':
                b.ws.send('Fetch.failRequest', {'requestId': msg['params']['requestId'], 'errorReason': 'Failed'})
                failed += 1
        b.ws.send('Fetch.disable')
        time.sleep(3)
        res = {'reached_payment': ok, 'requests_failed': failed, 'orders_created': orders_after(before),
               'windows_open': [t['url'][:90] for t in b.others()], 'page': state(b)}
    finally:
        b.quit()
    record('popup_ajax_error', res)


AGREEMENT = 'BNPL-1664 local validation terms'


def agreement_setup():
    """Enables terms and conditions with one manual agreement (local only); returns what to restore."""
    prior = sql("SELECT value FROM core_config_data WHERE path='checkout/options/enable_agreements' AND scope='default' AND scope_id=0")
    subprocess.run(['bin/clinotty', 'bin/magento', 'config:set', 'checkout/options/enable_agreements', '1'], cwd=M,
                   capture_output=True, stdin=subprocess.DEVNULL)
    sql("INSERT INTO checkout_agreement (name, content, checkbox_text, is_active, is_html, mode) "
        "VALUES ('%s', 'Local test only.', 'I accept the BNPL-1664 local test terms', 1, 0, 1)" % AGREEMENT)
    aid = sql("SELECT agreement_id FROM checkout_agreement WHERE name='%s' ORDER BY agreement_id DESC LIMIT 1" % AGREEMENT)
    sql("INSERT INTO checkout_agreement_store (agreement_id, store_id) VALUES (%s, 0)" % aid)
    subprocess.run(['bin/clinotty', 'bin/magento', 'cache:clean', 'config', 'full_page'], cwd=M, capture_output=True, stdin=subprocess.DEVNULL)
    return prior, aid


def agreement_teardown(prior, aid):
    sql("DELETE FROM checkout_agreement WHERE agreement_id=%s" % aid)
    if prior:
        sql("UPDATE core_config_data SET value='%s' WHERE path='checkout/options/enable_agreements' AND scope='default' AND scope_id=0" % prior)
    else:
        sql("DELETE FROM core_config_data WHERE path='checkout/options/enable_agreements' AND scope='default' AND scope_id=0")
    subprocess.run(['bin/clinotty', 'bin/magento', 'cache:clean', 'config', 'full_page'], cwd=M, capture_output=True, stdin=subprocess.DEVNULL)


def t_validation_agreement():
    """A real client-side validation failure: Magento_CheckoutAgreements' required terms checkbox left unchecked.
    No window may open, no order or purchase may happen; after checking it the buyer continues normally."""
    prior, aid = agreement_setup()
    try:
        scenario('failure')
        before = last_order_id()
        b = Browser(9537)
        try:
            ok, cfg = start_checkout(b, 'popup_validation@example.invalid')
            box = "document.querySelector('.payment-method._active .checkout-agreements input[type=checkbox]')"
            res = {'reached_payment': ok, 'agreement_checkbox_rendered': evaluate(b.ws, '!!' + box),
                   'validator_module_loaded': evaluate(b.ws, "require.defined('Magento_CheckoutAgreements/js/model/agreement-validator')"),
                   'validator_result_before': evaluate(b.ws, "require('Magento_CheckoutAgreements/js/model/agreement-validator').validate(true)")}
            click_place_order(b)
            time.sleep(4)
            res['after_failed_click'] = {
                'orders_created': orders_after(before), 'windows_open': [t['url'][:90] for t in b.others()],
                'field_error': evaluate(b.ws, "(document.querySelector('.payment-method._active .checkout-agreements .mage-error, "
                                              ".payment-method._active .checkout-agreements div.mage-error')||{}).innerText || null"),
                'page': state(b), 'mock_hits_total': len(mock('/__hits'))}
            evaluate(b.ws, box + '.click(); true')
            time.sleep(0.5)
            click_place_order(b)
            orders = wait_new_order(before)
            inc = orders[0]['increment_id'] if orders else None
            time.sleep(4)
            res['after_correcting'] = {'orders': orders, 'mock_hits': hits_for(inc) if inc else [], 'page': state(b),
                                       'reservations': reservations(inc) if inc else None}
            if inc:
                evaluate(b.ws, "document.querySelector('.aplazo-popup-cart a').click(); true")
                wait_for(b.ws, "location.pathname.indexOf('/checkout/cart') === 0 && document.readyState === 'complete'", 30)
                time.sleep(2)
                res['cart_messages'] = evaluate(b.ws, "Array.from(document.querySelectorAll('.messages .message')).map(m => m.innerText.trim())")
                res['order_after_cart_link'] = sql("SELECT CONCAT(state,'/',status) FROM sales_order WHERE increment_id='%s'" % inc)
                res['reservations_after_cart_link'] = reservations(inc)
                res['api_calls'] = stub_calls(inc)
        finally:
            b.quit()
    finally:
        agreement_teardown(prior, aid)
    record('popup_validation_agreement', res)


def t_unavailable_page():
    """Popup purchase for an order that is not in this session: the short page, its Close button and CSP headers."""
    other = os.environ.get('E2E_FOREIGN_ORDER_ID') or sql("SELECT MIN(entity_id) FROM sales_order")
    b = Browser(9539, flags=['--disable-popup-blocking'])
    try:
        b.ws.send('Page.navigate', {'url': BASE + '/'}); wait_for(b.ws, "document.readyState==='complete'", 60)
        url = BASE + '/aplazo/order/operations?operation=purchase&quoteid=x&order=%s&mode=popup' % other
        evaluate(b.ws, "window.__w = window.open(%s, 'aplazo_test'); true" % json.dumps(url))
        time.sleep(4)
        res = {'windows': [t['url'][:120] for t in b.others()]}
        p = b.attach(b.others()[0])
        p.send('Network.enable')
        p.send('Page.reload'); time.sleep(3)
        res['text'] = evaluate(p, 'document.body.innerText')
        res['inline_handlers'] = evaluate(p, "Array.from(document.querySelectorAll('*')).filter(e => Array.from(e.attributes).some(a => a.name.indexOf('on') === 0 || a.name === 'style')).length")
        heads = [e['params']['response'] for e in p.events if e.get('method') == 'Network.responseReceived'
                 and '/aplazo/order/operations' in e['params']['response']['url']]
        h = {k.lower(): v for k, v in (heads[-1]['headers'] if heads else {}).items()}
        policy = h.get('content-security-policy') or h.get('content-security-policy-report-only') or ''
        script_src = next((part.strip() for part in policy.split(';') if part.strip().startswith('script-src')), '')
        res.update(status=heads[-1]['status'] if heads else None, enforced_header='content-security-policy' in h,
                   report_only_header='content-security-policy-report-only' in h,
                   script_src_sha256_hashes=script_src.count("'sha256-"), script_src_unsafe_inline="'unsafe-inline'" in script_src)
        r = evaluate(p, "(() => { const b = document.getElementById('aplazo-popup-close'); const r = b.getBoundingClientRect(); return {x: r.x + r.width/2, y: r.y + r.height/2}; })()")
        for t in ('mouseMoved', 'mousePressed', 'mouseReleased'):
            p.send('Input.dispatchMouseEvent', {'type': t, 'x': r['x'], 'y': r['y'], 'button': 'left', 'clickCount': 1})
        time.sleep(1.5)
        res['console'] = [e['params']['entry']['text'][:200] for e in p.events if e.get('method') == 'Log.entryAdded']
        res['window_closed_after_click'] = evaluate(b.ws, 'window.__w ? window.__w.closed : null')
    finally:
        b.quit()
    record('popup_unavailable_page', res)


TESTS = {
    'redirect_default': t_redirect,
    'popup_success': t_terminal('success'),
    'popup_failure': t_terminal('failure'),
    'popup_close': t_terminal('close'),
    'popup_filter': t_terminal('filter_then_close'),
    'popup_lost': t_lost,
    'popup_pending_then_lost': t_pending_then_lost,
    'popup_repeat_purchase': t_repeat_purchase,
    'popup_closed_before_checkout': t_closed_before_checkout,
    'popup_blocked_initial': t_blocked_initial,
    'popup_double_click': t_double_click,
    'popup_ajax_error': t_ajax_error,
    'popup_validation_agreement': t_validation_agreement,
    'popup_unavailable_page': t_unavailable_page,
}

if __name__ == '__main__':
    if not M or not os.path.exists(os.path.join(M, 'bin', 'clinotty')):
        sys.exit('Set MAGENTO_DIR to the docker-magento directory (the one with bin/clinotty).')
    unknown = [n for n in sys.argv[1:] if n not in TESTS]
    if unknown or len(sys.argv) < 2:
        sys.exit('Tests: ' + ' '.join(TESTS))
    for name in sys.argv[1:]:
        TESTS[name]()
