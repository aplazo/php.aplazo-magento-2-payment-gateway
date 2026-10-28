"""LOCAL TEST ONLY (BNPL-1664 payment-first). Drives a real local Magento checkout in a test Chrome, with the Aplazo API
replaced by stub-module/ and Aplazo's window served by mock_https.py (Chrome --host-resolver-rules, this browser only).

  MAGENTO_DIR=~/path/to/docker-magento python3 -I pf_e2e.py <test> [<test> ...]     (or: all)

Nothing pays, cancels or calls Aplazo. "Paid" means the stub answers OUTSTANDING for that cartId; the webhook is
webhook_sim.php, signed with the local store's credentials. Every result is appended to ../.out/magento-e2e/pf.jsonl.
"""
import json, os, ssl, subprocess, sys, tempfile, threading, time, shutil

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
from cdp import WS, devtools_json, evaluate, local_request, wait_for  # noqa: E402

M = os.path.expanduser(os.environ.get('MAGENTO_DIR', ''))
BASE = 'https://magento.test'
CHROME = os.environ.get('CHROME', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
SKU, PRODUCT = 'APLAZO-1664-PF', 'aplazo-1664-pf.html'
LAST_SKU, LAST_PRODUCT = 'APLAZO-1664-LAST', 'aplazo-1664-last.html'
OUT = os.path.abspath(os.path.join(HERE, '..', '.out', 'magento-e2e'))
CTX = ssl.create_default_context(); CTX.check_hostname = False; CTX.verify_mode = ssl.CERT_NONE
PORT = [9700]
WEBVIEW_UA = ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
              'Mobile/15E148 Instagram 300.0.0.0')


# ---------- Magento ----------
def run(args, stdin=None, timeout=180):
    return subprocess.run(args, cwd=M, capture_output=True, text=True, errors='replace', stdin=stdin or subprocess.DEVNULL,
                          timeout=timeout).stdout


def sql(q):
    return run(['bin/mysql', '-N', '-e', q]).strip()


def rows(q, cols):
    return [dict(zip(cols, r.split('\t'))) for r in sql(q).splitlines() if r]


def sh(cmd):
    return run(['bin/clinotty', 'sh', '-c', cmd])


def php(script, *args, timeout=180):
    with open(os.path.join(HERE, script)) as fh:
        out = run(['bin/clinotty', 'php', '--'] + [str(a) for a in args], stdin=fh, timeout=timeout)
    return json.loads(out[out.index('{'):]) if '{' in out else out


def stub(name, value):
    """var/<name> in the container: the stub's and sync points' switches. None removes it."""
    if value is None:
        sh('rm -f var/%s' % name)
    else:
        sh("mkdir -p var/localdev_aplazo_stub_loans && printf '%%s' '%s' > var/%s" % (value, name))


def loan(cart_id, status):
    stub('localdev_aplazo_stub_loans/%s' % cart_id, status)


def reset_switches():
    sh('rm -rf var/localdev_aplazo_stub_loans var/localdev_aplazo_stub_createloan var/localdev_aplazo_stub_cancel '
       'var/localdev_pf_* var/localdev_aplazo_stub_delay')


ATTEMPT_COLS = ('entity_id', 'public_id', 'quote_id', 'cart_id', 'loan_id', 'status', 'order_id', 'reject_reason',
                'loan_cancel_state', 'check_count', 'email_requested_at', 'last_error')


def attempts_after(eid):
    return rows('SELECT %s FROM aplazo_payment_attempt WHERE entity_id > %d ORDER BY entity_id' % (','.join(ATTEMPT_COLS), eid),
                ATTEMPT_COLS)


def attempt(eid):
    r = rows('SELECT %s FROM aplazo_payment_attempt WHERE entity_id = %d' % (','.join(ATTEMPT_COLS), int(eid)), ATTEMPT_COLS)
    return r[0] if r else None


def max_id(table, col='entity_id'):
    return int(sql('SELECT COALESCE(MAX(%s),0) FROM %s' % (col, table)) or 0)


def orders_after(eid):
    return rows('SELECT o.entity_id, o.increment_id, o.state, o.status, o.quote_id, '
                '(SELECT COUNT(*) FROM sales_invoice i WHERE i.order_id=o.entity_id), o.email_sent, o.send_email, '
                "JSON_UNQUOTE(JSON_EXTRACT(p.additional_information, '$.aplazo_payment_attempt')) "
                'FROM sales_order o JOIN sales_order_payment p ON p.parent_id=o.entity_id WHERE o.entity_id > %d ORDER BY o.entity_id' % eid,
                ('entity_id', 'increment_id', 'state', 'status', 'quote_id', 'invoices', 'email_sent', 'send_email', 'attempt'))


def reservations_after(rid, sku=SKU):
    return [r['qty'] + ' ' + r['event'] + ' ' + r['object'] for r in rows(
        "SELECT quantity, JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.event_type')), JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.object_increment_id')) "
        "FROM inventory_reservation WHERE sku='%s' AND reservation_id > %d ORDER BY reservation_id" % (sku, rid), ('qty', 'event', 'object'))]


def quote(qid):
    r = rows('SELECT is_active, items_count, items_qty, reserved_order_id FROM quote WHERE entity_id=%d' % int(qid),
             ('is_active', 'items_count', 'items_qty', 'reserved_order_id'))
    return r[0] if r else None


def stub_calls_since(line_no):
    lines = sh('cat var/log/localdev_aplazo_stub.log 2>/dev/null').splitlines()
    return [json.loads(l) for l in lines[line_no:] if l.startswith('{')]


def stub_log_len():
    return len(sh('cat var/log/localdev_aplazo_stub.log 2>/dev/null').splitlines())


def webhook(cart_id, loan_id, mode='merchant', status='Activo'):
    """The callback's `mixed` return is serialized by Magento as [status, message] (or a JSON string): normalized here."""
    out = php('webhook_sim.php', cart_id, loan_id, status, mode, timeout=240)
    body = out.get('body') if isinstance(out, dict) else None
    if isinstance(body, str):
        try:
            body = json.loads(body)
        except ValueError:
            pass
    if isinstance(body, list) and len(body) == 2:
        body = {'status': body[0], 'message': body[1]}
    if isinstance(out, dict):
        out['body'] = body if isinstance(body, dict) else {'raw': body}
    return out


