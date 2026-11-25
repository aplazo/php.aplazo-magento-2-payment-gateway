"""LOCAL TEST ONLY (BNPL-1664 payment-first). Drives a real local Magento checkout in a test Chrome, with the Aplazo API
replaced by stub-module/ and Aplazo's window served by mock_https.py (Chrome --host-resolver-rules, this browser only).

  MAGENTO_DIR=~/path/to/docker-magento python3 -I pf_e2e.py <test> [<test> ...]     (or: all)

Nothing pays, cancels or calls Aplazo. "Paid" means the stub answers OUTSTANDING for that cartId; the webhook is
webhook_sim.php, signed with the local store's credentials. Every result is appended to ../.out/magento-e2e/pf.jsonl.
Optional: RABBITMQ_CONTAINER (name of the local RabbitMQ container) to count queued messages in the coupon/link cases.
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
       'var/localdev_pf_* var/localdev_aplazo_stub_delay var/localdev_aplazo_stub_log_delay')


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
    def __init__(self, block_popups=False, profile=None):
        # A port nobody listens on: attaching to another Chrome would reuse its session (and its cart).
        while True:
            PORT[0] += 1
            try:
                devtools_json(PORT[0])
            except Exception:
                break
        self.port = PORT[0]
        # profile: a directory kept between launches (scenario A, close Chrome and reopen it with the same profile).
        self.profile = profile or tempfile.mkdtemp(prefix='bnpl1664pf-')
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

    def quit(self, keep=False):
        if self.proc.poll() is None:
            self.proc.kill(); self.proc.wait()
        if not keep:
            shutil.rmtree(self.profile, ignore_errors=True)

    def stop(self, graceful):
        """Closes the whole Chrome and keeps the profile. graceful: DevTools Browser.close, the same path as quitting
        Chrome (it writes cookies and storage first; a SIGTERM to headless Chrome does not, it loses what changed in the
        last ~30 s); else SIGKILL, an abrupt crash (whatever Chrome had not written yet is lost)."""
        if graceful:
            WS(json.loads(local_request(self.port, '/json/version'))['webSocketDebuggerUrl']).send('Browser.close')
        else:
            self.proc.kill()
        try:
            self.proc.wait(20)
        except subprocess.TimeoutExpired:
            self.proc.kill(); self.proc.wait()


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
                # The attempt is linked to its order in the conversion transaction, so a crash after the commit
                # (order_saved) or before approval leaves it order_created with its order_id.
                and mid['attempt']['status'] == 'order_created' and mid['attempt']['order_id'] == mid['orders'][0]['entity_id']
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
                and attempt(a['entity_id'])['loan_cancel_state'] == 'request_answered')
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
        # The tab's add above changed the cart before the payment: start again on the current cart. Since the BNPL-1664
        # review a closed window runs its whole close window behind the layer (~25 s) and the checkout comes back only
        # on the final "not paid": wait for it before clicking again (a click on the layer starts nothing).
        b.close_popups()
        if not wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste', timeout=60):
            raise RuntimeError('the checkout did not come back after closing the first window')
        time.sleep(1)
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
                and x1['loan_cancel_state'] == 'request_answered' and r.res() == ['-2.0000 order_placed ' + a2['cart_id']])
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
                and x['status'] == 'rejected' and x['reject_reason'] == 'quote_not_active' and x['loan_cancel_state'] == 'request_answered')
        record('popup_pending_redirect_other_tab', good, redirect_order=redirect, redirect_reservations=redirect_res,
               webhook=hook, reconcile=rec, attempt=x, reservations=r.res(),
               note='redirect unchanged; the popup loan paid later needs a real reversal: blocked until C5')
    finally:
        b.quit()


OVERLAY_STATE_JS = r"""(() => { const o = document.querySelector('.aplazo-popup-overlay');
  return o ? {state: o.getAttribute('data-state'), message: o.querySelector('.aplazo-popup-overlay__message').innerText.trim(),
    inert: Array.from(document.body.children).filter(el => el !== o).every(el => el.hasAttribute('inert'))} : null; })()"""


def t_status_unknown():
    """The status API fails: the page says "not confirmed yet" and keeps the checkout BLOCKED (since the BNPL-1664
    review an undecided close never gives it back), the attempt is not closed; it completes once paid."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'ERROR')
        b.close_popups()
        st = wait_until(lambda: (lambda o: o if o and o.get('state') == 'message' and 'could not confirm your payment yet' in (o.get('message') or '') else False)(
            evaluate(b.ws, OVERLAY_STATE_JS, timeout=20)), 60)
        time.sleep(2)
        rec1 = reconcile(a['entity_id']); x1 = attempt(a['entity_id'])
        loan(a['cart_id'], 'EMPTY'); time.sleep(2)
        rec2 = reconcile(a['entity_id']); x2 = attempt(a['entity_id'])
        loan(a['cart_id'], 'OUTSTANDING'); time.sleep(2)
        rec3 = reconcile(a['entity_id'])
        o = r.orders()
        good = (ok and st and st['inert'] and rec1 and rec1[0]['result'] == 'unknown'
                and x1['status'] == 'open' and int(x1['check_count']) >= 1 and rec2 and rec2[0]['result'] == 'unknown'
                and x2['status'] == 'open' and rec3 and rec3[0]['result'] == 'completed' and len(o) == 1)
        record('status_unknown_then_paid', good, page=st, error=rec1, empty=rec2, paid=rec3, attempt_after_errors=x2, orders=o)
    finally:
        b.quit()


def t_create_loan_fail():
    """createLoan fails (timeout-like): uncertain attempt without loan id, no redirect fallback. An early signed webhook
    names a loan, but nothing ties that loan to this attempt (R-10): it is held for review, not converted. An operator
    who confirmed the loan with Aplazo attributes it (--attribute-loan); only then the check confirms it, against that
    loan only, among others listed for the same cartId."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        stub('localdev_aplazo_stub_createloan', 'fail')
        add_to_cart(b.ws); ok = to_payment(b.ws); mock_plan('stay')
        click_place_order(b.ws)
        a = wait_attempt(r.a, status=('uncertain',))
        st = wait_notice(b.ws, 'could not confirm whether Aplazo received', 'confirmar si Aplazo')
        stub('localdev_aplazo_stub_createloan', None)
        mid = dict(orders=r.orders(), popups=len(b.popups()), reservations=r.res())
        lid = '55' + a['cart_id'].lstrip('0')   # unique per run: the operator refuses a loan id another attempt holds
        loan(a['cart_id'], 'LIST:%s=OUTSTANDING,26701=OUTSTANDING' % lid)
        hook = webhook(a['cart_id'], lid)
        held = attempt(a['entity_id'])
        orders_after_hook = r.orders()   # before the operator's attribution: the held webhook created nothing
        status_calls_after_hook = len([c for c in r.calls('getLoanStatus') if c.get('cartId') == a['cart_id']])
        attention = attempts_cli('--attention', '--older-than=0')
        dry = attempts_cli('--attribute-loan=%s' % a['entity_id'], '--loan-id=%s' % lid)
        dry_loan = attempt(a['entity_id'])['loan_id']
        done = attempts_cli('--attribute-loan=%s' % a['entity_id'], '--loan-id=%s' % lid, '--confirm')
        again = attempts_cli('--attribute-loan=%s' % a['entity_id'], '--loan-id=26701', '--confirm')
        rec = reconcile(a['entity_id'])
        o = r.orders(); x = attempt(a['entity_id'])
        good = (ok and a and a['status'] == 'uncertain' and a['loan_id'] == 'NULL' and st and not mid['orders']
                and mid['popups'] == 0 and not mid['reservations']
                and hook['body'].get('message') == 'held_for_review' and held['status'] == 'uncertain'
                and held['loan_id'] == 'NULL' and held['last_error'] == 'webhook_loan_unattributed:' + lid
                and status_calls_after_hook == 0 and not orders_after_hook
                and int(a['entity_id']) in attention[1].get('loan_unattributed', [])
                and dry[0] == 0 and dry_loan == 'NULL' and done[0] == 0 and again[0] == 1
                and len(o) == 1 and o[0]['increment_id'] == a['cart_id'] and o[0]['invoices'] == '1'
                and x['status'] == 'completed' and x['loan_id'] == lid)
        record('create_loan_fail_webhook_held_then_operator_attribution', good, attempt_after_start=a, page=st, mid=mid,
               webhook=hook, held=held, orders_after_hook=orders_after_hook, status_calls_after_hook=status_calls_after_hook,
               attention_loan_unattributed=attention[1].get('loan_unattributed') if isinstance(attention[1], dict) else attention,
               dry_run=dry, dry_run_loan=dry_loan, attributed=done, attribute_again=again, reconcile=rec, orders=o, attempt=x)
    finally:
        stub('localdev_aplazo_stub_createloan', None)
        b.quit()


def uncertain_without_loan(r, b):
    """A start whose createLoan fails: an uncertain attempt without loan id, owned by this browser session."""
    stub('localdev_aplazo_stub_createloan', 'fail')
    try:
        add_to_cart(b.ws); ok = to_payment(b.ws); mock_plan('stay')
        click_place_order(b.ws)
        a = wait_attempt(r.a, status=('uncertain',))
    finally:
        stub('localdev_aplazo_stub_createloan', None)
    return ok, a


def t_r10(mode):
    """R-10: the status API lists every loan of a cartId (STG: loans of other stores and tests under the same merchant).
    Only the loan persisted on the attempt, matched exactly, decides; the webhook never attributes a loan. mode:
    own_paid_among_others | own_unpaid_other_paid | no_loan_foreign_paid | webhook_duplicate | webhook_conflict |
    webhook_ambiguous. The browser's complete and the reconciliation are run as the buyer's page and the cron would."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        data = {}
        if mode in ('no_loan_foreign_paid', 'webhook_ambiguous'):
            ok, a = uncertain_without_loan(r, b)
        else:
            ok, a = open_popup(b, plan='stay')
        own = 'stub-' + a['cart_id']
        if mode == 'own_paid_among_others':
            loan(a['cart_id'], 'LIST:26701=OUTSTANDING,own=OUTSTANDING,235354=REQUEST,none=OUTSTANDING')
            data['complete'] = complete_from(b.ws, a)
            data['complete_again'] = complete_from(b.ws, a)
            o = r.orders(); x = attempt(a['entity_id'])
            good = (data['complete']['body'].get('status') == 'completed' and data['complete_again']['body'].get('status') == 'completed'
                    and len(o) == 1 and o[0]['invoices'] == '1' and o[0]['increment_id'] == a['cart_id']
                    and x['status'] == 'completed' and x['loan_id'] == own)
        elif mode == 'own_unpaid_other_paid':
            loan(a['cart_id'], 'LIST:26701=OUTSTANDING,own=REQUEST')
            data['complete'] = complete_from(b.ws, a)
            data['webhook_own'] = webhook(a['cart_id'], own)
            data['reconcile'] = reconcile(a['entity_id'])
            x = attempt(a['entity_id'])
            good = (data['complete']['body'].get('status') == 'not_paid' and data['webhook_own']['body'].get('message') == 'not_paid'
                    and not r.orders() and x['status'] == 'open' and x['loan_id'] == own)
        elif mode == 'no_loan_foreign_paid':
            loan(a['cart_id'], 'LIST:26701=OUTSTANDING')
            data['complete'] = complete_from(b.ws, a)
            data['reconcile'] = reconcile(a['entity_id'])
            x = attempt(a['entity_id'])
            data['status_calls'] = len([c for c in r.calls('getLoanStatus') if c.get('cartId') == a['cart_id']])
            good = (a['loan_id'] == 'NULL' and data['complete']['body'].get('status') == 'pending'
                    and not r.orders() and not r.res() and x['status'] == 'uncertain' and x['loan_id'] == 'NULL'
                    and data['status_calls'] == 0)
        elif mode == 'webhook_duplicate':
            loan(a['cart_id'], 'LIST:26701=OUTSTANDING,own=OUTSTANDING')
            data['first'] = webhook(a['cart_id'], own)
            data['second'] = webhook(a['cart_id'], own)
            o = r.orders(); x = attempt(a['entity_id'])
            good = (data['first']['body'].get('message') == 'completed' and data['second']['body'].get('message') == 'completed'
                    and len(o) == 1 and o[0]['invoices'] == '1' and x['status'] == 'completed' and x['loan_id'] == own)
        elif mode == 'webhook_conflict':
            loan(a['cart_id'], 'LIST:26701=OUTSTANDING,own=REQUEST')
            v0 = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
            data['conflict'] = webhook(a['cart_id'], '26701')
            v1 = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
            x = attempt(a['entity_id'])
            data['versions'] = [v0, v1]
            good = (data['conflict']['body'].get('status') is False and v0 == v1 and not r.orders()
                    and x['status'] == 'open' and x['loan_id'] == own)
        else:  # webhook_ambiguous
            loan(a['cart_id'], 'LIST:26701=OUTSTANDING')
            data['first'] = webhook(a['cart_id'], '26701')
            v1 = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
            data['second'] = webhook(a['cart_id'], '26701')
            v2 = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
            x = attempt(a['entity_id'])
            data['versions'] = [v1, v2]
            good = (data['first']['body'].get('message') == 'held_for_review' and data['second']['body'].get('message') == 'held_for_review'
                    and v1 == v2 and not r.orders() and x['status'] == 'uncertain' and x['loan_id'] == 'NULL'
                    and x['last_error'] == 'webhook_loan_unattributed:26701')
        good = bool(ok and good)
        record('r10_%s' % mode, good, attempt=attempt(a['entity_id']), orders=r.orders(), reservations=r.res(), **data)
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


FETCH_JS = r"""(async (path, form, json) => {
  const fk = decodeURIComponent((document.cookie.match(/(?:^|; )form_key=([^;]+)/) || [])[1] || '');
  const opts = {method: json ? (json.__method || 'POST') : 'POST', credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}};
  if (json) { delete json.__method; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(json); }
  else { opts.body = new URLSearchParams(Object.assign({form_key: fk}, form || {})); }
  const r = await fetch(path, opts); const t = (await r.text()).slice(0, 300);
  return {http: r.status, body: t}; })(%s, %s, %s)"""


def address_json(qid, street):
    a = rows("SELECT firstname, lastname, city, region_id, postcode, country_id, telephone, email FROM quote_address "
             "WHERE quote_id=%d AND address_type='shipping'" % int(qid),
             ('firstname', 'lastname', 'city', 'region_id', 'postcode', 'country_id', 'telephone', 'email'))[0]
    return {'firstname': a['firstname'], 'lastname': a['lastname'], 'street': [street], 'city': a['city'],
            'region_id': int(a['region_id']), 'postcode': a['postcode'], 'countryId': a['country_id'],
            'telephone': a['telephone'], 'email': 'pf-test@example.invalid'}


def h1_request(route, qid):
    """(path, form, json) of an ordinary cart write for quote qid."""
    mask = sql('SELECT masked_id FROM quote_id_mask WHERE quote_id=%d' % int(qid))
    item = sql('SELECT item_id FROM quote_item WHERE quote_id=%d AND parent_item_id IS NULL LIMIT 1' % int(qid))
    pid = sql("SELECT entity_id FROM catalog_product_entity WHERE sku='%s'" % SKU)
    method = (sql("SELECT shipping_method FROM quote_address WHERE quote_id=%d AND address_type='shipping'" % int(qid)) or 'flatrate_flatrate').split('_', 1)
    if route == 'add_to_cart':
        return '/checkout/cart/add/', {'product': pid, 'qty': '1'}, None
    if route == 'update_qty':
        return '/checkout/sidebar/updateItemQty/', {'item_id': item, 'item_qty': '3'}, None
    if route == 'shipping_information':
        addr = address_json(qid, 'Calle Tardia 9')
        return '/rest/default/V1/guest-carts/%s/shipping-information' % mask, None, {'addressInformation': {
            'shipping_address': addr, 'billing_address': addr, 'shipping_carrier_code': method[0], 'shipping_method_code': method[1]}}
    if route == 'payment_information':
        return '/rest/default/V1/guest-carts/%s/set-payment-information' % mask, None, {
            'email': 'pf-test@example.invalid', 'paymentMethod': {'method': 'aplazo_gateway'},
            'billingAddress': address_json(qid, 'Calle Tardia 9')}
    raise ValueError(route)


def t_h1(route, point='load'):
    """H-1: a cart write that loaded the quote before the conversion and saves it after the commit."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/checkout/cart/'}); time.sleep(4)
        path, form, body = h1_request(route, qid)
        before = dict(quote=quote(qid), street=sql("SELECT street FROM quote_address WHERE quote_id=%d AND address_type='shipping'" % qid))
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_quote_save_pause', '%s:%d:12' % (point, qid))
        t, box = in_thread(evaluate, tab, FETCH_JS % (json.dumps(path), json.dumps(form), json.dumps(body)), 120)
        held = wait_until(lambda: sh('cat var/localdev_pf_quote_save_pause.inside 2>/dev/null').strip(), 30)
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        committed = dict(quote=quote(qid), orders=r.orders())
        t.join()
        stub('localdev_pf_quote_save_pause', None)
        after = dict(quote=quote(qid), items=rows('SELECT sku, qty FROM quote_item WHERE quote_id=%d AND parent_item_id IS NULL' % qid, ('sku', 'qty')),
                     street=sql("SELECT street FROM quote_address WHERE quote_id=%d AND address_type='shipping'" % qid),
                     orders=r.orders(), reservations=r.res())
        # Can the same cart be bought again? A new start from the buyer's session, then a paid loan.
        again = post(b.ws, '/aplazo/popup/start')['body']
        second = None
        if again.get('status') == 'open':
            na = attempts_after(r.a)[-1]
            loan(na['cart_id'], 'OUTSTANDING')
            second = dict(attempt=na['cart_id'], webhook=webhook(na['cart_id'], 'stub-' + na['cart_id']), orders=r.orders(), reservations=r.res())
        # Integrity is broken when the quote ends active although it already became an order, or when one quote ends up
        # with two orders. A stale write that wins before the conversion (the conversion then rejects the changed cart)
        # and a new purchase of that changed cart are legitimate.
        orders_of_quote = [o for o in r.orders() if o['quote_id'] == str(qid)]
        reactivated = after['quote']['is_active'] == '1' and bool(after['orders'])
        double = len(orders_of_quote) > 1
        outcome = ('stale_write_refused' if after['orders'] and after['quote']['is_active'] == '0' else
                   'stale_write_first_conversion_rejected' if not after['orders'] else 'converted_quote_reactivated')
        record('h1_stale_write_%s_%s' % (route, point), bool(held) and not reactivated and not double, route=route, point=point,
               outcome=outcome, orders_of_quote=[o['increment_id'] for o in orders_of_quote], held_request=held,
               write_response=box.get('v'), webhook=hook, before=before, at_commit=committed, after=after,
               start_again=again, second_purchase=second)
    finally:
        stub('localdev_pf_quote_save_pause', None)
        b.quit()


def t_h1_inflight(variant):
    """H-1, Mike's point 6: why step (b) of KeepConvertedQuoteClosed asks for ANY attempt of the quote.
    Ordering (two kit pauses):
      1. a cart write (sidebar updateItemQty) passes Magento's own checks and stops inside its quote save, before the
         module's guard (QuoteSavePause point "model", S1 = 12 s);
      2. the webhook starts the conversion: it locks the quote rows (FOR UPDATE) and stops at convert_before, before
         submit and before the attempt is linked to its order (SyncPoint, 25 s);
      3. the write resumes and runs the guard while the conversion is still open.
    variant 'current': step (b) finds the attempt, the guard's FOR UPDATE waits for the commit, reads is_active=0 and
    refuses the save. variant 'order_only' (stub H1VariantProbe: Mike's "attempt with order", plain read): the order
    is not committed yet, so step (b) answers no, the guard returns, the write waits on the row lock and then writes
    is_active=1 over the converted quote.
    ok = the predicted outcome: protected for 'current', the race reproduced for 'order_only'."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/checkout/cart/'}); time.sleep(4)
        path, form, body = h1_request('update_qty', qid)
        stub('localdev_pf_h1_variant', 'order_only' if variant == 'order_only' else None)
        line = stub_log_len()
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_quote_save_pause', 'model:%d:12' % qid)
        tw, write = in_thread(evaluate, tab, FETCH_JS % (json.dumps(path), json.dumps(form), json.dumps(body)), 120)
        write_held = wait_until(lambda: sh('cat var/localdev_pf_quote_save_pause.inside 2>/dev/null').strip(), 30)
        stub('localdev_pf_convert_before', 'pause:25')
        th, hook = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
        conversion_held = wait_until(lambda: sh('ls var/localdev_pf_convert_before.inside 2>/dev/null').strip(), 30)
        # The write must resume while the conversion is still paused.
        write_resumed_during_conversion = bool(wait_until(
            lambda: not sh('ls var/localdev_pf_quote_save_pause.inside 2>/dev/null').strip()
            and sh('ls var/localdev_pf_convert_before.inside 2>/dev/null').strip(), 30))
        tw.join(); th.join()
        stub('localdev_pf_convert_before', None); stub('localdev_pf_h1_variant', None)
        orders_of_quote = [o for o in r.orders() if o['quote_id'] == str(qid)]
        after = dict(quote=quote(qid), items=rows('SELECT sku, qty FROM quote_item WHERE quote_id=%d AND parent_item_id IS NULL' % qid, ('sku', 'qty')),
                     orders=[o['increment_id'] for o in orders_of_quote], attempt=attempt(a['entity_id']))
        reactivated = after['quote']['is_active'] == '1' and bool(orders_of_quote)
        step_b = [c for c in stub_calls_since(line) if c.get('method') == 'h1_step_b' and c.get('quote_id') == qid]
        ordered = bool(write_held) and bool(conversion_held) and write_resumed_during_conversion
        expected = reactivated if variant == 'order_only' else (not reactivated and bool(orders_of_quote))
        record('h1_inflight_%s' % variant, ordered and expected, variant=variant, write_held=bool(write_held),
               conversion_held=bool(conversion_held), write_resumed_during_conversion=write_resumed_during_conversion,
               reactivated=reactivated, step_b=step_b, write_response=write.get('v'), webhook=hook.get('v'), after=after)
    finally:
        stub('localdev_pf_quote_save_pause', None)
        stub('localdev_pf_convert_before', None)
        stub('localdev_pf_h1_variant', None)
        b.quit()


def config_row(path, scope, scope_id):
    return sql("SELECT value FROM core_config_data WHERE path='%s' AND scope='%s' AND scope_id=%d" % (path, scope, scope_id))


def set_config_sql(path, scope, scope_id, value_sql):
    """value_sql is an SQL expression (so a merchant id is derived in the DB and never printed); None deletes the row."""
    if value_sql is None:
        sql("DELETE FROM core_config_data WHERE path='%s' AND scope='%s' AND scope_id=%d" % (path, scope, scope_id))
    else:
        sql("INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('%s', %d, '%s', %s) "
            "ON DUPLICATE KEY UPDATE value=VALUES(value)" % (scope, scope_id, path, value_sql))
    magento('cache:clean', 'config')


MERCHANT_SQL = "(SELECT v FROM (SELECT value v FROM core_config_data WHERE path='payment/aplazo_gateway/merchantid' AND scope='default' AND scope_id=0) t)"
OTHER_MERCHANT_SQL = "CONCAT('1', %s)" % MERCHANT_SQL


def get_calls(r, cart_id):
    return [c for c in r.calls('getLoanStatus') if c.get('cartId') == cart_id]


def t_h4(change):
    """H-4: the store's sandbox flag or merchant changes after the start: Aplazo is not asked; restoring it completes."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        stored = sql('SELECT environment, merchant_id = %s FROM aplazo_payment_attempt WHERE entity_id=%s' % (MERCHANT_SQL, a['entity_id'])).split('\t')
        loan(a['cart_id'], 'OUTSTANDING')
        calls_before = len(get_calls(r, a['cart_id']))
        if change == 'sandbox':
            prev = config_row('payment/aplazo_gateway/sanbox_mode', 'default', 0)
            set_config_sql('payment/aplazo_gateway/sanbox_mode', 'default', 0, "'0'")
        else:
            set_config_sql('payment/aplazo_gateway/merchantid', 'websites', 1, OTHER_MERCHANT_SQL)
        try:
            hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
            rec = reconcile(a['entity_id'])
            during = dict(attempt=attempt(a['entity_id']), orders=r.orders(), status_calls=len(get_calls(r, a['cart_id'])) - calls_before)
        finally:
            if change == 'sandbox':
                set_config_sql('payment/aplazo_gateway/sanbox_mode', 'default', 0, "'%s'" % prev)
            else:
                set_config_sql('payment/aplazo_gateway/merchantid', 'websites', 1, None)
        time.sleep(2)
        rec2 = reconcile(a['entity_id'])
        o = r.orders()
        good = (ok and stored == ['stg', '1'] and during['status_calls'] == 0 and not during['orders']
                and during['attempt']['status'] == 'open'
                and during['attempt']['last_error'] == 'context_changed:' + ('environment' if change == 'sandbox' else 'merchant')
                and rec2 and rec2[0]['result'] == 'completed' and len(o) == 1)
        record('h4_%s_changed_after_start' % change, good, stored_environment_and_merchant_match=stored, webhook=hook,
               reconcile_during=rec, during=during, reconcile_after_restore=rec2, orders=o)
    finally:
        b.quit()


def foreign_order(store_id, increment):
    return php('store_ops.php', 'order', store_id, increment, SKU)


def t_h9(merchants):
    """H-9: another store's order has the attempt's cartId. distinct merchants: the signed merchantId routes each webhook
    to its own side; same merchant: refused as ambiguous, neither side touched."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    store2 = php('store_ops.php', 'store')['store_id']
    try:
        set_config_sql('payment/aplazo_gateway/merchantid', 'websites', 2, OTHER_MERCHANT_SQL if merchants == 'distinct' else MERCHANT_SQL)
        ok, a = open_popup(b, plan='stay')
        f = foreign_order(store2, a['cart_id'])
        both = rows("SELECT entity_id, store_id, state FROM sales_order WHERE increment_id='%s'" % a['cart_id'], ('entity_id', 'store_id', 'state'))
        loan(a['cart_id'], 'OUTSTANDING')
        res = {}
        if merchants == 'distinct':
            # The other store's webhook first (the attempt has no order yet): unchanged redirect path, its own order.
            res['other_merchant'] = webhook(a['cart_id'], '888777', 'other-merchant')
            foreign_after = sql('SELECT state FROM sales_order WHERE entity_id=%d' % f['entity_id'])
            res['attempt_merchant'] = webhook(a['cart_id'], 'stub-' + a['cart_id'])
            x = attempt(a['entity_id'])
            own = rows("SELECT entity_id, store_id, state FROM sales_order WHERE increment_id='%s' ORDER BY entity_id" % a['cart_id'],
                       ('entity_id', 'store_id', 'state'))
            # Again for the other merchant: the attempt still has no order of its own, so the redirect path is safe.
            res['other_merchant_again'] = webhook(a['cart_id'], '888777', 'other-merchant')
            # Paid, but the cartId is another store's order number: Magento would not give the order that number, so
            # the attempt is left for an operator instead of creating an order with another number.
            good = (ok and f.get('ok') and len(both) == 1 and res['other_merchant']['body'].get('status') is True
                    and foreign_after == 'processing' and x['status'] == 'needs_review'
                    and res['attempt_merchant']['body'] == {'status': False, 'message': 'needs_review'}
                    and x['last_error'] == 'cart_id_used_by_other_order' and [o['store_id'] for o in own] == [str(store2)]
                    and res['other_merchant_again']['body'].get('status') is True)
            record('h9_cross_store_cartid_distinct_merchants', good, foreign_order=f, results=res, foreign_after_its_webhook=foreign_after,
                   attempt=x, orders_with_cart_id=own,
                   note='foreign order: check/money-order, increment forced to the attempt cartId; its webhook used the unchanged redirect path')
        else:
            res['same_merchant'] = webhook(a['cart_id'], 'stub-' + a['cart_id'])
            x = attempt(a['entity_id'])
            foreign_after = sql('SELECT state FROM sales_order WHERE entity_id=%d' % f['entity_id'])
            good = (ok and f.get('ok') and len(both) == 1 and res['same_merchant']['body'] == {'status': False, 'message': 'Ambiguous cartId'}
                    and x['status'] == 'open' and foreign_after == 'new' and len([o for o in r.orders() if o['increment_id'] == a['cart_id']]) == 1)
            record('h9_cross_store_cartid_same_merchant', good, foreign_order=f, same_increment=both, results=res, attempt=x,
                   foreign_after=foreign_after)
    finally:
        set_config_sql('payment/aplazo_gateway/merchantid', 'websites', 2, None)
        b.quit()


def attempts_cli(*args):
    """bin/magento aplazo:payment-first:attempts <args>: (exit code, parsed JSON or text)."""
    proc = subprocess.run(['bin/clinotty', 'bin/magento', 'aplazo:payment-first:attempts'] + list(args), cwd=M,
                          capture_output=True, text=True, errors='replace', stdin=subprocess.DEVNULL, timeout=240)
    out = proc.stdout
    start = min([i for i in (out.find('{'), out.find('[')) if i >= 0] or [-1])
    try:
        body = json.loads(out[start:out.rfind('Nothing written') if 'Nothing written' in out else None]) if start >= 0 else out.strip()
    except ValueError:
        body = out.strip()[:400]
    return proc.returncode, body


def cron_job():
    with open(os.path.join(HERE, 'cron_job.php')) as fh:
        out = run(['bin/clinotty', 'php'], stdin=fh, timeout=240)
    return json.loads(out[out.index('{'):]) if '{' in out else {'raw': out.strip()[:200]}


IN_USE = 'payment/aplazo_gateway/payment_first_in_use'


def t_switch_off(via):
    """Review point 2 / Mike's point 5: turning popup off stops NEW attempts, not the reconciliation of existing ones.
    The store starts without the payment_first_in_use flag; the start writes it before the attempt row. Then popup is
    turned off: a new click from the same session gets use_redirect and creates no attempt, and the existing attempt,
    paid afterwards, still becomes its order through the webhook or through the cron entry point
    (Cron\\ReconcilePaymentAttempts::execute, not the ungated CLI)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    flag_before = config_row(IN_USE, 'stores', 1)
    set_config_sql(IN_USE, 'stores', 1, None)
    try:
        ok, a = open_popup(b, plan='stay')
        flag_after_start = config_row(IN_USE, 'stores', 1)
        website_mode('redirect')
        again = post(b.ws, '/aplazo/popup/start')['body']
        new_attempts = attempts_after(int(a['entity_id']))
        loan(a['cart_id'], 'OUTSTANDING')
        if via == 'webhook':
            result = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        else:
            sh("printf '%s\\n' > var/localdev_pf_cron_allowlist" % a['entity_id'])
            try:
                result = cron_job()
            finally:
                sh('rm -f var/localdev_pf_cron_allowlist')
        att = attempt(a['entity_id'])
        orders = [o['increment_id'] for o in r.orders()]
        passed = (flag_after_start == '1' and again.get('status') == 'use_redirect' and not new_attempts
                  and att['status'] == 'completed' and a['cart_id'] in orders)
        record('switch_off_%s' % via, passed, via=via, flag_before_test=flag_before, flag_after_start=flag_after_start,
               start_with_popup_off=again, new_attempts_after_off=len(new_attempts), result=result, attempt=att, orders=orders)
    finally:
        website_mode('popup')
        b.quit()


def rejected_attempt(b):
    """A paid attempt whose cart changed in another tab: rejected/cart_changed, reversal pending."""
    ok, a = open_popup(b, plan='stay')
    tab = b.new_tab(); add_to_cart(tab)
    loan(a['cart_id'], 'OUTSTANDING')
    webhook(a['cart_id'], 'stub-' + a['cart_id'])
    return ok, attempt(a['entity_id'])


def t_h5_interrupted():
    """H-5: the process dies while sending the reversal request: never sent again, closed as unknown, then an operator
    records what Aplazo confirmed; nothing claims the money was returned."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = rejected_attempt(b)
        stub('localdev_pf_cancel_requested', 'crash')
        crashed = reconcile(a['entity_id'])
        mid = attempt(a['entity_id'])
        time.sleep(4)
        closed = reconcile(a['entity_id'])
        after = attempt(a['entity_id'])
        again = reconcile(a['entity_id'])
        listing = attempts_cli('--id=%s' % a['entity_id'])[1]
        attention = attempts_cli('--attention', '--older-than=0')
        bad_resolve = attempts_cli('--resolve=%s' % a['entity_id'], '--outcome=reversal_confirmed')
        dry = attempts_cli('--resolve=%s' % a['entity_id'], '--outcome=reversal_confirmed', '--reference=APLAZO-CASE-TEST-1')
        dry_state = attempt(a['entity_id'])['status'], sql('SELECT resolution FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
        done = attempts_cli('--resolve=%s' % a['entity_id'], '--outcome=reversal_confirmed', '--reference=APLAZO-CASE-TEST-1',
                            '--note=kit test, synthetic', '--confirm')
        twice = attempts_cli('--resolve=%s' % a['entity_id'], '--outcome=reversal_confirmed', '--reference=APLAZO-CASE-TEST-2', '--confirm')
        attention_after = attempts_cli('--attention', '--older-than=0')
        text = json.dumps(listing)
        good = (ok and a['status'] == 'rejected' and mid['loan_cancel_state'] == 'requested'
                and after['loan_cancel_state'] == 'unknown' and after['last_error'] == 'cancel_request_interrupted'
                and len(r.calls('cancelLoan')) == 0 and again == []
                and int(a['entity_id']) in attention[1]['rejected_unresolved'] and attention[0] == 1
                and bad_resolve[0] == 1 and dry_state[1] == 'NULL'
                and isinstance(done[1], dict) and done[1].get('resolution') == 'reversal_confirmed'
                and twice[0] == 1 and int(a['entity_id']) not in attention_after[1]['rejected_unresolved']
                and 'refunded' not in text.lower() and 'cancelled' not in text.lower())
        record('h5_reversal_interrupted_then_operator_resolution', good, crashed=crashed, after_crash=mid['loan_cancel_state'],
               closed=closed, after=dict(state=after['loan_cancel_state'], last_error=after['last_error']), reconcile_again=again,
               cancel_calls=len(r.calls('cancelLoan')), listing=listing, attention=attention, bad_resolve=bad_resolve,
               dry_run_written=dry_state, resolved=done, resolve_twice=twice, attention_after=attention_after)
    finally:
        stub('localdev_pf_cancel_requested', None)
        b.quit()


def t_h5_cancel_fails():
    """H-5: the reversal request fails: unknown, not retried, still listed for an operator."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = rejected_attempt(b)
        stub('localdev_aplazo_stub_cancel', 'fail')
        first = reconcile(a['entity_id'])
        second = reconcile(a['entity_id'])
        x = attempt(a['entity_id'])
        attention = attempts_cli('--attention', '--older-than=0')
        good = (ok and first and first[0]['result'] == 'cancel_unknown' and second == [] and len(r.calls('cancelLoan')) == 1
                and x['loan_cancel_state'] == 'unknown' and int(a['entity_id']) in attention[1]['rejected_unresolved'])
        record('h5_cancel_request_fails_not_retried', good, first=first, second=second, attempt=x, cancel_calls=len(r.calls('cancelLoan')))
    finally:
        stub('localdev_aplazo_stub_cancel', None)
        b.quit()


def t_h3_cron_registered():
    """H-3: registered customer, browser gone, webhook schedules; the cron class (invoked directly, allowlisted)
    crashes once after saving the order, the attention report shows the paid attempt, the next cron run completes it."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    email, password = 'pf-cron@example.invalid', 'Kit-' + os.urandom(6).hex() + '-Aa1'
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
        sh("printf '%s\n' > var/localdev_pf_cron_allowlist" % a['entity_id'])
        # The finalizer records the payment before converting; a crash after the order is saved leaves the attempt open.
        stub('localdev_pf_order_saved', 'crash')
        run1 = cron_job()
        mid = dict(attempt=attempt(a['entity_id']), orders=r.orders(),
                   paid_confirmed=sql('SELECT paid_confirmed_at IS NOT NULL FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id']))
        attention = attempts_cli('--attention', '--older-than=0')
        time.sleep(2)
        run2 = cron_job()
        o = r.orders()
        cust = sql('SELECT customer_id, customer_is_guest FROM sales_order WHERE entity_id=%s' % o[0]['entity_id']).split('\t') if o else None
        attention_after = attempts_cli('--attention', '--older-than=0')
        good = (ok and logged_in and hook['body'].get('message') == 'scheduled' and mid['paid_confirmed'] == '1'
                and len(mid['orders']) == 1 and mid['orders'][0]['invoices'] == '0' and mid['attempt']['status'] == 'order_created'
                and int(a['entity_id']) in attention[1]['paid_without_order'] and attention[1]['cron_stale']
                and len(o) == 1 and o[0]['invoices'] == '1' and cust == [str(c['customer_id']), '0']
                and attempt(a['entity_id'])['status'] == 'completed' and r.res() == ['-1.0000 order_placed ' + a['cart_id']]
                and int(a['entity_id']) not in attention_after[1]['paid_without_order'])
        record('h3_registered_cron_class_crash_then_complete', good, webhook=hook, cron_run_1=run1, mid=mid, attention=attention,
               cron_run_2=run2, orders=o, order_customer=cust, reservations=r.res(), attention_after=attention_after,
               note='cron class invoked directly in the crontab area; the scheduler (cron:run) was not run')
    finally:
        sh('rm -f var/localdev_pf_cron_allowlist')
        stub('localdev_pf_order_saved', None)
        b.quit()


def t_h8_sequential():
    """H-8: admin cancels a payment-first order between its creation and its approval: no reversal requested, native
    reservation released, the attempt goes to needs_review (not completed) and is listed for an operator."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_approve_before', 'crash')
        webhook(a['cart_id'], 'stub-' + a['cart_id'])
        stub('localdev_pf_approve_before', None)
        o = r.orders()
        cancel = php('order_ops.php', 'cancel', o[0]['entity_id'])
        after_cancel = dict(state=sql('SELECT state FROM sales_order WHERE entity_id=%s' % o[0]['entity_id']),
                            reservations=r.res(), cancel_calls=len(r.calls('cancelLoan')),
                            comment=sql("SELECT COUNT(*) FROM sales_order_status_history WHERE parent_id=%s AND comment LIKE '%%did not request any loan reversal%%'" % o[0]['entity_id']))
        rec = reconcile(a['entity_id'])
        x = attempt(a['entity_id'])
        attention = attempts_cli('--attention', '--older-than=0')
        good = (ok and cancel.get('ok') and after_cancel['state'] == 'canceled' and after_cancel['cancel_calls'] == 0
                and after_cancel['comment'] == '1'
                and after_cancel['reservations'] == ['-1.0000 order_placed ' + a['cart_id'], '1.0000 order_canceled ' + a['cart_id']]
                and x['status'] == 'needs_review' and x['last_error'] == 'order_canceled_after_payment'
                and int(a['entity_id']) in attention[1]['order_canceled'] and int(a['entity_id']) in attention[1]['needs_review_unresolved']
                and sql('SELECT COUNT(*) FROM sales_invoice WHERE order_id=%s' % o[0]['entity_id']) == '0')
        record('h8_admin_cancel_before_approval', good, cancel=cancel, after_cancel=after_cancel, reconcile=rec, attempt=x, attention=attention)
    finally:
        b.quit()


def t_h8_race(path='cancel_direct'):
    """H-8: the admin cancellation loaded and cancelled the order in memory, then the approval commits, then the
    cancellation saves: refused; the order stays approved with its invoice."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_approve_before', 'crash')
        webhook(a['cart_id'], 'stub-' + a['cart_id'])
        stub('localdev_pf_approve_before', None)
        o = r.orders()
        # The cancellation loads the order and is held right there (stale copy in memory); the approval commits; then
        # the cancellation goes on and saves.
        if path == 'cancel_direct':
            t, box = in_thread(php, 'order_ops.php', path, o[0]['entity_id'], 10)
            inside = wait_inside('cancel_direct')
        else:
            stub('localdev_pf_sales_order_load_after', 'once:10')
            t, box = in_thread(php, 'order_ops.php', path, o[0]['entity_id'])
            inside = wait_inside('sales_order_load_after')
        rec = reconcile(a['entity_id'])
        t.join(); stub('localdev_pf_sales_order_load_after', None)
        state = sql('SELECT state FROM sales_order WHERE entity_id=%s' % o[0]['entity_id'])
        invoices = sql('SELECT COUNT(*) FROM sales_invoice WHERE order_id=%s' % o[0]['entity_id'])
        if path == 'cancel_direct':
            # Without the mutex: the approval commits while the cancellation holds a stale copy; its save is refused.
            good = (ok and inside and rec and rec[0]['result'] == 'completed' and box['v'].get('can_cancel_when_loaded') is True
                    and box['v'].get('ok') is False
                    and 'not cancelled' in (box['v'].get('error') or '') and state == 'processing' and invoices == '1'
                    and len(r.calls('cancelLoan')) == 0 and r.res() == ['-1.0000 order_placed ' + a['cart_id']])
            # The reservation must be untouched too: no "order_canceled" compensation for an order that stays approved.
        else:
            # Magento's cancellation (admin button) holds the order mutex from before its load: the approval waits for
            # it and then finds the order cancelled.
            good = (ok and inside and rec and rec[0]['result'] == 'needs_review' and box['v'].get('ok') is True
                    and state == 'canceled' and invoices == '0' and len(r.calls('cancelLoan')) == 0)
        record('h8_cancel_races_approval_%s' % path, good, cancel=box.get('v'), reconcile=rec, state=state, invoices=invoices,
               reservations=r.res(), cancel_calls=len(r.calls('cancelLoan')))
    finally:
        stub('localdev_pf_sales_order_load_after', None)
        b.quit()


def t_h2_slow_log():
    """H-2: the log service takes 5 s per call: the order is completed (and the locks released) long before the webhook
    request ends, because the finalizer's logs are sent after the locks."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_aplazo_stub_log_delay', '5')
        started = time.time()
        t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
        seen = None
        # Polled in the database only (a browser `complete` would run the finalizer itself and send its own logs).
        while t.is_alive() and time.time() - started < 120:
            if seen is None and sql('SELECT status FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id']) == 'completed':
                seen = round(time.time() - started, 1)
            time.sleep(0.3)
        t.join(); ended = round(time.time() - started, 1)
        stub('localdev_aplazo_stub_log_delay', None)
        o = r.orders()
        logs = [c for c in stub_calls_since(r.log) if c.get('service') == 'log' and c.get('delay_seconds') == 5]
        flushed_after = [c['message'] for c in logs if c['message'].startswith('Payment-first')]
        # Two posts of 5 s each are sent after the attempt lock is released (the finalizer's trace), so the request ends
        # at least ~10 s after the order was completed.
        good = (ok and seen is not None and ended - seen >= 9 and len(o) == 1 and o[0]['invoices'] == '1'
                and box['v']['body'].get('message') == 'completed' and len(flushed_after) >= 2)
        record('h2_slow_log_outside_locks', good, attempt_completed_after_s=seen, webhook_returned_after_s=ended,
               delayed_remote_log_calls=[c['message'] for c in logs], orders=o, webhook=box.get('v'),
               note='posts before the finalizer (webhook received, JWT validated) are the unchanged Notifications logs, outside locks')
    finally:
        stub('localdev_aplazo_stub_log_delay', None)
        b.quit()


def t_h1_crash_window(point='model'):
    """H-1 window: the order is committed and the quote deactivated, the process dies before the attempt records its
    order_id, and a cart write that already read the active quote saves before the reconciliation runs."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/checkout/cart/'}); time.sleep(4)
        path, form, body = h1_request('update_qty', qid)
        loan(a['cart_id'], 'OUTSTANDING')
        # 1. The quantity update reads the active quote and is held after its last read, before saving.
        stub('localdev_pf_quote_save_pause', '%s:%d:20' % (point, qid))
        t, box = in_thread(evaluate, tab, FETCH_JS % (json.dumps(path), json.dumps(form), json.dumps(body)), 120)
        held = wait_until(lambda: sh('cat var/localdev_pf_quote_save_pause.inside 2>/dev/null').strip(), 30)
        # 2. The webhook converts; the process dies at order_saved (after the commit, before the attempt link).
        stub('localdev_pf_order_saved', 'crash')
        crashed = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        stub('localdev_pf_order_saved', None)
        after_crash = dict(attempt=attempt(a['entity_id']), orders=r.orders(), quote=quote(qid))
        # 3. The held write goes on before any reconciliation.
        t.join()
        after_write = dict(quote=quote(qid), items=rows('SELECT sku, qty FROM quote_item WHERE quote_id=%d AND parent_item_id IS NULL' % qid, ('sku', 'qty')),
                           orders=r.orders(), reservations=r.res(), write_response=box.get('v'))
        # 4. Can this cart start another purchase now? (start only; not paid)
        again = post(b.ws, '/aplazo/popup/start')['body']
        # 5. Recovery, then integrity again.
        rec = reconcile(a['entity_id'])
        after_recovery = dict(attempt=attempt(a['entity_id']), quote=quote(qid), orders=r.orders(), reservations=r.res(),
                              attempts=[(x['entity_id'], x['status'], x['order_id']) for x in r.attempts()])
        order_of_quote = [o for o in r.orders() if o['quote_id'] == str(qid)]
        reactivated = after_write['quote']['is_active'] == '1'
        record('h1_crash_window_before_link_%s' % point, bool(held) and len(after_crash['orders']) == 1 and not reactivated
               and again.get('status') != 'open' and len(order_of_quote) == 1,
               held_request=held, webhook=crashed, after_crash=after_crash, after_write=after_write, start_again=again,
               reconcile=rec, after_recovery=after_recovery)
    finally:
        stub('localdev_pf_quote_save_pause', None)
        stub('localdev_pf_order_saved', None)
        b.quit()


def t_rollback_not_blocked():
    """A conversion that dies inside its transaction (before submit) is rolled back: no order, no attempt link, and the
    buyer's ordinary cart write is accepted (the guard must not block a cart that did not become an order)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/checkout/cart/'}); time.sleep(4)
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_convert_before', 'crash')
        crashed = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        stub('localdev_pf_convert_before', None)
        mid = dict(attempt=attempt(a['entity_id']), orders=r.orders(), quote=quote(qid), reservations=r.res())
        path, form, body = h1_request('update_qty', qid)
        write = evaluate(tab, FETCH_JS % (json.dumps(path), json.dumps(form), json.dumps(body)), timeout=120)
        after = dict(quote=quote(qid), items=rows('SELECT sku, qty FROM quote_item WHERE quote_id=%d AND parent_item_id IS NULL' % qid, ('sku', 'qty')))
        good = (ok and not mid['orders'] and mid['attempt']['order_id'] == 'NULL' and mid['attempt']['status'] == 'open'
                and mid['quote']['is_active'] == '1' and not mid['reservations'] and '"success":true' in (write or {}).get('body', '')
                and after['quote']['is_active'] == '1' and after['items'] == [{'sku': SKU, 'qty': '3.0000'}])
        record('conversion_rolled_back_cart_write_allowed', good, webhook=crashed, mid=mid, write=write, after=after)
    finally:
        stub('localdev_pf_convert_before', None)
        b.quit()


def rabbit_count(queue):
    """Messages waiting in a RabbitMQ queue of the local stack, read with rabbitmqctl in the container named by
    RABBITMQ_CONTAINER. Without it (or without AMQP) the count is None: not measured."""
    container = os.environ.get('RABBITMQ_CONTAINER')
    if not container:
        return None
    out = subprocess.run(['docker', 'exec', container, 'rabbitmqctl', 'list_queues', 'name', 'messages'],
                         capture_output=True, text=True).stdout
    for line in out.splitlines():
        parts = line.split()
        if len(parts) == 2 and parts[0] == queue:
            return int(parts[1])
    return None


SALABILITY_QUEUE = 'inventory.reservations.updateSalabilityStatus'


def link_failure_case(name, interfere, write_after=True):
    """The conversion is held inside its transaction (convert_before: quote rows locked, snapshot taken); meanwhile
    `interfere` changes the attempt row; then submit runs and linkOrder() must cope. Records what persisted."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/checkout/cart/'}); time.sleep(4)
        loan(a['cart_id'], 'OUTSTANDING')
        queue_before = rabbit_count(SALABILITY_QUEUE)
        stub('localdev_pf_convert_before', 'pause:10')
        t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
        inside = wait_inside('convert_before')
        version_before = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
        interference = interfere(a, tab)
        version_during = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
        t.join(); stub('localdev_pf_convert_before', None)
        persisted = dict(
            webhook=box.get('v'), attempt=attempt(a['entity_id']), quote=quote(qid),
            orders_any_store=rows("SELECT entity_id, store_id FROM sales_order WHERE increment_id='%s'" % a['cart_id'], ('entity_id', 'store_id')),
            grid_rows=sql("SELECT COUNT(*) FROM sales_order_grid WHERE increment_id='%s'" % a['cart_id']),
            invoices=sql("SELECT COUNT(*) FROM sales_invoice i JOIN sales_order o ON o.entity_id=i.order_id WHERE o.increment_id='%s'" % a['cart_id']),
            reservations=r.res(), quote_payment_mark=sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(additional_information, '$.aplazo_payment_attempt')) FROM quote_payment WHERE quote_id=%d" % qid),
            salability_messages_added=(rabbit_count(SALABILITY_QUEUE) or 0) - (queue_before or 0),
            local_trace=sh("grep -c 'Payment-first order placed, loan already paid {\"order_id\":\"%s\"}' var/log/aplazo_payment/info.log" % a['cart_id']).strip(),
            remote_log=[c.get('message') for c in r.calls() if c.get('service') == 'log' and c.get('order_id') == a['cart_id']])
        # A legitimate cart change afterwards must not be blocked by the guard.
        write = after_write = None
        if write_after:
            path, form, body = h1_request('update_qty', qid)
            write = evaluate(tab, FETCH_JS % (json.dumps(path), json.dumps(form), json.dumps(body)), timeout=120)
            after_write = quote(qid)
        record(name, None, inside=bool(inside), version_before=version_before, version_during_hold=version_during,
               interference=interference, persisted=persisted, cart_write=write, quote_after_write=after_write)
        return a, r
    finally:
        stub('localdev_pf_convert_before', None)
        b.quit()