def reconcile(*ids):
    out = run(['bin/clinotty', 'bin/magento', 'aplazo:payment-first:attempts', '--reconcile'] + ['--id=%s' % i for i in ids], timeout=240)
    return json.loads(out[out.index('['):]) if '[' in out else out


def snapshot(sku=SKU):
    with open(os.path.join(HERE, 'snapshot.php')) as fh:
        out = run(['bin/clinotty', 'php', '--', sku], stdin=fh)
    d = json.loads(out[out.index('{'):])
    return {'physical': d['physical_source_qty'], 'salable': d['salable_qty']}


def magento(*args):
    return run(['bin/clinotty', 'bin/magento'] + list(args), timeout=300)


def website_mode(mode):
    magento('config:set', '--scope=websites', '--scope-code=base', 'payment/aplazo_gateway/checkout_mode', mode)
    magento('cache:clean', 'config')


def mock_plan(name):
    local_request(8443, '/__scenario?name=' + name, method='POST', https=True, context=CTX)


def mock_hits():
    return json.loads(local_request(8443, '/__hits', https=True, context=CTX))


# ---------- browser ----------
class Browser:
    def __init__(self, block_popups=False):
        # A port nobody listens on: attaching to another Chrome would reuse its session (and its cart).
        while True:
            PORT[0] += 1
            try:
                devtools_json(PORT[0])
            except Exception:
                break
        self.port = PORT[0]
        self.profile = tempfile.mkdtemp(prefix='bnpl1664pf-')
        if block_popups:
            os.makedirs(os.path.join(self.profile, 'Default'))
            with open(os.path.join(self.profile, 'Default', 'Preferences'), 'w') as fh:
                json.dump({'profile': {'content_settings': {'exceptions': {'popups': {BASE + ',*': {'setting': 2}}}}}}, fh)
        self.proc = subprocess.Popen([CHROME, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                                      '--ignore-certificate-errors', '--host-resolver-rules=MAP checkout.aplazo.net 127.0.0.1:8443',
                                      '--remote-debugging-port=%d' % self.port, '--user-data-dir=' + self.profile,
                                      '--window-size=1280,1000', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(80):
            try:
                page = [t for t in self.targets() if t['type'] == 'page'][0]
                break
            except Exception:
                time.sleep(0.25)
        self.main_id = page['id']
        self.ws = self.attach(page)

    def targets(self):
        return devtools_json(self.port)

    def attach(self, target):
        ws = WS(target['webSocketDebuggerUrl'])
        ws.s.settimeout(200)
        ws.send('Page.enable'); ws.send('Runtime.enable')
        return ws

    def popups(self):
        return [t for t in self.targets() if t['type'] == 'page' and t['id'] != self.main_id and t['id'] not in getattr(self, 'tabs', ())]

    def new_tab(self):
        tid = self.ws.send('Target.createTarget', {'url': 'about:blank'})['targetId']
        self.tabs = getattr(self, 'tabs', ()) + (tid,)
        for _ in range(40):
            t = next((p for p in self.targets() if p['id'] == tid), None)
            if t:
                return self.attach(t)
            time.sleep(0.25)
        raise RuntimeError('tab')

    def close_popups(self):
        for t in self.popups():
            devtools_json(self.port, '/json/close/%s' % t['id'])
        time.sleep(1)

    def quit(self):
        self.proc.kill(); self.proc.wait()
        shutil.rmtree(self.profile, ignore_errors=True)


FILL = r"""
(async (email, street) => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const set = (sel, v) => { const el = document.querySelector(sel); if (!el) return false;
    el.value = v; el.dispatchEvent(new Event('change', {bubbles:true})); el.dispatchEvent(new Event('keyup', {bubbles:true})); return true; };
  for (let i = 0; i < 60 && !document.querySelector('#checkout-step-shipping [name="firstname"]'); i++) await sleep(500);
  set('#customer-email', email);
  set('[name="firstname"]', 'Demo'); set('[name="lastname"]', 'BNPL1664');
  set('[name="street[0]"]', street); set('[name="city"]', 'Ciudad de Mexico');
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
  const r = document.querySelector('input#aplazo_gateway'), box = r && r.closest('.payment-method');
  const n = box && box.querySelector('.aplazo-popup-notice'), b = box && box.querySelector('button.action.primary.checkout');
  return {path: location.pathname, notice: n ? n.innerText.trim() : null, placeOrderDisabled: b ? b.disabled : null};
})()
"""
BUTTON_JS = ("(() => { const b = document.querySelector('input#aplazo_gateway').closest('.payment-method')"
             ".querySelector('button.action.primary.checkout'); b.scrollIntoView({block: 'center'}); const r = b.getBoundingClientRect(); "
             "return {x: r.x + r.width / 2, y: r.y + r.height / 2}; })()")
POST_JS = r"""
(async (path, params) => {
  const fk = (document.cookie.match(/(?:^|; )form_key=([^;]+)/) || [])[1] || '';
  const body = new URLSearchParams(Object.assign({form_key: decodeURIComponent(fk)}, params));
  const r = await fetch(path, {method: 'POST', body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, redirect: 'manual'});
  let t = await r.text(); try { t = JSON.parse(t); } catch (e) { t = t.slice(0, 120); }
  return {http: r.status, type: r.type, body: t};
})
"""


def add_to_cart(ws, product=PRODUCT, sku=SKU):
    ws.send('Page.navigate', {'url': BASE + '/' + product})
    wait_for(ws, "document.readyState==='complete' && !!document.querySelector('#product-addtocart-button:not([disabled])')", 60)
    time.sleep(2)
    evaluate(ws, "document.querySelector('#product-addtocart-button').click(); true")
    wait_for(ws, "(window.localStorage['mage-cache-storage']||'').indexOf(%s)>-1" % json.dumps(sku), 40)
    time.sleep(2)


def to_payment(ws, email='pf-test@example.invalid', street='Calle Demo 1'):
    ws.send('Page.navigate', {'url': BASE + '/checkout/'})
    wait_for(ws, "!!document.querySelector('#checkout-step-shipping')", 90)
    return evaluate(ws, '(%s)(%s, %s)' % (FILL, json.dumps(email), json.dumps(street)), timeout=150)


def click_place_order(ws):
    evaluate(ws, BUTTON_JS); time.sleep(0.4)
    r = evaluate(ws, BUTTON_JS)
    for t in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        ws.send('Input.dispatchMouseEvent', {'type': t, 'x': r['x'], 'y': r['y'], 'button': 'left', 'clickCount': 1})


def post(ws, path, params=None, form_key=True):
    expr = POST_JS if form_key else POST_JS.replace("Object.assign({form_key: decodeURIComponent(fk)}, params)", 'params')
    return evaluate(ws, '(%s)(%s, %s)' % (expr, json.dumps(path), json.dumps(params or {})), timeout=150)


def page_state(ws):
    return evaluate(ws, STATE_JS, timeout=20)


def wait_notice(ws, *texts, timeout=30):
    """The page state once its notice contains one of the texts (English store or es_MX), else False."""
    def seen():
        st = page_state(ws) or {}
        return st if any(t in (st.get('notice') or '') for t in texts) else False
    return wait_until(seen, timeout)


def wait_attempt(after, timeout=40, status=None):
    end = time.time() + timeout
    while time.time() < end:
        a = attempts_after(after)
        if a and (status is None or a[-1]['status'] in status):
            return a[-1]
        time.sleep(0.5)
    return attempts_after(after)[-1] if attempts_after(after) else None


def wait_until(fn, timeout=40):
    end = time.time() + timeout
    while time.time() < end:
        v = fn()
        if v:
            return v
        time.sleep(0.5)
    return fn()


RESULTS = []


def record(name, ok, **data):
    os.makedirs(OUT, exist_ok=True)
    data = dict(test=name, ok=bool(ok), at=time.strftime('%Y-%m-%d %H:%M:%S %Z'), **data)
    with open(os.path.join(OUT, 'pf.jsonl'), 'a') as fh:
        fh.write(json.dumps(data, ensure_ascii=False) + '\n')
    RESULTS.append((name, bool(ok)))
    print(json.dumps(data, ensure_ascii=False, indent=1), flush=True)


class Run:
    """Baseline ids, so every check only looks at what this test created."""
    def __init__(self):
        self.a, self.o, self.r, self.log = max_id('aplazo_payment_attempt'), max_id('sales_order'), \
            max_id('inventory_reservation', 'reservation_id'), stub_log_len()
        self.r_last = self.r

    def attempts(self):
        return attempts_after(self.a)

    def orders(self):
        return orders_after(self.o)

    def res(self, sku=SKU):
        return reservations_after(self.r, sku)

    def calls(self, method=None):
        return [c for c in stub_calls_since(self.log) if method is None or c.get('method') == method]


def open_popup(b, email='pf-test@example.invalid', plan='stay', street='Calle Demo 1', add=True):
    """Cart with one unit, payment step, Place Order: returns the attempt the click created."""
    if add:
        add_to_cart(b.ws)
    ok = to_payment(b.ws, email, street)
    mock_plan(plan)
    before = max_id('aplazo_payment_attempt')
    click_place_order(b.ws)
    a = wait_attempt(before, status=('open', 'uncertain'))
    wait_until(lambda: b.popups(), 15)
    time.sleep(1.5)
    return ok, a


# ---------- setup ----------
def setup():
    print(php('order_ops.php', 'product', SKU, 1000))
    print(php('order_ops.php', 'product', LAST_SKU, 1))
    website_mode('popup')
    reset_switches()


def default_loan(status):
    """Answer for every cartId without its own file: REQUEST (not paid) unless a test says otherwise."""
    if status is None:
        sh('rm -f var/localdev_aplazo_stub_loan_status')
    else:
        sh("printf '%%s' '%s' > var/localdev_aplazo_stub_loan_status" % status)


def complete_from(ws, a):
    return post(ws, '/aplazo/popup/complete', {'attempt': a['public_id']})


def in_thread(fn, *args):
    box = {}
    t = threading.Thread(target=lambda: box.update(v=fn(*args)))
    t.start()
    return t, box


def wait_inside(event, timeout=30):
    return wait_until(lambda: sh('test -f var/localdev_pf_%s.inside && echo y' % event).strip() == 'y', timeout)


def order_items_qty(entity_id):
    return sql('SELECT SUM(qty_ordered) FROM sales_order_item WHERE order_id=%d AND parent_item_id IS NULL' % int(entity_id))


# ---------- tests ----------
def t_close_unpaid():
    """Closed without paying: no order, no reservation, cart intact; reopening reuses the same attempt and loan."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='close')
        st = wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste')
        q = quote(a['quote_id'])
        first = dict(attempts=r.attempts(), orders=r.orders(), reservations=r.res(), quote=q, page=st,
                     create_loan=[c.get('cartId') for c in r.calls('createLoan')], popups_left=len(b.popups()))
        loads = len([h for h in mock_hits() if h.get('kind') == 'page' and h['query'].get('quoteid') == 'stub-' + a['cart_id']])
        mock_plan('close')
        click_place_order(b.ws)
        time.sleep(6)
        again = dict(attempts=[x['entity_id'] + ':' + x['status'] for x in r.attempts()],
                     create_loan=[c.get('cartId') for c in r.calls('createLoan')],
                     same_url_loads=len([h for h in mock_hits() if h.get('kind') == 'page'
                                         and h['query'].get('quoteid') == 'stub-' + a['cart_id']]) - loads)
        good = (ok and len(first['attempts']) == 1 and first['attempts'][0]['status'] == 'open' and not first['orders']
                and not first['reservations'] and q['is_active'] == '1' and q['items_count'] == '1'
                and q['reserved_order_id'] != a['cart_id'] and first['create_loan'] == [a['cart_id']]
                and st and st['placeOrderDisabled'] is False and len(again['attempts']) == 1
                and again['create_loan'] == [a['cart_id']] and again['same_url_loads'] == 1 and not r.orders() and not r.res())
        record('close_unpaid_and_reuse', good, first=first, again=again)
    finally:
        b.quit()


def t_paid_message():
    """Paid, Aplazo posts success: one order with the attempt's number, invoiced, native -1 only; repeats change nothing."""
    r = Run(); b = Browser(); default_loan('OUTSTANDING')
    try:
        ok, a = open_popup(b, plan='success')
        wait_until(lambda: '/checkout/onepage/success' in (page_state(b.ws) or {}).get('path', ''), 40)
        first = dict(path=page_state(b.ws)['path'], attempt=attempt(a['entity_id']), orders=r.orders(), reservations=r.res(),
                     create_loan=len(r.calls('createLoan')))
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        repeat = dict(complete=complete_from(tab, a)['body'], webhook=webhook(a['cart_id'], 'stub-' + a['cart_id']),
                      reconcile=reconcile(a['entity_id']))
        after = dict(orders=r.orders(), reservations=r.res(), attempt=attempt(a['entity_id']))
        o = first['orders']
        good = (ok and len(o) == 1 and o[0]['increment_id'] == a['cart_id'] and o[0]['state'] == 'processing'
                and o[0]['invoices'] == '1' and o[0]['attempt'] == a['public_id'] and first['create_loan'] == 1
                and first['reservations'] == ['-1.0000 order_placed ' + a['cart_id']]
                and first['attempt']['status'] == 'completed' and first['attempt']['email_requested_at'] not in ('NULL', '')
                and repeat['complete'].get('status') == 'completed' and repeat['webhook']['body'].get('status') is True
                and after['orders'] == o and after['reservations'] == first['reservations']
                and after['attempt']['email_requested_at'] == first['attempt']['email_requested_at'])
        record('paid_success_message_once', good, first=first, repeat=repeat, after=after)
        return a
    finally:
        b.quit()


def t_paid_closed_before_message():
    """Paid and the window disappears before any message: the closed window makes the page ask; one order."""
    r = Run(); b = Browser(); default_loan('OUTSTANDING')
    try:
        ok, a = open_popup(b, plan='stay')
        b.close_popups()
        wait_until(lambda: '/checkout/onepage/success' in (page_state(b.ws) or {}).get('path', ''), 40)
        o = r.orders()
        record('paid_window_closed_before_message', ok and len(o) == 1 and o[0]['invoices'] == '1'
               and '/checkout/onepage/success' in page_state(b.ws)['path'] and r.res() == ['-1.0000 order_placed ' + a['cart_id']],
               orders=o, reservations=r.res(), path=page_state(b.ws)['path'])
    finally:
        b.quit()


def t_concurrent(first):
    """complete and webhook at the same time; `first` holds the locks inside the conversion (paused) while the other runs."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_convert_before', 'pause:8')
        if first == 'webhook':
            t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
            wait_inside('convert_before')
            during = complete_from(tab, a)['body']
            t.join(); stub('localdev_pf_convert_before', None)
            later = complete_from(tab, a)['body']
            other = box['v']['body']
        else:
            t, box = in_thread(complete_from, tab, a)
            wait_inside('convert_before')
            during = webhook(a['cart_id'], 'stub-' + a['cart_id'])['body']
            t.join(); stub('localdev_pf_convert_before', None)
            later = complete_from(tab, a)['body']
            other = box['v']['body']
        o = r.orders()
        good = (ok and len(o) == 1 and o[0]['invoices'] == '1' and r.res() == ['-1.0000 order_placed ' + a['cart_id']]
                and later.get('status') == 'completed' and attempt(a['entity_id'])['status'] == 'completed')
        record('concurrent_%s_first' % first, good, first_result=other, second_during=during, later=later, orders=o,
               reservations=r.res())
    finally:
        stub('localdev_pf_convert_before', None)
        b.quit()


def t_crash(point):
    """The PHP process dies after the order is saved (order_saved) or before approval (approve_before): resumed once."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_%s' % point, 'crash')
        crashed = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        mid = dict(attempt=attempt(a['entity_id']), orders=r.orders(), reservations=r.res())
        resumed = reconcile(a['entity_id'])
        o = r.orders()
        good = (ok and len(mid['orders']) == 1 and mid['orders'][0]['invoices'] == '0'
                and mid['attempt']['status'] == ('open' if point == 'order_saved' else 'order_created')
                and len(o) == 1 and o[0]['invoices'] == '1' and o[0]['state'] == 'processing'
                and attempt(a['entity_id'])['status'] == 'completed' and r.res() == ['-1.0000 order_placed ' + a['cart_id']])
        record('crash_at_%s_then_reconcile' % point, good, crashed=crashed, mid=mid, resumed=resumed, orders=o,
               reservations=r.res(), attempt=attempt(a['entity_id']))
    finally:
        stub('localdev_pf_%s' % point, None)
        b.quit()


def t_cart_changed(kind):
    """The cart changes in another tab while Aplazo is open, then the loan is paid: no order; rejected, reversal (kit)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        tab = b.new_tab()
        if kind == 'same_amount':
            to_payment(tab, street='Otra Calle 2')
        else:
            add_to_cart(tab)
        q = quote(a['quote_id'])
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        page = complete_from(tab, a)['body']
        rec = reconcile(a['entity_id'])
        good = (ok and not r.orders() and not r.res() and quote(a['quote_id'])['is_active'] == '1'
                and attempt(a['entity_id'])['status'] == 'rejected' and attempt(a['entity_id'])['reject_reason'] == 'cart_changed'
                and page.get('status') == 'rejected' and len(r.calls('cancelLoan')) == 1
                and attempt(a['entity_id'])['loan_cancel_state'] == 'accepted')
        record('cart_changed_%s' % kind, good, quote_after_change=q, webhook=hook, page=page, reconcile=rec,
               attempt=attempt(a['entity_id']), cancel_calls=len(r.calls('cancelLoan')),
               note='reversal simulated by the stub: blocked for the real integration until C5')
    finally:
        b.quit()


def t_mutation_during_convert():
    """A cart change sent while the quote is being converted waits for the commit and lands in a new cart."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        tab = b.new_tab(); add_to_cart(tab); tab.send('Page.navigate', {'url': BASE + '/' + PRODUCT}); time.sleep(4)
        # The tab's add above changed the cart before the payment: start again on the current cart.
        b.close_popups(); time.sleep(4)
        before = max_id('aplazo_payment_attempt'); mock_plan('stay')
        click_place_order(b.ws); a = wait_attempt(before, status=('open',)); time.sleep(2)
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_convert_before', 'pause:8')
        t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
        wait_inside('convert_before')
        pid = sql("SELECT entity_id FROM catalog_product_entity WHERE sku='%s'" % SKU)
        started = time.time()
        add = evaluate(tab, r"""(async (pid) => {
            const fk = (document.cookie.match(/(?:^|; )form_key=([^;]+)/) || [])[1] || '';
            const r = await fetch('/checkout/cart/add/', {method: 'POST', credentials: 'same-origin',
              headers: {'X-Requested-With': 'XMLHttpRequest'},
              body: new URLSearchParams({product: pid, qty: '1', form_key: decodeURIComponent(fk)})});
            const body = (await r.text()).slice(0, 300);
            const sec = await (await fetch('/customer/section/load/?sections=messages,cart&force_new_section_timestamp=true',
              {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})).json();
            return {status: r.status, body, messages: (sec.messages || {}).messages || [],
                    cart_count: (sec.cart || {}).summary_count}; })(%s)""" % json.dumps(pid), timeout=120)
        waited = round(time.time() - started, 1)
        t.join(); stub('localdev_pf_convert_before', None)
        o = r.orders()
        newer = rows('SELECT entity_id, is_active, items_qty FROM quote WHERE entity_id > %d AND customer_email IS NULL OR entity_id > %d AND is_active=1 ORDER BY entity_id DESC LIMIT 3'
                     % (int(a['quote_id']), int(a['quote_id'])), ('entity_id', 'is_active', 'items_qty'))
        # The change waited for the commit and was not written into the converted quote; what the buyer is told
        # (an error message, or the unit in a new cart) is recorded as evidence.
        good = (ok and len(o) == 1 and order_items_qty(o[0]['entity_id']) == '2.0000' and waited >= 3
                and quote(a['quote_id'])['is_active'] == '0' and quote(a['quote_id'])['items_qty'] == '2.0000'
                and (bool(add.get('messages')) or add.get('cart_count') == 1))
        record('cart_mutation_waits_for_conversion', good, add_http=add, add_waited_seconds=waited, order=o,
               order_qty=order_items_qty(o[0]['entity_id']) if o else None, converted_quote=quote(a['quote_id']), newer_quotes=newer,
               webhook=box.get('v'))
    finally:
        stub('localdev_pf_convert_before', None)
        b.quit()


def two_attempts(b):
    """A1 for one unit, closed unpaid; one more unit in the cart; A2 for two units. Returns (A1, A2)."""
    ok, a1 = open_popup(b, plan='close')
    wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste')
    add_to_cart(b.ws)
    to_payment(b.ws)
    before = max_id('aplazo_payment_attempt'); mock_plan('stay')
    click_place_order(b.ws)
    a2 = wait_attempt(before, status=('open',))
    time.sleep(2)
    return ok, a1, a2


def t_two_payments(order):
    """Two attempts on one quote, both paid. `old_first`: the old one fails on the changed cart, the new one completes.
    `new_first`: the new one completes, the old one paid late finds the quote converted. One order either way."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a1, a2 = two_attempts(b)
        superseded = attempt(a1['entity_id'])['status']
        loan(a1['cart_id'], 'OUTSTANDING'); loan(a2['cart_id'], 'OUTSTANDING')
        if order == 'old_first':
            h1 = webhook(a1['cart_id'], 'stub-' + a1['cart_id']); h2 = webhook(a2['cart_id'], 'stub-' + a2['cart_id'])
            expected_reason = 'cart_changed'
        else:
            h2 = webhook(a2['cart_id'], 'stub-' + a2['cart_id']); h1 = reconcile(a1['entity_id'])
            expected_reason = 'quote_not_active'
        rec = reconcile(a1['entity_id'])
        o = r.orders(); x1 = attempt(a1['entity_id']); x2 = attempt(a2['entity_id'])
        good = (ok and superseded == 'superseded' and len(o) == 1 and o[0]['increment_id'] == a2['cart_id']
                and x2['status'] == 'completed' and x1['status'] == 'rejected' and x1['reject_reason'] == expected_reason
                and x1['loan_cancel_state'] == 'accepted' and r.res() == ['-2.0000 order_placed ' + a2['cart_id']])
        record('two_attempts_both_paid_%s' % order, good, a1_after_second_start=superseded, first=h1, second=h2, reconcile=rec,
               orders=o, reservations=r.res(), a1=x1, a2=x2,
               note='the second payment needs a real reversal: blocked for the real integration until C5')
    finally:
        b.quit()


def t_redirect_other_tab():
    """Popup pending while another tab (a webview UA, so redirect) places a redirect order from the same quote."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        tab = b.new_tab()
        tab.send('Emulation.setUserAgentOverride', {'userAgent': WEBVIEW_UA})
        to_payment(tab)
        popups_before = len(b.popups())
        click_place_order(tab)
        redirect = wait_until(lambda: [o for o in r.orders() if o['increment_id'] != a['cart_id']], 60)
        time.sleep(4)
        redirect_res = r.res()
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        rec = reconcile(a['entity_id'])
        x = attempt(a['entity_id'])
        inc = redirect[0]['increment_id'] if redirect else None
        good = (ok and redirect and len(r.orders()) == 1 and inc != a['cart_id'] and len(b.popups()) == popups_before
                and redirect_res == ['-1.0000 order_placed ' + inc, '1.0000 aplazo_item_reserved ' + inc]
                and x['status'] == 'rejected' and x['reject_reason'] == 'quote_not_active' and x['loan_cancel_state'] == 'accepted')
        record('popup_pending_redirect_other_tab', good, redirect_order=redirect, redirect_reservations=redirect_res,
               webhook=hook, reconcile=rec, attempt=x, reservations=r.res(),
               note='redirect unchanged; the popup loan paid later needs a real reversal: blocked until C5')
    finally:
        b.quit()


def t_status_unknown():
    """The status API fails: the page shows "not confirmed yet", the attempt is not closed; it completes once paid."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'ERROR')
        b.close_popups()
        st = wait_notice(b.ws, 'could not confirm your payment yet', 'Todavía')
        time.sleep(2)
        rec1 = reconcile(a['entity_id']); x1 = attempt(a['entity_id'])
        loan(a['cart_id'], 'EMPTY'); time.sleep(2)
        rec2 = reconcile(a['entity_id']); x2 = attempt(a['entity_id'])
        loan(a['cart_id'], 'OUTSTANDING'); time.sleep(2)
        rec3 = reconcile(a['entity_id'])
        o = r.orders()
        good = (ok and st and not st['placeOrderDisabled'] and rec1 and rec1[0]['result'] == 'unknown'
                and x1['status'] == 'open' and int(x1['check_count']) >= 1 and rec2 and rec2[0]['result'] == 'unknown'
                and x2['status'] == 'open' and rec3 and rec3[0]['result'] == 'completed' and len(o) == 1)
        record('status_unknown_then_paid', good, page=st, error=rec1, empty=rec2, paid=rec3, attempt_after_errors=x2, orders=o)
    finally:
        b.quit()


def t_create_loan_fail():
    """createLoan fails (timeout-like): uncertain attempt, no redirect fallback; an early webhook still completes it."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        stub('localdev_aplazo_stub_createloan', 'fail')
        add_to_cart(b.ws); ok = to_payment(b.ws); mock_plan('stay')
        click_place_order(b.ws)
        a = wait_attempt(r.a, status=('uncertain',))
        st = wait_notice(b.ws, 'could not confirm that Aplazo opened', 'confirmar que Aplazo')
        stub('localdev_aplazo_stub_createloan', None)
        mid = dict(orders=r.orders(), popups=len(b.popups()), reservations=r.res())
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], '555001')
        o = r.orders(); x = attempt(a['entity_id'])
        good = (ok and a and a['status'] == 'uncertain' and st and not mid['orders'] and mid['popups'] == 0
                and not mid['reservations'] and len(o) == 1 and o[0]['increment_id'] == a['cart_id']
                and x['status'] == 'completed' and x['loan_id'] == '555001')
        record('create_loan_fail_then_early_webhook', good, attempt_after_start=a, page=st, mid=mid, webhook=hook, orders=o,
               attempt=x)
    finally:
        stub('localdev_aplazo_stub_createloan', None)
        b.quit()


def t_webhook_correlation():
    """Authentication kept, claims must match the attempt; the body status is never read as payment."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        res = dict(forged=webhook(a['cart_id'], 'stub-' + a['cart_id'], 'forged'),
                   other_merchant=webhook(a['cart_id'], 'stub-' + a['cart_id'], 'other-merchant'),
                   other_loan=webhook(a['cart_id'], '777777'),
                   activo_but_unpaid=webhook(a['cart_id'], 'stub-' + a['cart_id']))
        x = attempt(a['entity_id'])
        good = (ok and res['forged']['body'].get('status') is False and res['other_merchant']['body'].get('status') is False
                and res['other_loan']['body'].get('status') is False
                and res['activo_but_unpaid']['body'].get('message') == 'not_paid'
                and not r.orders() and x['status'] == 'open' and x['loan_id'] == 'stub-' + a['cart_id'])
        record('webhook_correlation', good, results=res, attempt=x, orders=r.orders())
    finally:
        b.quit()


def t_ownership():
    """Another session cannot use an attempt; POSTs without form key are refused; an empty cart cannot start."""
    r = Run(); b = Browser(); other = Browser(); default_loan('OUTSTANDING')
    try:
        ok, a = open_popup(b, plan='stay')
        other.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        res = dict(other_session=post(other.ws, '/aplazo/popup/complete', {'attempt': a['public_id']}),
                   other_session_start=post(other.ws, '/aplazo/popup/start'),
                   no_form_key_complete=post(b.ws, '/aplazo/popup/complete', {'attempt': a['public_id']}, form_key=False),
                   no_form_key_start=post(b.ws, '/aplazo/popup/start', form_key=False))
        good = (ok and res['other_session']['body'].get('status') == 'unknown_attempt'
                and res['other_session_start']['body'].get('status') == 'invalid'
                and res['no_form_key_complete']['http'] == 403 and res['no_form_key_complete']['body'].get('status') == 'invalid_form_key'
                and res['no_form_key_start']['http'] == 403 and res['no_form_key_start']['body'].get('status') == 'invalid_form_key'
                and not r.orders()
                and attempt(a['entity_id'])['status'] == 'open' and len(r.attempts()) == 1)
        record('ownership_and_form_key', good, results=res, attempts=len(r.attempts()), orders=r.orders())
    finally:
        b.quit(); other.quit()


def t_session_lost():
    """The buyer's session is gone (cookies cleared): the page learns nothing; the webhook still creates the order."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        b.ws.send('Network.enable'); b.ws.send('Network.clearBrowserCookies')
        b.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        page = post(b.ws, '/aplazo/popup/complete', {'attempt': a['public_id']})
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        o = r.orders()
        good = ok and page['body'].get('status') == 'unknown_attempt' and len(o) == 1 and o[0]['invoices'] == '1'
        record('session_lost_server_completes', good, page=page, webhook=hook, orders=o)
    finally:
        b.quit()