def t_link_conflict():
    """Legitimate concurrent update: the buyer's `complete` during the conversion records the payment / asks for a check
    (attempt lock busy), which moves the attempt version."""
    def interfere(a, tab):
        return complete_from(tab, a)['body']
    a, r = link_failure_case('link_conflict_concurrent_complete', interfere, write_after=False)
    rec = reconcile(a['entity_id'])
    record('link_conflict_then_reconcile', None, reconcile=rec, attempt=attempt(a['entity_id']),
           orders=[o['increment_id'] + ':' + o['state'] + ':inv' + o['invoices'] for o in r.orders()], reservations=r.res())


def t_link_conflict_with_write():
    def interfere(a, tab):
        return complete_from(tab, a)['body']
    link_failure_case('link_conflict_then_cart_write', interfere)


def t_link_definitive():
    """Definitive link failure (synthetic): while the conversion runs, the attempt is moved out of the payable states
    by a direct SQL write, as no supported concurrent path does that during a conversion."""
    def interfere(a, tab):
        sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
        return 'status set to needs_review by SQL (synthetic)'
    link_failure_case('link_definitive_failure_synthetic', interfere)


def hold_conversion_and(a, interfere, crash_after_commit=False):
    """Webhook converts while held inside the conversion transaction; `interfere` runs during the hold."""
    stub('localdev_pf_convert_before', 'pause:10')
    if crash_after_commit:
        stub('localdev_pf_order_saved', 'crash')
    t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
    inside = wait_inside('convert_before')
    v0 = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
    note = interfere()
    v1 = sql('SELECT version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'])
    t.join()
    stub('localdev_pf_convert_before', None); stub('localdev_pf_order_saved', None)
    return dict(inside=bool(inside), version_before=v0, version_during_hold=v1, interference=note, webhook=box.get('v'))


def plugin_stock_moves(r):
    return [x for x in r.res() if 'aplazo' in x]