def t_config_change():
    """Popup turned off after the attempt started: the paid attempt is still finalized; new clicks use redirect."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        website_mode('redirect')
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        o = r.orders()
        record('config_changed_after_start', ok and len(o) == 1 and o[0]['invoices'] == '1', webhook=hook, orders=o)
    finally:
        website_mode('popup')
        b.quit()


def redirect_flow(name, browser, ua=None, mode=None, trusted=True):
    r = Run(); default_loan('REQUEST')
    try:
        if mode:
            website_mode(mode)
        if ua:
            browser.ws.send('Emulation.setUserAgentOverride', {'userAgent': ua})
        add_to_cart(browser.ws); ok = to_payment(browser.ws)
        if trusted:
            click_place_order(browser.ws)
        else:
            # No user activation: headless Chrome blocks window.open only then (as in BNPL-1664's validation).
            evaluate(browser.ws, BUTTON_JS.replace('b.scrollIntoView', 'b.click(); b.scrollIntoView'))
        o = wait_until(lambda: r.orders(), 60)
        wait_until(lambda: 'checkout.aplazo.net' in (evaluate(browser.ws, 'location.href') or ''), 40)
        href = evaluate(browser.ws, 'location.href')
        res = r.res()
        inc = o[0]['increment_id'] if o else None
        hook = webhook(inc, '888001') if inc else None
        after = dict(orders=r.orders(), reservations=r.res())
        good = (ok and len(o) == 1 and not r.attempts() and len(browser.popups()) == 0 and 'checkout.aplazo.net' in (href or '')
                and 'mode=popup' not in href and res == ['-1.0000 order_placed ' + inc, '1.0000 aplazo_item_reserved ' + inc]
                and after['orders'][0]['state'] == 'processing'
                and after['reservations'] == res + ['-1.0000 order_placed_aplazo ' + inc])
        record(name, good, href=href, orders=o, reservations=res, webhook=hook, after=after, attempts=len(r.attempts()))
    finally:
        if mode:
            website_mode('popup')
        browser.quit()


def t_redirect_regression():
    redirect_flow('redirect_mode_unchanged', Browser(), mode='redirect')


def t_blocked_popup():
    redirect_flow('popup_blocked_uses_redirect', Browser(block_popups=True), trusted=False)


def t_webview():
    redirect_flow('webview_uses_redirect', Browser(), ua=WEBVIEW_UA)


def t_last_unit():
    """The last unit is sold to someone else (redirect, paid) while the popup is open; the popup payment is rejected."""
    r = Run(); b = Browser(); other = Browser(); default_loan('REQUEST')
    try:
        # Exactly one sellable unit, whatever earlier runs left reserved.
        snap = snapshot(LAST_SKU)
        php('order_ops.php', 'stock', LAST_SKU, int(snap['physical'] - snap['salable'] + 1))
        magento('cache:clean', 'full_page')
        start_salable = snapshot(LAST_SKU)['salable']
        add_to_cart(b.ws, LAST_PRODUCT, LAST_SKU)
        ok, a = open_popup(b, plan='stay', add=False)
        other.ws.send('Emulation.setUserAgentOverride', {'userAgent': WEBVIEW_UA})
        add_to_cart(other.ws, LAST_PRODUCT, LAST_SKU); to_payment(other.ws, 'pf-other@example.invalid')
        click_place_order(other.ws)
        ro = wait_until(lambda: r.orders(), 60); time.sleep(3)
        webhook(ro[0]['increment_id'], '888002')
        salable = snapshot(LAST_SKU)
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        x = attempt(a['entity_id'])
        good = (ok and start_salable == 1 and len(r.orders()) == 1 and salable['salable'] == 0 and x['status'] == 'rejected'
                and x['reject_reason'] == 'out_of_stock' and not [o for o in r.orders() if o['increment_id'] == a['cart_id']])
        record('last_unit_sold_elsewhere', good, other_order=ro, salable_after_other=salable, webhook=hook, attempt=x,
               reservations=r.res(LAST_SKU))
    finally:
        b.quit(); other.quit()


def t_shipment():
    """A completed payment-first order is shipped: physical stock goes down once, the reservations net to zero."""
    r = Run(); b = Browser(); default_loan('OUTSTANDING')
    try:
        before = snapshot()
        ok, a = open_popup(b, plan='success')
        o = wait_until(lambda: r.orders() and r.orders()[0]['state'] == 'processing' and r.orders(), 40)
        ship = php('order_ops.php', 'ship', o[0]['entity_id'])
        after = snapshot()
        res = r.res()
        good = (ok and ship.get('ok') and after['physical'] == before['physical'] - 1 and after['salable'] == before['salable'] - 1
                and res == ['-1.0000 order_placed ' + a['cart_id'], '1.0000 shipment_created ' + a['cart_id']])
        record('shipment_and_physical_stock', good, before=before, after=after, ship=ship, reservations=res)
    finally:
        b.quit()


LOGIN_JS = r"""(async (u, p) => {
  const fk = document.querySelector('#login-form [name=form_key]').value;
  await fetch('/customer/account/loginPost/', {method: 'POST', credentials: 'same-origin',
    body: new URLSearchParams({form_key: fk, 'login[username]': u, 'login[password]': p})});
  const sec = await (await fetch('/customer/section/load/?sections=customer&force_new_section_timestamp=true',
    {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})).json();
  return !!(sec.customer && sec.customer.firstname); })(%s, %s)"""


def t_registered():
    """A logged-in customer pays; the order is the customer's, created by the webhook without any browser session."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    email, password = 'pf-registered@example.invalid', 'Kit-' + os.urandom(6).hex() + '-Aa1'
    try:
        sql("DELETE FROM customer_entity WHERE email='%s'" % email)
        c = php('order_ops.php', 'customer', email, password)
        b.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'})
        wait_for(b.ws, "!!document.querySelector('#email')", 40)
        logged_in = evaluate(b.ws, LOGIN_JS % (json.dumps(email), json.dumps(password)), timeout=60)
        ok, a = open_popup(b, email=email, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        b.ws.send('Network.enable'); b.ws.send('Network.clearBrowserCookies')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        before_reconcile = r.orders()
        rec = reconcile(a['entity_id'])
        o = r.orders()
        cust = sql('SELECT customer_id, customer_is_guest, customer_group_id FROM sales_order WHERE entity_id=%s' % o[0]['entity_id']).split('\t') if o else None
        good = (ok and logged_in and hook['body'].get('message') == 'scheduled' and not before_reconcile and len(o) == 1 and cust and cust[0] == str(c['customer_id']) and cust[1] == '0'
                and sql('SELECT customer_id FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id']) == str(c['customer_id']))
        record('registered_customer_without_session', good, logged_in=logged_in, webhook=hook, reconcile=rec, orders=o, order_customer=cust, customer_id=c['customer_id'])
    finally:
        b.quit()


def t_registered_browser():
    """A logged-in customer pays with the window present: the buyer's `complete` creates the customer's order."""
    r = Run(); b = Browser(); default_loan('OUTSTANDING')
    email, password = 'pf-registered2@example.invalid', 'Kit-' + os.urandom(6).hex() + '-Aa1'
    try:
        sql("DELETE FROM customer_entity WHERE email='%s'" % email)
        c = php('order_ops.php', 'customer', email, password)
        b.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'})
        wait_for(b.ws, "!!document.querySelector('#email')", 40)
        logged_in = evaluate(b.ws, LOGIN_JS % (json.dumps(email), json.dumps(password)), timeout=60)
        ok, a = open_popup(b, email=email, plan='success')
        wait_until(lambda: '/checkout/onepage/success' in (page_state(b.ws) or {}).get('path', ''), 40)
        o = r.orders()
        cust = sql('SELECT customer_id, customer_is_guest FROM sales_order WHERE entity_id=%s' % o[0]['entity_id']).split('\t') if o else None
        good = (ok and logged_in and len(o) == 1 and cust == [str(c['customer_id']), '0']
                and '/checkout/onepage/success' in page_state(b.ws)['path'])
        record('registered_customer_with_browser', good, orders=o, order_customer=cust, path=page_state(b.ws)['path'])
    finally:
        b.quit()