def t_link_conflict_fixed(crash=False):
    """Legitimate concurrent update during the conversion (the buyer's complete): the link is applied on the current
    row and the order completes (or, with crash, the link is already persisted when the process dies after the commit)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/checkout/cart/'}); time.sleep(4)
        loan(a['cart_id'], 'OUTSTANDING')
        held = hold_conversion_and(a, lambda: complete_from(tab, a)['body'], crash_after_commit=crash)
        mid = dict(attempt=attempt(a['entity_id']), orders=r.orders(), quote=quote(qid), reservations=r.res())
        rec = reconcile(a['entity_id']) if crash else None
        x = attempt(a['entity_id']); o = r.orders()
        good = (ok and held['inside'] and int(held['version_during_hold']) > int(held['version_before']) and len(o) == 1
                and x['status'] == 'completed' and x['order_id'] == o[0]['entity_id'] and o[0]['invoices'] == '1'
                and o[0]['increment_id'] == a['cart_id'] and quote(qid)['is_active'] == '0'
                and r.res() == ['-1.0000 order_placed ' + a['cart_id']] and not plugin_stock_moves(r))
        if crash:
            good = good and mid['attempt']['status'] == 'order_created' and mid['attempt']['order_id'] == o[0]['entity_id'] \
                and len(mid['orders']) == 1 and mid['orders'][0]['invoices'] == '0' and rec and rec[0]['result'] == 'completed'
        else:
            good = good and held['webhook']['body'].get('message') == 'completed'
        record('link_conflict_legit_%s' % ('crash_after_commit' if crash else 'completes'), good, held=held, mid=mid,
               reconcile=rec, attempt=x, orders=o, quote=quote(qid), reservations=r.res())
    finally:
        b.quit()


def t_link_incompatible():
    """Incompatible conflict (synthetic, direct SQL): during the conversion the attempt is moved to needs_review. The
    conversion must roll back and leave that row exactly as written (status, last_error, check_count)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        loan(a['cart_id'], 'OUTSTANDING')
        def interfere():
            sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
            return 'SYNTHETIC: status set to needs_review by direct SQL'
        held = hold_conversion_and(a, interfere)
        x = attempt(a['entity_id'])
        written = rows('SELECT status, last_error, check_count, version FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'],
                       ('status', 'last_error', 'check_count', 'version'))[0]
        good = (ok and held['inside'] and held['webhook']['body'] == {'status': False, 'message': 'needs_review'}
                and written['status'] == 'needs_review' and written['last_error'] == 'kit_synthetic_definitive'
                and written['check_count'] == '0' and written['version'] == held['version_during_hold']
                and not r.orders() and not r.res() and quote(qid)['is_active'] == '1' and x['order_id'] == 'NULL')
        record('link_incompatible_conflict_aborts_synthetic', good, held=held, attempt_row=written, orders=r.orders(),
               reservations=r.res(), quote=quote(qid))
    finally:
        b.quit()


COUPON = 'PFKIT10'
COUPON_QUEUE = 'sales.rule.update.coupon.usage'


def coupon_usage():
    return rows("SELECT c.times_used, r.times_used FROM salesrule_coupon c JOIN salesrule r ON r.rule_id=c.rule_id WHERE c.code='%s'" % COUPON,
                ('coupon_times_used', 'rule_times_used'))[0]


def consume_coupon_queue(n):
    """Runs Magento's own consumer for the coupon usage topic for exactly n messages (only kit messages are queued)."""
    if not n:
        return 'nothing to consume'
    out = run(['bin/clinotty', 'bin/magento', 'queue:consumers:start', COUPON_QUEUE, '--max-messages=%d' % n, '--single-thread'], timeout=180)
    return out.strip()[-200:] or 'ok'


def t_coupon(mode):
    """A cart with a coupon. mode=success: paid and converted (baseline). mode=link_fail: definitive link failure after
    submit (synthetic SQL), conversion rolled back. Records coupon usage, published messages and the consumer's effect."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        php('order_ops.php', 'coupon', COUPON)
        add_to_cart(b.ws)
        applied = post(b.ws, '/checkout/cart/couponPost/', {'coupon_code': COUPON})
        ok = to_payment(b.ws)
        before_attempt = max_id('aplazo_payment_attempt'); mock_plan('stay')
        click_place_order(b.ws)
        a = wait_attempt(before_attempt, status=('open',)); time.sleep(2)
        qid = int(a['quote_id'])
        q_coupon = sql('SELECT coupon_code FROM quote WHERE entity_id=%d' % qid)
        loan(a['cart_id'], 'OUTSTANDING')
        usage0, q0 = coupon_usage(), rabbit_count(COUPON_QUEUE)
        if mode == 'success':
            held = dict(webhook=webhook(a['cart_id'], 'stub-' + a['cart_id']))
        else:
            def interfere():
                sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
                return 'SYNTHETIC: status set to needs_review by direct SQL'
            held = hold_conversion_and(a, interfere)
        usage1, q1 = coupon_usage(), rabbit_count(COUPON_QUEUE)
        consumed = consume_coupon_queue((q1 or 0) - (q0 or 0))
        usage2, q2 = coupon_usage(), rabbit_count(COUPON_QUEUE)
        o = r.orders()
        record('coupon_%s' % mode, None, coupon_applied=applied.get('http'), quote_coupon=q_coupon, held=held,
               orders=[x['increment_id'] + ':' + x['state'] for x in o],
               order_coupon=sql("SELECT coupon_code FROM sales_order WHERE increment_id='%s'" % a['cart_id']),
               usage_before=usage0, usage_after_webhook=usage1, usage_after_consumer=usage2,
               coupon_messages_published=(q1 or 0) - (q0 or 0), coupon_queue_after_consumer=q2, consumer=consumed,
               attempt=attempt(a['entity_id']), reservations=r.res())
    finally:
        b.quit()


def t_cart_changed_record():
    """Paid, cart changed in another tab: what is kept about the payment, the loan and the need for attention, before any
    reconciliation or reversal (nothing here is a refund or a resolution)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        tab = b.new_tab(); add_to_cart(tab)
        loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        kept = rows('SELECT status, reject_reason, cart_id, loan_id, paid_confirmed_at IS NOT NULL, loan_cancel_state, order_id, resolution '
                    'FROM aplazo_payment_attempt WHERE entity_id=%s' % a['entity_id'],
                    ('status', 'reject_reason', 'cart_id', 'loan_id', 'paid_confirmed', 'loan_cancel_state', 'order_id', 'resolution'))[0]
        buyer = complete_from(tab, a)['body']
        listing = attempts_cli('--id=%s' % a['entity_id'])[1]
        row = listing[0] if isinstance(listing, list) and listing else {}
        attention = attempts_cli('--attention', '--older-than=0')
        text = json.dumps([buyer, row]).lower()
        good = (ok and kept['status'] == 'rejected' and kept['reject_reason'] == 'cart_changed' and kept['paid_confirmed'] == '1'
                and kept['loan_id'] == 'stub-' + a['cart_id'] and kept['cart_id'] == a['cart_id'] and kept['loan_cancel_state'] == 'pending'
                and kept['resolution'] == 'NULL' and not r.orders() and int(a['entity_id']) in attention[1]['rejected_unresolved']
                and attention[0] == 1 and not any(w in text for w in ('refunded', 'reembols', 'devuelto', 'cancelled', 'cancelado')))
        record('cart_changed_payment_kept_attention', good, webhook=hook, attempt_kept=kept, buyer_message=buyer,
               cli_payment=row.get('payment'), cli_conversion=row.get('conversion'), cli_reversal=row.get('reversal'),
               attention_exit=attention[0], in_rejected_unresolved=int(a['entity_id']) in attention[1]['rejected_unresolved'],
               cancel_calls=len(r.calls('cancelLoan')))
    finally:
        b.quit()