def t_double_start():
    """Two starts at the same time from two tabs of one session: a single loan and a single attempt."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        add_to_cart(b.ws); ok = to_payment(b.ws)
        evaluate(b.ws, "require(['Magento_Checkout/js/action/set-payment-information'], f => f(null, {method: 'aplazo_gateway'})); true")
        time.sleep(3)
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        stub('localdev_aplazo_stub_delay', '5')
        t1, box1 = in_thread(post, b.ws, '/aplazo/popup/start')
        time.sleep(1)
        second = post(tab, '/aplazo/popup/start')['body']
        t1.join(); stub('localdev_aplazo_stub_delay', None)
        first = box1['v']['body']
        third = post(tab, '/aplazo/popup/start')['body']
        # The second request waits for the session lock (same PHP session) and then reuses the attempt, or meets the
        # cart lock and is told to wait: either way a single loan.
        good = (ok and first.get('status') == 'open' and third.get('status') == 'open'
                and (second.get('status') == 'busy' or second.get('attempt') == first.get('attempt'))
                and third.get('attempt') == first.get('attempt') and len(r.attempts()) == 1 and len(r.calls('createLoan')) == 1
                and not r.orders() and not r.res())
        record('double_start_two_tabs', good, first=first, second=second, third=third, attempts=len(r.attempts()),
               create_loan=len(r.calls('createLoan')))
    finally:
        stub('localdev_aplazo_stub_delay', None)
        b.quit()


def t_webview_rules():
    """The webview heuristic, evaluated in the store's own module for representative user agents."""
    b = Browser()
    uas = {
        'facebook_ios': ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 [FBAN/FBIOS;FBAV/440.0]', True),
        'instagram': (WEBVIEW_UA, True),
        'twitter': ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Twitter for iPhone/10.0', True),
        'line': ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari Line/13.0.0', True),
        'android_webview': ('Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0 Mobile Safari/537.36', True),
        'generic_webview': ('Mozilla/5.0 (Linux; Android 14) SomeApp WebView/1.0', True),
        'android_chrome': ('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36', False),
        'iphone_safari': ('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', False),
        'desktop_chrome': ('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', False),
        'linux_desktop_not_line': ('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', False),
    }
    try:
        b.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(5)
        got = evaluate(b.ws, "new Promise(res => require(['Aplazo_AplazoPayment/js/model/webview'], w => res(Object.fromEntries("
                             "Object.entries(%s).map(([k, v]) => [k, w.isWebView(v[0])])))))" % json.dumps(uas), timeout=60)
        record('webview_rules', got == {k: v[1] for k, v in uas.items()}, got=got, expected={k: v[1] for k, v in uas.items()})
    finally:
        b.quit()


TESTS = {
    'close_unpaid': t_close_unpaid,
    'paid_message': t_paid_message,
    'paid_closed': t_paid_closed_before_message,
    'concurrent_webhook_first': lambda: t_concurrent('webhook'),
    'concurrent_complete_first': lambda: t_concurrent('complete'),
    'crash_order_saved': lambda: t_crash('order_saved'),
    'crash_approve_before': lambda: t_crash('approve_before'),
    'cart_changed_same_amount': lambda: t_cart_changed('same_amount'),
    'cart_changed_amount': lambda: t_cart_changed('amount'),
    'mutation_during_convert': t_mutation_during_convert,
    'two_payments_old_first': lambda: t_two_payments('old_first'),
    'two_payments_new_first': lambda: t_two_payments('new_first'),
    'redirect_other_tab': t_redirect_other_tab,
    'status_unknown': t_status_unknown,
    'create_loan_fail': t_create_loan_fail,
    'webhook_correlation': t_webhook_correlation,
    'ownership': t_ownership,
    'session_lost': t_session_lost,
    'config_change': t_config_change,
    'redirect_regression': t_redirect_regression,
    'blocked_popup': t_blocked_popup,
    'webview': t_webview,
    'last_unit': t_last_unit,
    'shipment': t_shipment,
    'double_start': t_double_start,
    'registered': t_registered,
    'registered_browser': t_registered_browser,
    'webview_rules': t_webview_rules,
}

if __name__ == '__main__':
    if not M or not os.path.isfile(os.path.join(M, 'bin', 'clinotty')):
        sys.exit('MAGENTO_DIR must point at the docker-magento directory')
    names = sys.argv[1:] or ['all']
    names = list(TESTS) if names == ['all'] else names
    if names and names[0] == 'setup':
        setup(); names = names[1:]
    for n in names:
        try:
            TESTS[n]()
        except Exception as e:  # a crashed test is recorded, the run goes on
            import traceback
            record(n, False, error='%s: %s' % (type(e).__name__, e), where=traceback.format_exc().splitlines()[-3:])
        finally:
            reset_switches()
    print('\nSUMMARY ' + ' '.join('%s=%s' % (n, 'PASS' if ok else 'FAIL') for n, ok in RESULTS))