def t_crash_after_writes():
    """Point 1 (H-1): the PHP process exits inside the conversion transaction AFTER submit wrote the order, the MSI
    reservation and the quote deactivation (crash at sales_quote_save_after). No catch runs; the database rolls back
    because the connection closes. Compared with link_incompatible, where the catch rolls back."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        qid = int(a['quote_id'])
        loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_sales_quote_save_after', 'crash')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id'])
        stub('localdev_pf_sales_quote_save_after', None)
        crashed_at = [c for c in r.calls() if c.get('service') == 'sync' and c.get('method') == 'sales_quote_save_after']
        after = dict(orders=rows("SELECT entity_id, store_id FROM sales_order WHERE increment_id='%s'" % a['cart_id'], ('entity_id', 'store_id')),
                     grid=sql("SELECT COUNT(*) FROM sales_order_grid WHERE increment_id='%s'" % a['cart_id']),
                     reservations=r.res(), quote=quote(qid), attempt=attempt(a['entity_id']),
                     order_placed_trace=[c.get('message') for c in r.calls() if c.get('service') == 'log' and c.get('order_id') == a['cart_id']])
        rec = reconcile(a['entity_id'])
        o = r.orders()
        record('crash_inside_tx_after_writes', None, webhook_body=str((hook or {}).get('body'))[:300], crash_point=crashed_at,
               after_crash=after, reconcile=rec, final_attempt=attempt(a['entity_id'])['status'],
               final_orders=[x['increment_id'] + ':' + x['state'] + ':inv' + x['invoices'] for x in o], final_reservations=r.res())
    finally:
        stub('localdev_pf_sales_quote_save_after', None)
        b.quit()


def approval_snapshot(r, a):
    o = rows("SELECT o.entity_id, o.state, o.status, JSON_UNQUOTE(JSON_EXTRACT(p.additional_information,'$.aplazo_status')), "
             "(SELECT COUNT(*) FROM sales_order_status_history h WHERE h.parent_id=o.entity_id AND h.comment LIKE '%%loan status API%%'), "
             "(SELECT COUNT(*) FROM sales_invoice i WHERE i.order_id=o.entity_id), o.send_email, o.email_sent "
             "FROM sales_order o JOIN sales_order_payment p ON p.parent_id=o.entity_id WHERE o.increment_id='%s'" % a['cart_id'],
             ('entity_id', 'state', 'status', 'aplazo_status', 'confirm_comment', 'invoices', 'send_email', 'email_sent'))
    x = attempt(a['entity_id'])
    calls = r.calls()
    return dict(order=o[0] if o else None, attempt_status=x['status'], attempt_order_id=x['order_id'],
                email_requested_at=x['email_requested_at'], reservations=r.res(),
                tracking_order_paid_events=len([c for c in calls if c.get('service') == 'tracking' and c.get('event') == 'order_paid']),
                invoice_probe=[{k: c.get(k) for k in ('state', 'status', 'is_in_process')} for c in calls
                               if c.get('service') == 'probe' and c.get('order_id') == a['cart_id']])


def t_approval(mode):
    """Approval effects (state/status, aplazo_status, confirmation comment, tracking, email request) and what a repeated
    finalization recovers. mode: default | custom_status | crash_after_invoice | operator_invoice."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    custom = 'aplazo_kit_paid'
    try:
        if mode == 'custom_status':
            php('order_ops.php', 'order_status', custom)
            set_config_sql('payment/aplazo_gateway/approved_order_status', 'websites', 1, "'%s'" % custom)
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        steps = {}
        if mode == 'crash_after_invoice':
            stub('localdev_pf_sales_order_invoice_save_after', 'crash')
            steps['webhook'] = str(webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body'))[:200]
            stub('localdev_pf_sales_order_invoice_save_after', None)
            steps['after_crash'] = approval_snapshot(r, a)
            steps['reconcile_1'] = reconcile(a['entity_id'])
        elif mode == 'operator_invoice':
            stub('localdev_pf_approve_before', 'crash')
            steps['webhook'] = str(webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body'))[:200]
            stub('localdev_pf_approve_before', None)
            o = r.orders()
            steps['before_invoice'] = approval_snapshot(r, a)
            steps['operator_invoice'] = php('order_ops.php', 'invoice', o[0]['entity_id'])
            steps['after_operator_invoice'] = approval_snapshot(r, a)
            steps['reconcile_1'] = reconcile(a['entity_id'])
        else:
            steps['webhook'] = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
        steps['after_first_finalization'] = approval_snapshot(r, a)
        # Repeated finalization: webhook again, then the reconciliation for this attempt.
        steps['webhook_again'] = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
        steps['reconcile_again'] = reconcile(a['entity_id'])
        steps['after_repeat'] = approval_snapshot(r, a)
        record('approval_%s' % mode, None, configured_approved_status=custom if mode == 'custom_status' else 'processing (default)',
               steps=steps)
    finally:
        stub('localdev_pf_sales_order_invoice_save_after', None); stub('localdev_pf_approve_before', None)
        if mode == 'custom_status':
            set_config_sql('payment/aplazo_gateway/approved_order_status', 'websites', 1, None)
        b.quit()


def email_snapshot(r, a, since):
    s = approval_snapshot(r, a)
    s['order_sender_probe'] = [c for c in stub_calls_since(since)
                               if c.get('service') == 'probe' and c.get('method', '').startswith('order_sender')
                               and c.get('order_id') == a['cart_id']]
    return s


def t_email_path(mode):
    """Order email per finalization path, one synthetic guest order each. mode: webhook | reconcile_cli (the operator
    command, crontab area emulated) | reconcile_cron_class (Cron\\ReconcilePaymentAttempts::execute in the crontab area,
    as cron_job.php; not the scheduler). The probe plugin records area/store and the full exception, rethrown unchanged."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        since = stub_log_len()
        steps = {'before': email_snapshot(r, a, since)}
        if mode == 'webhook':
            steps['finalize'] = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
        elif mode == 'reconcile_cli':
            steps['finalize'] = reconcile(a['entity_id'])
        else:
            sh("printf '%s\\n' > var/localdev_pf_cron_allowlist" % a['entity_id'])
            try:
                steps['finalize'] = cron_job()
            finally:
                sh('rm -f var/localdev_pf_cron_allowlist')
        steps['after'] = email_snapshot(r, a, since)
        record('email_%s' % mode, None, cart_id=a['cart_id'], attempt_id=a['entity_id'], steps=steps)
    finally:
        b.quit()


ASYNC_PATH = 'sales_email/general/async_sending'


def async_selection(cart_id):
    """Read-only: whether Magento's async order email job (EmailSenderHandler::sendEmails) would select this order now:
    send_email=1, email_sent NULL, created at or after the async setting's updated_at. The job is not run."""
    r = rows("SELECT o.send_email, o.email_sent, (o.created_at >= (SELECT updated_at FROM core_config_data WHERE path='%s' "
             "AND scope='default' AND scope_id=0)) FROM sales_order o WHERE o.increment_id='%s'" % (ASYNC_PATH, cart_id),
             ('send_email', 'email_sent', 'created_after_async_enabled'))
    return dict(r[0], selected=bool(r and r[0]['send_email'] == '1' and r[0]['email_sent'] == 'NULL'
                                    and r[0]['created_after_async_enabled'] == '1')) if r else None


def t_email_fix(mode):
    """PaymentAttemptsCommand area fix: CLI reconciliation (sync / async) and webhook, then a repeated finalization
    (webhook + CLI reconciliation). One synthetic guest order each. mode: cli_sync | cli_async | webhook."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        if mode == 'cli_async':
            set_config_sql(ASYNC_PATH, 'default', 0, "'1'")
        ok, a = open_popup(b, plan='stay')
        loan(a['cart_id'], 'OUTSTANDING')
        since = stub_log_len()
        steps = {'async_sending': '1' if mode == 'cli_async' else 'default (0)', 'before': email_snapshot(r, a, since)}
        steps['finalize'] = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body') if mode == 'webhook' else reconcile(a['entity_id'])
        steps['after'] = email_snapshot(r, a, since)
        if mode == 'cli_async':
            steps['async_job_would_select'] = async_selection(a['cart_id'])
        steps['webhook_again'] = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
        steps['reconcile_again'] = reconcile(a['entity_id'])
        steps['after_repeat'] = email_snapshot(r, a, since)
        steps['orders_for_cart'] = len([o for o in r.orders() if o['increment_id'] == a['cart_id']])
        steps['order_sender_calls'] = len([c for c in steps['after_repeat']['order_sender_probe'] if c['method'] == 'order_sender_before'])
        record('email_fix_%s' % mode, None, cart_id=a['cart_id'], attempt_id=a['entity_id'], steps=steps)
    finally:
        if mode == 'cli_async':
            set_config_sql(ASYNC_PATH, 'default', 0, None)
        b.quit()


def coupon_counters(customer_id=None):
    """Every counter the coupon touches, read from the database, plus the RabbitMQ queue and the bulk bookkeeping.
    coupon: salesrule_coupon.times_used (sync, in the caller's transaction); rule: salesrule.times_used (only the queue
    consumer writes it); customer_rule: salesrule_customer (sync); customer_coupon: salesrule_coupon_usage (sync)."""
    c = coupon_usage()
    out = {'coupon': c['coupon_times_used'], 'rule': c['rule_times_used']}
    if customer_id:
        out['customer_rule'] = sql("SELECT COALESCE(SUM(sc.times_used),0) FROM salesrule_customer sc JOIN salesrule_coupon c "
                                   "ON c.rule_id=sc.rule_id WHERE c.code='%s' AND sc.customer_id=%d" % (COUPON, int(customer_id)))
        out['customer_coupon'] = sql("SELECT COALESCE(SUM(u.times_used),0) FROM salesrule_coupon_usage u JOIN salesrule_coupon c "
                                     "ON c.coupon_id=u.coupon_id WHERE c.code='%s' AND u.customer_id=%d" % (COUPON, int(customer_id)))
    out['queued'] = rabbit_count(COUPON_QUEUE)
    out['bulks_rule_processing'] = sql("SELECT COUNT(*) FROM magento_bulk WHERE description LIKE 'Rule processing%'")
    out['operations'] = sql("SELECT COUNT(*) FROM magento_operation WHERE topic_name='%s'" % COUPON_QUEUE)
    return out


def coupon_cart(b, email):
    """Cart with one unit and the kit coupon, payment step, Place Order: returns the attempt the click created."""
    add_to_cart(b.ws)
    applied = post(b.ws, '/checkout/cart/couponPost/', {'coupon_code': COUPON})
    to_payment(b.ws, email)
    before = max_id('aplazo_payment_attempt'); mock_plan('stay')
    click_place_order(b.ws)
    a = wait_attempt(before, status=('open',)); time.sleep(2)
    return applied, a


def t_coupon_counters(mode, who='guest'):
    """Coupon counters around a payment-first conversion. mode: success | abort_terminal (definitive link failure after
    submit, synthetic SQL; guest only) | crash_then_recover (kit crash at sales_quote_save_after inside the conversion
    transaction, then the reconciliation creates the order). who: guest | registered (the webhook only schedules a
    registered customer's attempt, so the CLI reconciliation converts it). Counters before, after each step without
    consuming, and after Magento's own consumer for exactly the messages the step queued."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    customer_id = None
    email = 'pf-test@example.invalid'
    steps = []
    def snap(label, consume=False):
        before = steps[-1]['counters']['queued'] if steps else None
        entry = {'step': label, 'counters': coupon_counters(customer_id)}
        if consume:
            n = (entry['counters']['queued'] or 0)
            entry['consumer'] = consume_coupon_queue(n)
            entry['consumed'] = n
            entry['counters_after_consumer'] = coupon_counters(customer_id)
        steps.append(entry)
    try:
        php('order_ops.php', 'coupon', COUPON)
        if who == 'registered':
            email, password = 'pf-coupon@example.invalid', 'Kit-' + os.urandom(6).hex() + '-Aa1'
            sql("DELETE FROM customer_entity WHERE email='%s'" % email)
            customer_id = php('order_ops.php', 'customer', email, password)['customer_id']
            b.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'})
            wait_for(b.ws, "!!document.querySelector('#email')", 40)
            evaluate(b.ws, LOGIN_JS % (json.dumps(email), json.dumps(password)), timeout=60)
        snap('baseline')
        applied, a = coupon_cart(b, email)
        loan(a['cart_id'], 'OUTSTANDING')
        snap('attempt_open')
        finalize = (lambda: reconcile(a['entity_id'])) if who == 'registered' else (lambda: webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body'))
        result = {}
        if mode == 'success':
            result['finalize'] = finalize()
            snap('after_conversion', consume=True)
        elif mode == 'abort_terminal':
            def interfere():
                sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
                return 'SYNTHETIC: status set to needs_review by direct SQL'
            result['held'] = {k: v for k, v in hold_conversion_and(a, interfere).items() if k in ('inside', 'webhook')}
            snap('after_abort', consume=True)
        else:
            stub('localdev_pf_sales_quote_save_after', 'crash')
            result['crashed'] = str(finalize())[:160]
            stub('localdev_pf_sales_quote_save_after', None)
            result['after_crash_orders'] = len([o for o in r.orders() if o['increment_id'] == a['cart_id']])
            snap('after_crash', consume=True)
            result['recover'] = reconcile(a['entity_id'])
            snap('after_recovery', consume=True)
        o = [x for x in r.orders() if x['increment_id'] == a['cart_id']]
        record('coupon_counters_%s_%s' % (who, mode), None, cart_id=a['cart_id'], customer_id=customer_id,
               coupon_applied=applied.get('http'), quote_coupon=sql('SELECT coupon_code FROM quote WHERE entity_id=%s' % a['quote_id']),
               result=result, steps=steps, final_orders=[x['increment_id'] + ':' + x['state'] for x in o],
               order_coupon=sql("SELECT coupon_code FROM sales_order WHERE increment_id='%s'" % a['cart_id']),
               attempt=attempt(a['entity_id'])['status'], reservations=r.res())
    finally:
        stub('localdev_pf_sales_quote_save_after', None)
        b.quit()


def counters(code, customer_id=None):
    """Coupon counters for one kit coupon (see coupon_counters), the coupon queue and the bulk bookkeeping."""
    c = rows("SELECT c.times_used, r.times_used FROM salesrule_coupon c JOIN salesrule r ON r.rule_id=c.rule_id WHERE c.code='%s'" % code,
             ('coupon', 'rule'))[0]
    out = dict(c)
    if customer_id:
        out['customer_rule'] = sql("SELECT COALESCE(SUM(sc.times_used),0) FROM salesrule_customer sc JOIN salesrule_coupon c "
                                   "ON c.rule_id=sc.rule_id WHERE c.code='%s' AND sc.customer_id=%d" % (code, int(customer_id)))
        out['customer_coupon'] = sql("SELECT COALESCE(SUM(u.times_used),0) FROM salesrule_coupon_usage u JOIN salesrule_coupon c "
                                     "ON c.coupon_id=u.coupon_id WHERE c.code='%s' AND u.customer_id=%d" % (code, int(customer_id)))
    out['queued'] = rabbit_count(COUPON_QUEUE)
    out['bulks_rule_processing'] = sql("SELECT COUNT(*) FROM magento_bulk WHERE description LIKE 'Rule processing%'")
    return out


def probe_lines(since):
    """Kit observations since a log position: coupon publications (with transaction level and the newest committed kit
    order seen from an independent session), after-submit callback pool, sync points, and the module's coupon log."""
    keep = []
    for c in stub_calls_since(since):
        m = c.get('method', '')
        if (c.get('service') == 'probe' and m in ('coupon_bulk_publish', 'callback_pool_after_submit')) or c.get('service') == 'sync' \
                or (c.get('service') == 'log' and 'coupon' in (c.get('message') or '')):
            keep.append({k: v for k, v in c.items() if k != 'at'})
    return keep


class CouponRun:
    """Steps of one coupon scenario: counters before/after each step, Magento's consumer for exactly the messages the
    step queued, and the kit observations of the step."""
    def __init__(self, code, customer_id=None):
        self.code, self.customer_id, self.steps, self.pos = code, customer_id, [], stub_log_len()

    def snap(self, label, consume=True, **extra):
        e = dict(step=label, counters=counters(self.code, self.customer_id), observed=probe_lines(self.pos), **extra)
        self.pos = stub_log_len()
        if consume:
            n = e['counters']['queued'] or 0
            e['consumed'] = n
            e['consumer'] = consume_coupon_queue(n) if n else 'nothing to consume'
            e['counters_after_consumer'] = counters(self.code, self.customer_id)
        self.steps.append(e)
        return e


def order_state(cart_id):
    r = rows("SELECT o.state, o.status, (SELECT COUNT(*) FROM sales_invoice i WHERE i.order_id=o.entity_id), o.coupon_code, "
             "(SELECT COUNT(*) FROM sales_order_status_history h WHERE h.parent_id=o.entity_id AND h.comment LIKE '%%loan status API%%') "
             "FROM sales_order o WHERE o.increment_id='%s'" % cart_id, ('state', 'status', 'invoices', 'coupon', 'confirm_comments'))
    return dict(orders=len(r), **(r[0] if r else {}))


def login(b, email, password=None):
    """Logs a synthetic customer in. Without a password the customer is created anew (any older row is deleted) with a
    random one, kept in LOGINS for a later login of the same customer."""
    if password is None:
        password = 'Kit-' + os.urandom(6).hex() + '-Aa1'
        sql("DELETE FROM customer_entity WHERE email='%s'" % email)
        LOGINS[email] = (php('order_ops.php', 'customer', email, password)['customer_id'], password)
    b.ws.send('Page.navigate', {'url': BASE + '/customer/account/login/'})
    wait_for(b.ws, "!!document.querySelector('#email')", 40)
    evaluate(b.ws, LOGIN_JS % (json.dumps(email), json.dumps(password)), timeout=60)
    return LOGINS[email][0]


LOGINS = {}


def coupon_attempt(b, code, email):
    """Cart, coupon, payment step, Place Order. Returns (coupon kept on the quote, attempt or None)."""
    add_to_cart(b.ws)
    post(b.ws, '/checkout/cart/couponPost/', {'coupon_code': code})
    before = max_id('aplazo_payment_attempt')
    qid = sql("SELECT MAX(entity_id) FROM quote")
    applied = sql("SELECT coupon_code FROM quote WHERE entity_id=%s" % qid)
    to_payment(b.ws, email); mock_plan('stay')
    click_place_order(b.ws)
    a = wait_attempt(before, status=('open',)); time.sleep(2)
    return sql("SELECT coupon_code FROM quote WHERE entity_id=%s" % a['quote_id']), a


def t_coupon_fix(mode, who='guest'):
    """Coupon usage with the deferred publication (BNPL-1664). mode: success (+ repeated finalization) | abort_terminal
    (definitive link failure after submit, synthetic SQL) | crash_recover (kit crash inside the conversion transaction,
    then reconciliation) | submit_failure (kit RuntimeException inside submit, then reconciliation) | publish_exception /
    publish_error (kit RuntimeException / \Error from the bulk publisher after scheduleBulk opened its transaction)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        php('order_ops.php', 'coupon', COUPON)
        email, cid = 'pf-test@example.invalid', None
        if who == 'registered':
            email = 'pf-coupon@example.invalid'; cid = login(b, email)
        run_ = CouponRun(COUPON, cid)
        run_.snap('baseline', consume=False)
        kept, a = coupon_attempt(b, COUPON, email)
        loan(a['cart_id'], 'OUTSTANDING')
        finalize = (lambda: reconcile(a['entity_id'])) if who == 'registered' else (lambda: webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body'))
        run_.snap('attempt_open', consume=False, quote_coupon=kept)
        if mode == 'success':
            res = finalize(); run_.snap('after_conversion', result=res, order=order_state(a['cart_id']), attempt=attempt(a['entity_id'])['status'])
            res = dict(webhook=webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body'), reconcile=reconcile(a['entity_id']))
            run_.snap('after_repeat', result=res, order=order_state(a['cart_id']), attempt=attempt(a['entity_id'])['status'])
        elif mode == 'abort_terminal':
            def interfere():
                sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
                return 'SYNTHETIC: status set to needs_review by direct SQL'
            held = hold_conversion_and(a, interfere)
            run_.snap('after_abort', result={k: held.get(k) for k in ('inside', 'webhook')}, order=order_state(a['cart_id']), attempt=attempt(a['entity_id'])['status'])
        elif mode in ('crash_recover', 'submit_failure'):
            point, plan = ('localdev_pf_sales_quote_save_after', 'crash') if mode == 'crash_recover' else ('localdev_pf_sales_model_service_quote_submit_before', 'throw')
            stub(point, plan)
            res = str(finalize())[:200]
            stub(point, None)
            run_.snap('after_failure', result=res, order=order_state(a['cart_id']), attempt=attempt(a['entity_id'])['status'])
            res = reconcile(a['entity_id'])
            run_.snap('after_recovery', result=res, order=order_state(a['cart_id']), attempt=attempt(a['entity_id'])['status'])
        else:
            stub('localdev_pf_bulk_publish_fault', 'error' if mode == 'publish_error' else 'exception')
            res = finalize()
            stub('localdev_pf_bulk_publish_fault', None)
            run_.snap('after_conversion', result=res, order=order_state(a['cart_id']), attempt=attempt(a['entity_id'])['status'])
        record('coupon_fix_%s_%s' % (who, mode), None, cart_id=a['cart_id'], customer_id=cid, steps=run_.steps,
               reservations=r.res(), final_attempt=attempt(a['entity_id'])['status'])
    finally:
        for f in ('localdev_pf_sales_quote_save_after', 'localdev_pf_sales_model_service_quote_submit_before', 'localdev_pf_bulk_publish_fault'):
            stub(f, None)
        b.quit()


def t_coupon_redirect():
    """Redirect checkout with the kit coupon: the plugin must not change anything (publication from inside submit, as in
    Magento; one message; rule +1 after the consumer)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        website_mode('redirect')
        php('order_ops.php', 'coupon', COUPON)
        run_ = CouponRun(COUPON)
        run_.snap('baseline', consume=False)
        add_to_cart(b.ws)
        post(b.ws, '/checkout/cart/couponPost/', {'coupon_code': COUPON})
        to_payment(b.ws)
        click_place_order(b.ws)
        o = wait_until(lambda: r.orders(), 60)
        inc = o[0]['increment_id'] if o else None
        run_.snap('after_place_order', order=order_state(inc) if inc else None, attempts=len(r.attempts()))
        hook = webhook(inc, '888002') if inc else None
        run_.snap('after_webhook', result=(hook or {}).get('body'), order=order_state(inc) if inc else None)
        record('coupon_fix_redirect', None, increment_id=inc, steps=run_.steps, reservations=r.res())
    finally:
        website_mode('popup')
        b.quit()


LIMIT_COUPON = 'PFKITLIM3'


def t_coupon_limits():
    """Configured limits (rule LIMIT_COUPON: 2 uses per coupon, 1 per customer). A registered customer crashes inside the
    conversion and is recovered; the same customer is then refused the coupon. A guest aborts after submit, another
    guest succeeds, and a third guest is refused (coupon limit). The limits read the synchronous counters only."""
    r = Run(); default_loan('REQUEST')
    out = {'rule': php('order_ops.php', 'coupon', LIMIT_COUPON, '2', '1')}
    out['config'] = rows("SELECT r.uses_per_coupon, r.uses_per_customer, c.usage_limit, c.usage_per_customer FROM salesrule r JOIN salesrule_coupon c "
                         "ON c.rule_id=r.rule_id WHERE c.code='%s'" % LIMIT_COUPON, ('uses_per_coupon', 'uses_per_customer', 'usage_limit', 'usage_per_customer'))[0]
    email = 'pf-limit@example.invalid'
    steps = []
    def one(label, fn):
        b = Browser()
        try:
            steps.append(dict(step=label, **fn(b)))
        finally:
            b.quit()
    state = {}
    def registered_crash(b):
        state['cid'] = login(b, email)
        kept, a = coupon_attempt(b, LIMIT_COUPON, email); loan(a['cart_id'], 'OUTSTANDING')
        stub('localdev_pf_sales_quote_save_after', 'crash'); reconcile(a['entity_id']); stub('localdev_pf_sales_quote_save_after', None)
        mid = counters(LIMIT_COUPON, state['cid'])
        rec = reconcile(a['entity_id'])
        c = counters(LIMIT_COUPON, state['cid']); n = c['queued'] or 0
        return dict(cart_id=a['cart_id'], quote_coupon=kept, after_crash=mid, recover=rec, order=order_state(a['cart_id']), before_consumer=c,
                    consumer=consume_coupon_queue(n) if n else 'nothing', after_consumer=counters(LIMIT_COUPON, state['cid']))
    def registered_again(b):
        login(b, email, LOGINS[email][1])  # the same customer, in a new browser
        add_to_cart(b.ws)
        resp = post(b.ws, '/checkout/cart/couponPost/', {'coupon_code': LIMIT_COUPON})
        qid = sql("SELECT MAX(entity_id) FROM quote WHERE customer_id=%d" % state['cid'])
        return dict(coupon_post=resp.get('http'), quote_coupon=sql("SELECT coupon_code FROM quote WHERE entity_id=%s" % qid),
                    counters=counters(LIMIT_COUPON, state['cid']))
    def guest_abort(b):
        kept, a = coupon_attempt(b, LIMIT_COUPON, 'pf-test@example.invalid'); loan(a['cart_id'], 'OUTSTANDING')
        def interfere():
            sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
            return 'SYNTHETIC'
        held = hold_conversion_and(a, interfere)
        c = counters(LIMIT_COUPON); n = c['queued'] or 0
        return dict(cart_id=a['cart_id'], quote_coupon=kept, webhook=held.get('webhook'), order=order_state(a['cart_id']), before_consumer=c,
                    consumer=consume_coupon_queue(n) if n else 'nothing', after_consumer=counters(LIMIT_COUPON))
    def guest_success(b):
        kept, a = coupon_attempt(b, LIMIT_COUPON, 'pf-test@example.invalid'); loan(a['cart_id'], 'OUTSTANDING')
        hook = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
        c = counters(LIMIT_COUPON); n = c['queued'] or 0
        return dict(cart_id=a['cart_id'], quote_coupon=kept, webhook=hook, order=order_state(a['cart_id']), before_consumer=c,
                    consumer=consume_coupon_queue(n) if n else 'nothing', after_consumer=counters(LIMIT_COUPON))
    def guest_refused(b):
        add_to_cart(b.ws)
        resp = post(b.ws, '/checkout/cart/couponPost/', {'coupon_code': LIMIT_COUPON})
        qid = sql("SELECT MAX(entity_id) FROM quote")
        return dict(coupon_post=resp.get('http'), quote_coupon=sql("SELECT coupon_code FROM quote WHERE entity_id=%s" % qid),
                    counters=counters(LIMIT_COUPON))
    try:
        out['before'] = counters(LIMIT_COUPON)
        one('registered_crash_then_recover', registered_crash)
        one('registered_second_use_refused', registered_again)
        one('guest_abort_after_submit', guest_abort)
        one('guest_success', guest_success)
        one('guest_third_use_refused', guest_refused)
        record('coupon_fix_limits', None, steps=steps, reservations=r.res(), **out)
    finally:
        stub('localdev_pf_sales_quote_save_after', None)


MSI_SKU, MSI_PRODUCT = 'APLAZO-1664-MSI', 'aplazo-1664-msi.html'
MSI_QUEUE = 'inventory.reservations.updateSalabilityStatus'
INVENTORY_QUEUES = ('inventory.reservations.updateSalabilityStatus', 'inventory.indexer.sourceItem', 'inventory.indexer.stock',
                    'inventory.reservations.update', 'inventory.reservations.cleanup', 'inventory.source.items.cleanup',
                    'inventory.mass.update')


def msi_state(*orders, sku=MSI_SKU):
    with open(os.path.join(HERE, 'msi_state.php')) as fh:
        out = run(['bin/clinotty', 'php', '--', sku] + list(orders), stdin=fh)
    return json.loads(out[out.index('{'):]) if '{' in out else {'raw': out.strip()[-300:]}


def innodb_state():
    """Open InnoDB transactions and lock waits, read-only, as the database container's root (the application user has no
    PROCESS privilege; none is granted)."""
    q = ("SELECT trx_id, trx_state, trx_started, trx_rows_locked, trx_rows_modified, trx_tables_locked, "
         "LEFT(COALESCE(trx_query,''),60) FROM information_schema.INNODB_TRX ORDER BY trx_started; "
         "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS")
    out = subprocess.run(['docker', 'exec', os.environ.get('DB_CONTAINER', 'magento248-db-1'), 'sh', '-c',
                          'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e "%s"' % q.replace('"', '\\"')],
                         capture_output=True, text=True).stdout.strip().splitlines()
    cols = ('trx_id', 'state', 'started', 'rows_locked', 'rows_modified', 'tables_locked', 'query')
    trx = [dict(zip(cols, l.split('\t'))) for l in out[:-1] if '\t' in l]
    return {'transactions': trx, 'lock_waits': int(out[-1]) if out and out[-1].isdigit() else None}


def inventory_queues():
    return {q: rabbit_count(q) for q in INVENTORY_QUEUES}


def consume_msi(n):
    """Magento's own consumer for the salability topic, exactly n messages (only kit messages are queued)."""
    if not n:
        return {'consumed': 0, 'output': 'nothing to consume'}
    t0 = time.time()
    out = run(['bin/clinotty', 'bin/magento', 'queue:consumers:start', MSI_QUEUE, '--max-messages=%d' % n, '--single-thread'], timeout=240)
    return {'consumed': n, 'seconds': round(time.time() - t0, 1), 'output': out.strip()[-200:] or 'ok'}


def msi_ready():
    """Exactly one salable unit of the synthetic product: default source quantity = 1 + units still reserved by earlier
    kit orders (SourceItemsSave, as the 'stock' kit command; MSI keeps the legacy stock row in step synchronously)."""
    php('order_ops.php', 'product', MSI_SKU, 1)
    s = msi_state()
    reserved = -float(s['reservations']['sum'])
    out = php('order_ops.php', 'stock', MSI_SKU, int(1 + reserved))
    after_stock = msi_state()
    reindexed = None
    # Indexers are "Update by Schedule" and Magento's cron is off: if the stock row did not follow the source quantity,
    # the setup reindexes the two stock indexers (documented in the record; never between the steps under test).
    status = after_stock.get('legacy_stock_status') or {}
    if after_stock.get('salable_qty') != 1 or str(status.get('stock_status')) != '1':
        reindexed = magento('indexer:reindex', 'cataloginventory_stock', 'inventory').strip()[-200:]
    magento('cache:clean', 'full_page')
    return dict(set_source_qty=int(1 + reserved), stock_command=out.get('ok'), state_after_stock_command=after_stock,
                setup_reindex=reindexed, state=msi_state())


def t_msi_drain():
    """Consumes, with Magento's consumer, the salability messages left queued by earlier kit runs (all kit SKUs; listed
    without consuming beforehand). Records each SKU's stored and computed salability before and after."""
    skus = [r['sku'] for r in rows("SELECT DISTINCT sku FROM inventory_reservation WHERE sku LIKE 'APLAZO-%' ORDER BY sku", ('sku',))]
    def states():
        res = {}
        for sku in skus:
            st = rows("SELECT s.stock_status, s.qty FROM cataloginventory_stock_status s JOIN catalog_product_entity e ON e.entity_id=s.product_id "
                      "WHERE e.sku='%s' AND s.stock_id=1" % sku, ('stock_status', 'qty'))
            reserved = sql("SELECT COALESCE(SUM(quantity),0) FROM inventory_reservation WHERE sku='%s' AND stock_id=1" % sku)
            res[sku] = dict(st[0] if st else {}, reservations=reserved)
        return res
    before, q = states(), rabbit_count(MSI_QUEUE)
    cons = consume_msi(q or 0)
    record('msi_backlog_drain', None, skus=skus, queued_before=q, consumer=cons, before=before, after=states(),
           queued_after=rabbit_count(MSI_QUEUE))


def t_msi(mode):
    """Salability message of a payment-first conversion, synthetic product APLAZO-1664-MSI with one salable unit.
    mode: abort_then_consume (definitive link failure after submit, synthetic SQL; consumer after the rollback) |
    success_then_consume (consumer after the commit) | pause_consume_commit / pause_consume_abort (the conversion is held
    by the kit at sales_quote_save_after, after submit wrote the reservation and published the message and before the
    commit; Magento's consumer runs during the hold; then the conversion commits, or aborts by synthetic SQL)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    steps = []
    def step(label, **extra):
        e = dict(step=label, state=msi_state(*([a['cart_id']] if a else [])), innodb=innodb_state(), queues=inventory_queues(), **extra)
        steps.append(e)
        return e
    a = None
    try:
        ready = msi_ready()
        step('ready', setup={k: ready[k] for k in ('set_source_qty', 'stock_command', 'state_after_stock_command', 'setup_reindex')})
        add_to_cart(b.ws, MSI_PRODUCT, MSI_SKU)
        ok, a = open_popup(b, plan='stay', add=False)
        loan(a['cart_id'], 'OUTSTANDING')
        step('attempt_open')
        def interfere():
            sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
            return 'SYNTHETIC: status set to needs_review by direct SQL'
        if mode == 'abort_then_consume':
            held = hold_conversion_and(a, interfere)
            e = step('after_rollback', result={k: held.get(k) for k in ('inside', 'webhook')})
            cons = consume_msi(e['queues'][MSI_QUEUE] or 0)
            step('after_consumer', consumer=cons)
        elif mode == 'success_then_consume':
            res = webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
            e = step('after_commit', result=res)
            cons = consume_msi(e['queues'][MSI_QUEUE] or 0)
            step('after_consumer', consumer=cons)
        else:
            stub('localdev_pf_sales_quote_save_after', 'pause:45')
            t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
            inside = wait_inside('sales_quote_save_after', 40)
            e = step('paused_before_commit', inside=bool(inside))
            cons = consume_msi(e['queues'][MSI_QUEUE] or 0)
            still = sh('test -f var/localdev_pf_sales_quote_save_after.inside && echo y').strip() == 'y'
            step('after_consumer_during_hold', consumer=cons, conversion_still_held=still)
            if mode == 'pause_consume_abort':
                interfere()
            t.join(120)
            stub('localdev_pf_sales_quote_save_after', None)
            e = step('after_conversion_finished', result=(box.get('v') or {}).get('body'), aborted_by_kit=mode == 'pause_consume_abort')
            if e['queues'][MSI_QUEUE]:
                step('after_late_consumer', consumer=consume_msi(e['queues'][MSI_QUEUE]))
            if mode == 'pause_consume_commit_followup':
                # Consequences, without any intervention: nothing else is queued or scheduled to correct the row.
                time.sleep(60)
                magento('cache:clean', 'full_page')
                page = run(['curl', '-sk', BASE + '/' + MSI_PRODUCT])
                shown = 'Out of stock' if 'Out of stock' in page else ('In stock' if 'In stock' in page else 'unknown')
                other = Browser()
                try:
                    other.ws.send('Page.navigate', {'url': BASE + '/' + MSI_PRODUCT})
                    wait_for(other.ws, "document.readyState==='complete'", 60); time.sleep(2)
                    button = evaluate(other.ws, "!!document.querySelector('#product-addtocart-button:not([disabled])')")
                    if button:
                        evaluate(other.ws, "document.querySelector('#product-addtocart-button').click(); true"); time.sleep(6)
                    msgs = evaluate(other.ws, "Array.from(document.querySelectorAll('.message-error, .message-success, .mage-error'))"
                                              ".map(e => e.innerText.trim()).filter(Boolean).join(' | ')")
                finally:
                    other.quit()
                step('stale_60s_later_no_intervention', storefront_shows=shown, add_to_cart_button=bool(button), storefront_messages=msgs)
                # Documented correction probe, after everything above was recorded: a full rebuild of the stock indexers.
                out = magento('indexer:reindex', 'cataloginventory_stock', 'inventory').strip()[-200:]
                step('after_documented_reindex', reindex=out)
        record('msi_%s' % mode, None, cart_id=a['cart_id'], attempt_id=a['entity_id'], final_attempt=attempt(a['entity_id'])['status'],
               steps=steps, reservations=r.res(MSI_SKU))
    finally:
        stub('localdev_pf_sales_quote_save_after', None)
        b.quit()


def rabbit_consumers(queue):
    container = os.environ.get('RABBITMQ_CONTAINER')
    if not container:
        return None
    out = subprocess.run(['docker', 'exec', container, 'rabbitmqctl', 'list_queues', 'name', 'consumers'],
                         capture_output=True, text=True).stdout
    for line in out.splitlines():
        parts = line.split()
        if len(parts) == 2 and parts[0] == queue:
            return int(parts[1])
    return None


def stop_waiting_msi_consumer():
    """Stops a salability consumer the kit started and that is still waiting (only that command line, in the PHP
    container), so it cannot take messages of a later step."""
    subprocess.run(['docker', 'exec', os.environ.get('PHP_CONTAINER', 'magento248-phpfpm-1'), 'pkill', '-f',
                    'queue:consumers:start ' + MSI_QUEUE], capture_output=True)


def salability_probes(since):
    return [{k: v for k, v in c.items() if k != 'at'} for c in stub_calls_since(since)
            if (c.get('service') == 'probe' and c.get('method') == 'salability_publish') or c.get('service') == 'sync'
            or (c.get('service') == 'log' and 'salability' in (c.get('message') or ''))]


def msi_module_log(since_line):
    return [l for l in sh('tail -n +%d var/log/aplazo_payment/info.log 2>/dev/null' % (since_line + 1)).splitlines()
            if 'salability' in l]


def t_msi_fix(mode):
    """Deferred salability message (DeferredSalabilityPublisher) with the synthetic product APLAZO-1664-MSI and one
    salable unit. mode: abort_then_consume | success_then_consume | pause_consumer_waiting_commit (Magento's consumer is
    started and waiting while the kit holds the conversion before its commit) | pause_abort | crash_recover (kit crash
    inside the conversion transaction, reconciliation, then a repeated finalization) | publish_exception / publish_error
    (kit failure in the queue publisher after the commit; then the native recovery checks)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    steps = []
    a = None
    log0 = int((sh('wc -l < var/log/aplazo_payment/info.log 2>/dev/null').strip() or '0'))
    pos = [stub_log_len()]
    def step(label, **extra):
        e = dict(step=label, state=msi_state(*([a['cart_id']] if a else [])), innodb=innodb_state(),
                 queue=rabbit_count(MSI_QUEUE), consumers=rabbit_consumers(MSI_QUEUE), observed=salability_probes(pos[0]), **extra)
        pos[0] = stub_log_len()
        steps.append(e)
        return e
    def consume_queued(label):
        n = rabbit_count(MSI_QUEUE) or 0
        return step(label, consumer=consume_msi(n))
    try:
        ready = msi_ready()
        step('ready', setup={k: ready[k] for k in ('set_source_qty', 'stock_command', 'setup_reindex')})
        add_to_cart(b.ws, MSI_PRODUCT, MSI_SKU)
        ok, a = open_popup(b, plan='stay', add=False)
        loan(a['cart_id'], 'OUTSTANDING')
        step('attempt_open')
        def interfere():
            sql("UPDATE aplazo_payment_attempt SET status='needs_review', last_error='kit_synthetic_definitive', version=version+1 WHERE entity_id=%s" % a['entity_id'])
            return 'SYNTHETIC: status set to needs_review by direct SQL'
        hook = lambda: webhook(a['cart_id'], 'stub-' + a['cart_id']).get('body')
        if mode == 'abort_then_consume':
            held = hold_conversion_and(a, interfere)
            step('after_rollback', result={k: held.get(k) for k in ('inside', 'webhook')})
            consume_queued('after_consumer')
        elif mode == 'success_then_consume':
            step('after_commit', result=hook())
            consume_queued('after_consumer')
        elif mode in ('pause_consumer_waiting_commit', 'pause_abort'):
            stub('localdev_pf_sales_quote_save_after', 'pause:45')
            t, box = in_thread(webhook, a['cart_id'], 'stub-' + a['cart_id'])
            inside = wait_inside('sales_quote_save_after', 40)
            step('paused_before_commit', inside=bool(inside))
            ct = cbox = None
            if mode == 'pause_consumer_waiting_commit':
                ct, cbox = in_thread(consume_msi, 1)
                connected = wait_until(lambda: (rabbit_consumers(MSI_QUEUE) or 0) > 0, 30)
                still = sh('test -f var/localdev_pf_sales_quote_save_after.inside && echo y').strip() == 'y'
                step('consumer_waiting_during_hold', consumer_connected=bool(connected), conversion_still_held=still)
            else:
                interfere()
            t.join(120)
            stub('localdev_pf_sales_quote_save_after', None)
            if ct:
                ct.join(120)
                step('after_commit_consumer_done', result=(box.get('v') or {}).get('body'), consumer=(cbox or {}).get('v'),
                     consumer_thread_alive=ct.is_alive())
            else:
                step('after_abort', result=(box.get('v') or {}).get('body'))
                consume_queued('after_consumer')
        elif mode == 'crash_recover':
            stub('localdev_pf_sales_quote_save_after', 'crash')
            res = str(hook())[:160]
            stub('localdev_pf_sales_quote_save_after', None)
            step('after_crash', result=res)
            step('after_recovery', result=reconcile(a['entity_id']))
            consume_queued('after_consumer')
            res = dict(webhook=hook(), reconcile=reconcile(a['entity_id']))
            step('after_repeat', result=res)
            consume_queued('after_repeat_consumer')
        else:
            stub('localdev_pf_msi_publish_fault', 'error' if mode == 'publish_error' else 'exception')
            res = hook()
            stub('localdev_pf_msi_publish_fault', None)
            step('after_commit_publish_failed', result=res, module_log=msi_module_log(log0), attempt_row=attempt(a['entity_id'])['status'])
            consume_queued('after_consumer_nothing_queued')
            # Native recovery checks, in order, each recorded: a full rebuild of the stock indexers, then the order's
            # shipment (MSI deducts the source and adds the shipment reservation, and publishes a salability message
            # for the SKU outside any conversion).
            step('after_reindex', reindex=magento('indexer:reindex', 'cataloginventory_stock', 'inventory').strip()[-160:])
            o = r.orders()
            oid = [x for x in o if x['increment_id'] == a['cart_id']][0]['entity_id']
            step('after_shipment', ship=php('order_ops.php', 'ship', oid))
            consume_queued('after_shipment_consumer')
        verdict = None
        if mode in ('publish_exception', 'publish_error'):
            # Since the review of 2026-11-24 the module applies the failed message in process (MSI's own handler):
            # right after the failure, before any consumer, the sold last unit is no longer salable in the stock index.
            failed = [x for x in steps if x['step'] == 'after_commit_publish_failed'][0]
            view = (failed['state'].get('stock_index_view') or {})
            verdict = (str(view.get('is_salable')) == '0' and failed['attempt_row'] == 'completed'
                       and 'applied in process' in json.dumps(failed['module_log']))
        record('msi_fix_%s' % mode, verdict, cart_id=a['cart_id'], attempt_id=a['entity_id'],
               final_attempt=attempt(a['entity_id'])['status'],
               orders_for_cart=len([x for x in r.orders() if x['increment_id'] == a['cart_id']]), steps=steps,
               reservations=r.res(MSI_SKU))
    finally:
        for f in ('localdev_pf_sales_quote_save_after', 'localdev_pf_msi_publish_fault'):
            stub(f, None)
        stop_waiting_msi_consumer()
        b.quit()


def t_msi_fix_redirect():
    """Redirect checkout with the synthetic product: the salability message must be published as before (at once, outside
    any conversion), and its consumer must leave the last unit not salable."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    steps = []
    pos = [stub_log_len()]
    inc = None
    def step(label, **extra):
        e = dict(step=label, state=msi_state(*([inc] if inc else [])), queue=rabbit_count(MSI_QUEUE),
                 observed=salability_probes(pos[0]), **extra)
        pos[0] = stub_log_len()
        steps.append(e)
        return e
    try:
        website_mode('redirect')
        ready = msi_ready()
        step('ready', setup={k: ready[k] for k in ('set_source_qty', 'stock_command', 'setup_reindex')})
        add_to_cart(b.ws, MSI_PRODUCT, MSI_SKU); to_payment(b.ws)
        click_place_order(b.ws)
        o = wait_until(lambda: r.orders(), 60)
        inc = o[0]['increment_id'] if o else None
        step('after_place_order', attempts=len(r.attempts()))
        step('after_consumer', consumer=consume_msi(rabbit_count(MSI_QUEUE) or 0))
        record('msi_fix_redirect', None, increment_id=inc, steps=steps, reservations=r.res(MSI_SKU))
    finally:
        website_mode('popup')
        b.quit()


def start_state(r, quote_id):
    rows_ = rows("SELECT entity_id, cart_id, status, last_error, next_check_at, loan_id, fingerprint FROM aplazo_payment_attempt "
                 "WHERE quote_id=%d ORDER BY entity_id" % int(quote_id),
                 ('entity_id', 'cart_id', 'status', 'last_error', 'next_check_at', 'loan_id', 'fingerprint'))
    for x in rows_:
        x['fingerprint'] = x['fingerprint'][:12]
    return dict(attempts_for_quote=len(rows_), attempts=rows_, create_loan_calls=len(r.calls('createLoan')),
                create_loan_cart_ids=[c.get('cartId') for c in r.calls('createLoan')])


def select_aplazo(ws):
    evaluate(ws, "require(['Magento_Checkout/js/action/set-payment-information'], f => f(null, {method: 'aplazo_gateway'})); true")
    time.sleep(3)


def t_uncertain_start(mode):
    """An earlier start whose outcome is unknown blocks another loan for the quote. mode: uncertain_reclick (createLoan
    fails, then the same click again) | uncertain_cart_change (createLoan fails, the cart changes, click again) |
    starting_interrupted (the PHP process dies during createLoan: the attempt stays starting; click again, then change the
    cart and click again) | normal_and_reuse (a normal start opens; the same click reuses the open attempt). The start
    endpoint (POST aplazo/popup/start) is called as the payment button does, with the form key."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    steps = []
    try:
        add_to_cart(b.ws); ok = to_payment(b.ws); select_aplazo(b.ws)
        qid = int(sql("SELECT quote_id FROM aplazo_payment_attempt ORDER BY entity_id DESC LIMIT 1") or 0)
        qid = int(sql("SELECT MAX(entity_id) FROM quote WHERE is_active=1 AND reserved_order_id IS NULL") or 0)
        steps.append(dict(step='before', quote_id=qid, **start_state(r, qid)))
        if mode in ('uncertain_reclick', 'uncertain_cart_change'):
            stub('localdev_aplazo_stub_createloan', 'fail')
        elif mode == 'starting_interrupted':
            stub('localdev_aplazo_stub_createloan', 'crash')
        first = post(b.ws, '/aplazo/popup/start')
        stub('localdev_aplazo_stub_createloan', None)   # from here a createLoan call would succeed
        steps.append(dict(step='first_start', response=first.get('body') if isinstance(first.get('body'), dict) else str(first)[:200],
                          **start_state(r, qid)))
        second = post(b.ws, '/aplazo/popup/start')
        steps.append(dict(step='second_start_same_cart', response=second.get('body'), **start_state(r, qid)))
        if mode in ('uncertain_cart_change', 'starting_interrupted'):
            add_to_cart(b.ws); to_payment(b.ws); select_aplazo(b.ws)
            items = sql("SELECT CONCAT(items_count,' items, qty ',items_qty) FROM quote WHERE entity_id=%d" % qid)
            third = post(b.ws, '/aplazo/popup/start')
            steps.append(dict(step='start_after_cart_change', quote=items, response=third.get('body'), **start_state(r, qid)))
        if mode != 'normal_and_reuse':
            # The attempt is kept for the reconciliation, which checks it (the stub loan is not paid).
            a0 = steps[-1]['attempts'][0]
            steps.append(dict(step='reconcile_keeps_it', reconcile=reconcile(a0['entity_id']), **start_state(r, qid)))
        record('uncertain_start_%s' % mode, None, quote_id=qid, steps=steps, orders=len(r.orders()), reservations=r.res())
    finally:
        stub('localdev_aplazo_stub_createloan', None)
        b.quit()


OTHER_METHOD_JS = r"""
(async (method) => {
  const c = window.checkoutConfig;
  const path = '/rest/' + c.storeCode + '/V1/guest-carts/' + c.quoteData.entity_id + '/payment-information';
  const r = await fetch(path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({cartId: c.quoteData.entity_id, email: 'pf-test@example.invalid', paymentMethod: {method}})});
  let t = await r.text(); try { t = JSON.parse(t); } catch (e) { t = t.slice(0, 200); }
  return {http: r.status, body: t};
})
"""


def order_methods(r):
    return rows('SELECT o.increment_id, p.method FROM sales_order o JOIN sales_order_payment p ON p.parent_id=o.entity_id '
                'WHERE o.entity_id > %d ORDER BY o.entity_id' % r.o, ('increment_id', 'method'))


def t_place_other(mode):
    """The popup attempt is left open, the buyer reloads the checkout (the page's block is gone) and places the order with
    another method (check / money order, the same REST call Luma's Place Order makes). SettlePaidAttemptsBeforePlaceOrder
    settles the attempt first. mode: paid (loan OUTSTANDING: the attempt becomes the Aplazo order and the other order is
    refused) | unpaid (REQUEST: the other order is placed; a later payment of the loan ends rejected, the residual risk) |
    unknown (status API fails: the other order is placed) | busy (the webhook is converting the paid attempt right now and
    placeOrder is called with the method as a parameter, so it reaches the guard: refused as busy, the webhook's order
    stands) | busy_checkout (same hold, through the checkout's REST call: refused by whichever check meets it first, the
    quote guard, the core cart lock or the inactive quote; no other order)."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, plan='stay')
        if mode in ('paid', 'busy', 'busy_checkout'):
            loan(a['cart_id'], 'OUTSTANDING')
        elif mode == 'unknown':
            loan(a['cart_id'], 'ERROR')
        b.ws.send('Page.navigate', {'url': BASE + '/checkout/'})
        wait_for(b.ws, "!!(window.checkoutConfig && window.checkoutConfig.quoteData && document.querySelector('#checkout-step-shipping, #checkout-payment-method-load'))", 90)
        time.sleep(2)
        place = lambda: evaluate(b.ws, '(%s)(%s)' % (OTHER_METHOD_JS, json.dumps('checkmo')), timeout=150)
        hold = None
        if mode == 'busy':
            box = {}
            hold = hold_conversion_and(a, lambda: box.update(v=php('order_ops.php', 'place_checkmo', a['quote_id'])) or 'placed during hold')
            answer = box.get('v')
        elif mode == 'busy_checkout':
            box = {}
            hold = hold_conversion_and(a, lambda: box.update(v=place()) or 'placed during hold')
            answer = box.get('v')
        else:
            answer = place()
        x = attempt(a['entity_id'])
        methods = order_methods(r)
        msg = json.dumps(answer)
        later = None
        if mode == 'paid':
            good = (ok and answer['http'] >= 400 and a['cart_id'] in msg and methods == [dict(increment_id=a['cart_id'], method='aplazo_gateway')]
                    and x['status'] == 'completed' and quote(a['quote_id'])['is_active'] == '0')
        elif mode == 'busy':
            good = (ok and hold['inside'] and answer.get('ok') is False and 'confirming an Aplazo payment' in msg
                    and methods == [dict(increment_id=a['cart_id'], method='aplazo_gateway')] and x['status'] == 'completed')
        elif mode == 'busy_checkout':
            # Refused whichever way the timing falls: KeepConvertedQuoteClosed on the quote save ("No such entity with
            # cartId"), the core cart lock still held by the conversion, or the inactive quote after it.
            good = (ok and hold['inside'] and answer['http'] >= 400
                    and methods == [dict(increment_id=a['cart_id'], method='aplazo_gateway')] and x['status'] == 'completed')
        else:
            placed = [m for m in methods if m['method'] == 'checkmo']
            good = (ok and answer['http'] == 200 and len(methods) == 1 and len(placed) == 1 and x['status'] == 'open')
            if mode == 'unpaid':
                loan(a['cart_id'], 'OUTSTANDING')
                rec = reconcile(a['entity_id'])
                later = dict(reconcile=rec, attempt=attempt(a['entity_id']))
                good = good and later['attempt']['status'] == 'rejected' and later['attempt']['reject_reason'] == 'quote_not_active'
        record('place_other_%s' % mode, good, answer=answer, attempt=x, orders=methods, hold=hold, later=later,
               status_calls=[c.get('cartId') for c in r.calls('getLoanStatus')])
    finally:
        b.quit()


# ---------- 2026-11-25: abandonment, recovery and communication failures (scenarios A-G) ----------
OVERLAY_ACTION_JS = "(() => { const b = document.querySelector('.aplazo-popup-overlay__action'); if (!b || b.hidden) return false; b.click(); return true; })()"
CART_ROWS_JS = "document.querySelectorAll('#shopping-cart-table tbody.cart.item').length"


def cookie_meta(b):
    """Names and persistence of magento.test's cookies, never their values."""
    out = b.ws.send('Network.getCookies', {'urls': [BASE + '/']}).get('cookies', [])
    return sorted('%s:%s' % (c['name'], 'session' if c.get('session') else 'persistent') for c in out)


def cart_rows(ws):
    ws.send('Page.navigate', {'url': BASE + '/checkout/cart/'})
    wait_for(ws, "document.readyState==='complete' && !!document.querySelector('.cart-empty, #shopping-cart-table')", 60)
    time.sleep(1)
    return evaluate(ws, CART_ROWS_JS)


def latest_quote():
    return int(sql('SELECT MAX(entity_id) FROM quote') or 0)


def wait_success(b, timeout=60):
    return wait_until(lambda: '/checkout/onepage/success' in (page_state(b.ws) or {}).get('path', ''), timeout)


def status_calls(r, cart_id):
    return len([c for c in r.calls('getLoanStatus') if c.get('cartId') == cart_id])


def mock_loads(cart_id):
    return len([h for h in mock_hits() if h.get('kind') == 'page' and h['query'].get('quoteid') == 'stub-' + cart_id])


def t_ab_restart(mode, graceful):
    """A. The whole Chrome is closed (graceful: SIGTERM; else SIGKILL, a crash) and reopened on the SAME profile.
    mode: before_aplazo (checkout filled, Aplazo never opened: no attempt, no loan; the cart is still there after
    reopening and the purchase goes on with the same quote) | attempt_unpaid (Aplazo opened, attempt open, loan not paid:
    after reopening the next Aplazo click reuses that attempt and its loan) | attempt_paid_webhook (the loan is paid while
    Chrome is closed and the webhook arrives: the order exists before the buyer comes back, the cart is empty) |
    attempt_paid_nosignal (paid while closed, no webhook or cron yet: the buyer comes back to a full cart; the next
    Aplazo click reuses the paid attempt and the check creates the order, once)."""
    r = Run(); P = tempfile.mkdtemp(prefix='bnpl1664pf-keep-')
    b = Browser(profile=P); default_loan('REQUEST')
    data = dict(graceful=graceful)
    try:
        if mode == 'before_aplazo':
            add_to_cart(b.ws); ok = to_payment(b.ws, email='pf-restart@example.invalid', street='Calle Cierre 1')
            qid = latest_quote()
            data['before_close'] = dict(attempts=len(r.attempts()), create_loan=len(r.calls('createLoan')), quote=quote(qid),
                                        cookies=cookie_meta(b))
            time.sleep(2)
            b.stop(graceful); b = Browser(profile=P)
            data['cookies_after'] = cookie_meta(b)
            data['cart_rows_after'] = cart_rows(b.ws)
            data['after_reopen'] = dict(attempts=len(r.attempts()), create_loan=len(r.calls('createLoan')), quote=quote(qid))
            persisted = data['cart_rows_after'] == 1
            if persisted:
                default_loan('OUTSTANDING')
                to_payment(b.ws, email='pf-restart@example.invalid', street='Calle Cierre 1'); mock_plan('success')
                click_place_order(b.ws)
                a = wait_attempt(r.a, status=('open', 'completed'))
                data['success'] = bool(wait_success(b))
                data['attempt'] = attempt(a['entity_id']) if a else None
            o = r.orders(); data['orders'] = o; data['reservations'] = r.res()
            good = (ok and data['before_close']['attempts'] == 0 and data['before_close']['create_loan'] == 0
                    and data['after_reopen']['attempts'] == 0 and data['after_reopen']['create_loan'] == 0
                    and (persisted or not graceful))
            if persisted:
                good = (good and data['attempt'] and data['attempt']['quote_id'] == str(qid) and data['success'] and len(o) == 1
                        and o[0]['quote_id'] == str(qid) and o[0]['invoices'] == '1' and len(data['reservations']) == 1)
            data['cart_persisted'] = persisted
        else:
            ok, a = open_popup(b, email='pf-restart@example.invalid', plan='stay', street='Calle Cierre 2')
            data['attempt_before'] = a
            time.sleep(2)
            b.stop(graceful)
            if mode in ('attempt_paid_webhook', 'attempt_paid_nosignal'):
                loan(a['cart_id'], 'OUTSTANDING')
            if mode == 'attempt_paid_webhook':
                data['webhook'] = webhook(a['cart_id'], 'stub-' + a['cart_id'])
                data['orders_while_closed'] = r.orders()
            b = Browser(profile=P)
            data['cookies_after'] = cookie_meta(b)
            data['cart_rows_after'] = cart_rows(b.ws)
            data['quote_after_reopen'] = quote(a['quote_id'])
            if mode == 'attempt_paid_webhook':
                o = r.orders()
                good = (ok and data['webhook']['body'].get('status') is True and len(data['orders_while_closed']) == 1
                        and data['cart_rows_after'] == 0 and data['quote_after_reopen']['is_active'] == '0' and len(o) == 1
                        and o[0]['invoices'] == '1' and len(r.res()) == 1 and len(r.calls('createLoan')) == 1)
            elif data['cart_rows_after'] == 0:
                # The browser lost its session (crash before Chrome wrote its cookies): the buyer starts over. Nothing
                # must be created; the attempt stays open under reconciliation, its quote untouched.
                data['attempt_after'] = attempt(a['entity_id'])
                good = (ok and not graceful and not r.orders() and len(r.calls('createLoan')) == 1
                        and data['attempt_after']['status'] == 'open' and data['quote_after_reopen']['is_active'] == '1')
                if mode == 'attempt_paid_nosignal':
                    data['reconcile'] = reconcile(a['entity_id'])
                    o = r.orders()
                    good = (ok and not graceful and data['reconcile'] and data['reconcile'][0]['result'] == 'completed'
                            and len(o) == 1 and o[0]['invoices'] == '1')
                data['cart_lost'] = True
            else:
                loads = mock_loads(a['cart_id'])
                if mode == 'attempt_unpaid':
                    loan(a['cart_id'], 'OUTSTANDING')
                to_payment(b.ws, email='pf-restart@example.invalid', street='Calle Cierre 2'); mock_plan('close')
                click_place_order(b.ws)
                data['success'] = bool(wait_success(b))
                data['attempts'] = [x['entity_id'] + ':' + x['status'] for x in r.attempts()]
                data['same_url_loads'] = mock_loads(a['cart_id']) - loads
                o = r.orders()
                good = (ok and data['cart_rows_after'] == 1 and data['quote_after_reopen']['is_active'] == '1'
                        and data['attempts'] == [a['entity_id'] + ':completed'] and len(r.calls('createLoan')) == 1
                        and data['same_url_loads'] == 1 and data['success'] and len(o) == 1 and o[0]['attempt'] == a['public_id']
                        and o[0]['invoices'] == '1' and len(r.res()) == 1)
            data['orders'] = r.orders(); data['reservations'] = r.res()
        record('ab_restart_%s_%s' % (mode, 'graceful' if graceful else 'crash'), good, **data)
    finally:
        b.quit()


def t_ab_wait(mode):
    """B. Aplazo's window stays open before the buyer goes on. wait_then_pay: a bounded 45 s wait (longer than the
    page's whole check cycle, 1 + 8 checks every 3 s, so any timer-driven request would show) creates nothing and asks
    nothing; then the payment completes once. expired: the stub lists the loan as CANCELLED (stands for an expired or
    cancelled loan; STG's real expiry status and timing are contract C3, unknown): the close gives the checkout back;
    a new Aplazo click must not send the buyer to that dead loan again."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, email='pf-wait@example.invalid', plan='stay', street='Calle Espera 1')
        if mode == 'wait_then_pay':
            n0 = status_calls(r, a['cart_id']); t0 = time.time()
            time.sleep(45)
            during = dict(seconds=round(time.time() - t0), status_calls=status_calls(r, a['cart_id']) - n0,
                          create_loan=len(r.calls('createLoan')), attempts=len(r.attempts()), orders=len(r.orders()),
                          popup_open=len(b.popups()))
            loan(a['cart_id'], 'OUTSTANDING'); b.close_popups()
            success = bool(wait_success(b)); o = r.orders()
            good = (ok and during['status_calls'] == 0 and during['create_loan'] == 1 and during['attempts'] == 1
                    and during['orders'] == 0 and during['popup_open'] == 1 and success and len(o) == 1
                    and o[0]['invoices'] == '1' and len(r.res()) == 1 and len(r.calls('createLoan')) == 1)
            record('ab_wait_then_pay', good, during=during, success=success, orders=o, reservations=r.res())
        else:
            loan(a['cart_id'], 'CANCELLED'); b.close_popups()
            st = wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste', timeout=60)
            x1 = attempt(a['entity_id'])
            loads = mock_loads(a['cart_id'])
            mock_plan('stay'); click_place_order(b.ws); time.sleep(6)
            again = dict(attempts=[x['entity_id'] + ':' + x['status'] for x in r.attempts()],
                         create_loan=len(r.calls('createLoan')), dead_url_loads=mock_loads(a['cart_id']) - loads,
                         popups=len(b.popups()))
            good = (ok and st and not r.orders() and again['dead_url_loads'] == 0)
            record('ab_wait_expired_loan', good, page_after_close=st, attempt_after_close=x1, again=again,
                   note='expected: an Aplazo click after the loan is reported CANCELLED must not reopen that loan')
    finally:
        b.quit()


def t_ab_close_processing(mode):
    """C. The window is closed while the payment is still processing (status REQUEST). paid_during_checks: Aplazo
    reports it paid during the page's checks after the close: the page finds it and goes to success, one order.
    paid_after_checks: the checks end with not_paid (the checkout is given back), then the loan becomes paid and the
    reconciliation (as the cron) creates the order: one order, the cart converted; the page told the buyer "not paid"."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        ok, a = open_popup(b, email='pf-proc@example.invalid', plan='stay', street='Calle Proceso 1')
        b.close_popups()
        if mode == 'paid_during_checks':
            wait_until(lambda: status_calls(r, a['cart_id']) >= 3, 30)
            seen = status_calls(r, a['cart_id'])
            loan(a['cart_id'], 'OUTSTANDING')
            success = bool(wait_success(b)); o = r.orders()
            good = ok and seen >= 3 and success and len(o) == 1 and o[0]['invoices'] == '1' and len(r.res()) == 1
            record('ab_close_paid_during_checks', good, status_calls_before_paid=seen, success=success, orders=o,
                   reservations=r.res(), attempt=attempt(a['entity_id']))
        else:
            st = wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste', timeout=60)
            given_back = dict(page=st, orders=len(r.orders()), quote=quote(a['quote_id']), attempt=attempt(a['entity_id']))
            loan(a['cart_id'], 'OUTSTANDING')
            rec = reconcile(a['entity_id']); o = r.orders()
            good = (ok and st and st['placeOrderDisabled'] is False and given_back['orders'] == 0
                    and given_back['attempt']['status'] == 'open' and rec and rec[0]['result'] == 'completed' and len(o) == 1
                    and o[0]['invoices'] == '1' and len(r.res()) == 1 and quote(a['quote_id'])['is_active'] == '0')
            record('ab_close_paid_after_checks', good, given_back=given_back, reconcile=rec, orders=o, reservations=r.res(),
                   quote_after=quote(a['quote_id']))
    finally:
        b.quit()


def t_ab_reopen(mode):
    """E. Close and choose Aplazo again. changed_qty: the buyer closes unpaid, adds a second unit and clicks Aplazo:
    a new attempt and a new loan for the new amount; the earlier attempt is superseded (never reused for another
    amount); the new one is paid: one order for 2 units. Then the EARLIER loan is paid late: it can never become a second
    order (it ends rejected, its reversal is the kit's stub). double_click: two clicks in a row on the same tab: one
    attempt, one loan, one window."""
    r = Run(); b = Browser(); default_loan('REQUEST')
    try:
        if mode == 'changed_qty':
            ok, a = open_popup(b, email='pf-reopen@example.invalid', plan='close', street='Calle Reabrir 1')
            st = wait_notice(b.ws, 'closed the Aplazo window', 'Cerraste', timeout=60)
            add_to_cart(b.ws); to_payment(b.ws, email='pf-reopen@example.invalid', street='Calle Reabrir 1')
            mock_plan('stay'); click_place_order(b.ws)
            a2 = wait_attempt(int(a['entity_id']), status=('open', 'uncertain'))
            wait_until(lambda: b.popups(), 15); time.sleep(1.5)
            old = attempt(a['entity_id'])
            loan(a2['cart_id'], 'OUTSTANDING'); b.close_popups()
            success = bool(wait_success(b)); o = r.orders()
            loan(a['cart_id'], 'OUTSTANDING')
            late = reconcile(a['entity_id']); old_after = attempt(a['entity_id'])
            good = (ok and st and a2 and a2['entity_id'] != a['entity_id'] and old['status'] == 'superseded'
                    and len(r.calls('createLoan')) == 2 and success and len(o) == 1 and o[0]['attempt'] != a['public_id']
                    and order_items_qty(o[0]['entity_id']) == '2.0000' and len(r.orders()) == 1
                    and old_after['status'] == 'rejected')
            record('ab_reopen_changed_qty', good, first=a, second=a2, first_after_second=old, success=success, orders=o,
                   late_payment_of_first=late, first_after_late_payment=old_after, reservations=r.res(),
                   note='the first loan paid late is a second charge without order: rejected, refund by an operator (release blocker)')
        else:
            add_to_cart(b.ws); ok = to_payment(b.ws, email='pf-dbl@example.invalid', street='Calle Doble 1'); mock_plan('stay')
            r2 = evaluate(b.ws, BUTTON_JS)
            for _ in range(2):
                for t in ('mousePressed', 'mouseReleased'):
                    b.ws.send('Input.dispatchMouseEvent', {'type': t, 'x': r2['x'], 'y': r2['y'], 'button': 'left', 'clickCount': 1})
            time.sleep(8)
            data = dict(attempts=[x['entity_id'] + ':' + x['status'] for x in r.attempts()], create_loan=len(r.calls('createLoan')),
                        popups=len(b.popups()))
            good = ok and len(data['attempts']) == 1 and data['create_loan'] == 1 and data['popups'] == 1 and not r.orders()
            record('ab_reopen_double_click', good, **data)
    finally:
        b.quit()


def t_ab_success_then_close():
    """F. Paid; Aplazo posts success and closes its window in the same instant: the success message and the close
    detection race on the page. One order, one invoice, one reservation; a repeated complete changes nothing."""
    r = Run(); b = Browser(); default_loan('OUTSTANDING')
    try:
        ok, a = open_popup(b, email='pf-race@example.invalid', plan='success_then_close', street='Calle Carrera 1')
        success = bool(wait_success(b)); time.sleep(3)
        first = dict(orders=r.orders(), reservations=r.res(), attempt=attempt(a['entity_id']), status_calls=status_calls(r, a['cart_id']))
        tab = b.new_tab(); tab.send('Page.navigate', {'url': BASE + '/customer/account/login/'}); time.sleep(3)
        again = complete_from(tab, a)['body']
        o = r.orders()
        good = (ok and success and len(o) == 1 and o[0]['invoices'] == '1' and first['reservations'] == ['-1.0000 order_placed ' + a['cart_id']]
                and again.get('status') == 'completed' and r.orders() == first['orders'] and r.res() == first['reservations'])
        record('ab_success_then_close_race', good, success=success, first=first, repeat=again)
    finally:
        b.quit()


def t_ab_network(mode):
    """G. browser_offline: the buyer's browser is offline (DevTools network emulation) when the window closes: no
    complete reaches Magento, the page keeps the checkout blocked ("could not confirm your payment yet", Check again)
    and never says "not paid"; back online, Check again completes it, one order. response_lost: Magento receives
    complete, confirms the payment and creates the order, and the browser loses the answer (DevTools Fetch fails the
    response after the server sent it): the page retries and the second complete answers completed, still one order."""
    r = Run(); b = Browser(); default_loan('OUTSTANDING')
    try:
        ok, a = open_popup(b, email='pf-net@example.invalid', plan='stay', street='Calle Red 1')
        if mode == 'browser_offline':
            b.ws.send('Network.enable')
            b.ws.send('Network.emulateNetworkConditions', {'offline': True, 'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1})
            b.close_popups()
            st = wait_until(lambda: (lambda o: o if o and o.get('state') == 'message' and 'could not confirm your payment yet' in (o.get('message') or '') else False)(
                evaluate(b.ws, OVERLAY_STATE_JS, timeout=20)), 60)
            offline = dict(page=st, status_calls=status_calls(r, a['cart_id']), orders=len(r.orders()), attempt=attempt(a['entity_id']))
            b.ws.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 0, 'downloadThroughput': -1, 'uploadThroughput': -1})
            clicked = evaluate(b.ws, OVERLAY_ACTION_JS)
            success = bool(wait_success(b)); o = r.orders()
            good = (ok and st and st['inert'] and offline['status_calls'] == 0 and offline['orders'] == 0
                    and offline['attempt']['status'] == 'open' and clicked and success and len(o) == 1 and o[0]['invoices'] == '1'
                    and len(r.res()) == 1)
            record('ab_net_browser_offline', good, offline=offline, check_again_clicked=clicked, success=success, orders=o, reservations=r.res())
        else:
            b.ws.send('Fetch.enable', {'patterns': [{'urlPattern': '*aplazo/popup/complete*', 'requestStage': 'Response'}]})
            b.close_popups()
            failed = None; end = time.time() + 40
            while time.time() < end and not failed:
                b.ws.send('Runtime.evaluate', {'expression': '1'})
                for ev in list(b.ws.events):
                    if ev.get('method') == 'Fetch.requestPaused':
                        b.ws.events.remove(ev)
                        p = ev['params']
                        failed = dict(status=p.get('responseStatusCode'), at=time.strftime('%H:%M:%S'))
                        b.ws.send('Fetch.failRequest', {'requestId': p['requestId'], 'errorReason': 'ConnectionReset'})
                        break
                time.sleep(0.2)
            orders_at_loss = r.orders()
            b.ws.send('Fetch.disable')
            success = bool(wait_success(b)); o = r.orders()
            good = (ok and failed and failed['status'] == 200 and len(orders_at_loss) == 1 and success and len(o) == 1
                    and o == orders_at_loss and o[0]['invoices'] == '1' and len(r.res()) == 1 and attempt(a['entity_id'])['status'] == 'completed')
            record('ab_net_response_lost', good, lost_response=failed, orders_when_lost=orders_at_loss, success=success, orders=o,
                   reservations=r.res())
    finally:
        b.quit()


TESTS = {
    'ab_restart_before_graceful': lambda: t_ab_restart('before_aplazo', True),
    'ab_restart_before_crash': lambda: t_ab_restart('before_aplazo', False),
    'ab_restart_unpaid_graceful': lambda: t_ab_restart('attempt_unpaid', True),
    'ab_restart_unpaid_crash': lambda: t_ab_restart('attempt_unpaid', False),
    'ab_restart_paid_webhook': lambda: t_ab_restart('attempt_paid_webhook', True),
    'ab_restart_paid_nosignal': lambda: t_ab_restart('attempt_paid_nosignal', True),
    'ab_restart_paid_nosignal_crash': lambda: t_ab_restart('attempt_paid_nosignal', False),
    'ab_wait_then_pay': lambda: t_ab_wait('wait_then_pay'),
    'ab_wait_expired': lambda: t_ab_wait('expired'),
    'ab_close_paid_during_checks': lambda: t_ab_close_processing('paid_during_checks'),
    'ab_close_paid_after_checks': lambda: t_ab_close_processing('paid_after_checks'),
    'ab_reopen_changed_qty': lambda: t_ab_reopen('changed_qty'),
    'ab_reopen_double_click': lambda: t_ab_reopen('double_click'),
    'ab_success_then_close': t_ab_success_then_close,
    'ab_net_browser_offline': lambda: t_ab_network('browser_offline'),
    'ab_net_response_lost': lambda: t_ab_network('response_lost'),
    'r10_own_paid_among_others': lambda: t_r10('own_paid_among_others'),
    'r10_own_unpaid_other_paid': lambda: t_r10('own_unpaid_other_paid'),
    'r10_no_loan_foreign_paid': lambda: t_r10('no_loan_foreign_paid'),
    'r10_webhook_duplicate': lambda: t_r10('webhook_duplicate'),
    'r10_webhook_conflict': lambda: t_r10('webhook_conflict'),
    'r10_webhook_ambiguous': lambda: t_r10('webhook_ambiguous'),
    'po_paid': lambda: t_place_other('paid'),
    'po_unpaid': lambda: t_place_other('unpaid'),
    'po_unknown': lambda: t_place_other('unknown'),
    'po_busy': lambda: t_place_other('busy'),
    'po_busy_checkout': lambda: t_place_other('busy_checkout'),
    'close_unpaid': t_close_unpaid,
    'email_webhook': lambda: t_email_path('webhook'),
    'us_uncertain_reclick': lambda: t_uncertain_start('uncertain_reclick'),
    'us_uncertain_cart_change': lambda: t_uncertain_start('uncertain_cart_change'),
    'us_starting_interrupted': lambda: t_uncertain_start('starting_interrupted'),
    'us_normal_and_reuse': lambda: t_uncertain_start('normal_and_reuse'),
    'mf_abort_then_consume': lambda: t_msi_fix('abort_then_consume'),
    'mf_success_then_consume': lambda: t_msi_fix('success_then_consume'),
    'mf_pause_consumer_waiting_commit': lambda: t_msi_fix('pause_consumer_waiting_commit'),
    'mf_pause_abort': lambda: t_msi_fix('pause_abort'),
    'mf_crash_recover': lambda: t_msi_fix('crash_recover'),
    'mf_publish_exception': lambda: t_msi_fix('publish_exception'),
    'mf_publish_error': lambda: t_msi_fix('publish_error'),
    'mf_redirect': t_msi_fix_redirect,
    'msi_drain': t_msi_drain,
    'msi_abort_then_consume': lambda: t_msi('abort_then_consume'),
    'msi_success_then_consume': lambda: t_msi('success_then_consume'),
    'msi_pause_consume_commit': lambda: t_msi('pause_consume_commit'),
    'msi_pause_consume_abort': lambda: t_msi('pause_consume_abort'),
    'msi_pause_consume_commit_followup': lambda: t_msi('pause_consume_commit_followup'),
    'cf_guest_success': lambda: t_coupon_fix('success'),
    'cf_guest_abort': lambda: t_coupon_fix('abort_terminal'),
    'cf_guest_crash_recover': lambda: t_coupon_fix('crash_recover'),
    'cf_guest_submit_failure': lambda: t_coupon_fix('submit_failure'),
    'cf_guest_publish_exception': lambda: t_coupon_fix('publish_exception'),
    'cf_guest_publish_error': lambda: t_coupon_fix('publish_error'),
    'cf_registered_success': lambda: t_coupon_fix('success', 'registered'),
    'cf_registered_crash_recover': lambda: t_coupon_fix('crash_recover', 'registered'),
    'cf_redirect': t_coupon_redirect,
    'cf_limits': t_coupon_limits,
    'cc_guest_success': lambda: t_coupon_counters('success'),
    'cc_guest_abort': lambda: t_coupon_counters('abort_terminal'),
    'cc_guest_crash_recover': lambda: t_coupon_counters('crash_then_recover'),
    'cc_registered_success': lambda: t_coupon_counters('success', 'registered'),
    'cc_registered_crash_recover': lambda: t_coupon_counters('crash_then_recover', 'registered'),
    'email_fix_cli_sync': lambda: t_email_fix('cli_sync'),
    'email_fix_cli_async': lambda: t_email_fix('cli_async'),
    'email_fix_webhook': lambda: t_email_fix('webhook'),
    'email_reconcile_cli': lambda: t_email_path('reconcile_cli'),
    'email_reconcile_cron_class': lambda: t_email_path('reconcile_cron_class'),
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
    'h1_add_to_cart': lambda: t_h1('add_to_cart'),
    'h4_sandbox': lambda: t_h4('sandbox'),
    'h5_interrupted': t_h5_interrupted,
    'h5_cancel_fails': t_h5_cancel_fails,
    'h3_cron_registered': t_h3_cron_registered,
    'h8_sequential': t_h8_sequential,
    'h8_race': t_h8_race,
    'h8_race_mutex': lambda: t_h8_race('cancel'),
    'h2_slow_log': t_h2_slow_log,
    'h4_merchant': lambda: t_h4('merchant'),
    'h9_distinct': lambda: t_h9('distinct'),
    'h9_same': lambda: t_h9('same'),
    'h1_update_qty_save': lambda: t_h1('update_qty', 'save'),
    'h1_update_qty_cart': lambda: t_h1('update_qty', 'cart'),
    'h1_crash_window': t_h1_crash_window,
    'rollback_not_blocked': t_rollback_not_blocked,
    'link_conflict': t_link_conflict,
    'link_conflict_fixed': t_link_conflict_fixed,
    'link_conflict_crash': lambda: t_link_conflict_fixed(True),
    'link_incompatible': t_link_incompatible,
    'coupon_success': lambda: t_coupon('success'),
    'cart_changed_record': t_cart_changed_record,
    'crash_after_writes': t_crash_after_writes,
    'approval_default': lambda: t_approval('default'),
    'approval_custom_status': lambda: t_approval('custom_status'),
    'approval_crash_after_invoice': lambda: t_approval('crash_after_invoice'),
    'approval_operator_invoice': lambda: t_approval('operator_invoice'),
    'coupon_link_fail': lambda: t_coupon('link_fail'),
    'link_conflict_with_write': t_link_conflict_with_write,
    'link_definitive': t_link_definitive,
    'h1_crash_window_cart': lambda: t_h1_crash_window('cart'),
    'h1_add_to_cart_cart': lambda: t_h1('add_to_cart', 'cart'),
    'h1_update_qty': lambda: t_h1('update_qty'),
    'switch_off_webhook': lambda: t_switch_off('webhook'),
    'switch_off_cron': lambda: t_switch_off('cron'),
    'h1_inflight_current': lambda: t_h1_inflight('current'),
    'h1_inflight_order_only': lambda: t_h1_inflight('order_only'),
    'h1_shipping_information': lambda: t_h1('shipping_information'),
    'h1_payment_information': lambda: t_h1('payment_information'),
}

def require_stub():
    """Stops the suite before any request unless kit_guard.php proves, inside this Magento, that LocalDev_AplazoStub is
    enabled, the plugin's ApiService / LogService / TrackingService are the stub's and the store URL is local. Without
    that the store calls the configured Aplazo API (STG) and the scenarios create real loans there."""
    try:
        out = php('kit_guard.php', timeout=120)
    except Exception as e:  # noqa: BLE001
        sys.exit('kit guard could not run (%s): refusing to run' % type(e).__name__)
    if not isinstance(out, dict) or out.get('ok') is not True:
        sys.exit('kit guard failed, refusing to run: %s' % (out.get('problems') if isinstance(out, dict) else out))
    print('kit guard ok: %s' % json.dumps({'hosts': out.get('hosts'), 'resolved': out.get('resolved')}))

if __name__ == '__main__':
    if not M or not os.path.isfile(os.path.join(M, 'bin', 'clinotty')):
        sys.exit('MAGENTO_DIR must point at the docker-magento directory')
    require_stub()
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
