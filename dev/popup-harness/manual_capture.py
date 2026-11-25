"""LOCAL MANUAL TESTS ONLY (BNPL-1664). Observer for a manual purchase. Launches a visible Chrome with its own temporary profile on
https://magento.test/ and only LISTENS through the DevTools protocol: it never clicks, types or submits.

Captured (OUT/events.jsonl, one JSON per line, relative time in seconds):
  - targets: windows/tabs/popups opened, navigated and closed (URL reduced: scheme+host+path, no query/fragment)
  - network: method, reduced URL, resource type, status, failures, for every window (Aplazo's included); never headers,
    cookies, request bodies or response bodies, except the JSON answers of magento.test's aplazo/popup/start,
    aplazo/popup/complete and the cart section count (reduced: no tokens, no URLs with query)
  - console, JS exceptions and browser log entries: magento.test pages only (Aplazo's window is not recorded)
  - screenshots: JPEG frames of the magento.test tab only (Page.startScreencast, at most ~2 per second), in OUT/frames/.
    Not a video, and Aplazo's window is never captured.
Stop: create OUT/STOP or close the Chrome window (the observer also checks every 2 s without events).
One DevTools session per window (attached once, by target id); request paths compare with or without a trailing slash.
OUT holds screenshots with whatever the tester typed (name, address): keep it local, review and redact before sharing.
Usage: python3 -I manual_capture.py <empty output directory outside the repository>
"""
import base64, json, os, select, subprocess, sys, time, urllib.parse

HARNESS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HARNESS)
from cdp import WS, devtools_json  # noqa: E402

OUT = sys.argv[1]
PORT = 9333
CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
LOCAL = 'magento.test'
BODY_PATHS = ('/aplazo/popup/start', '/aplazo/popup/complete', '/customer/section/load')


def norm_path(path):
    return '/' + (path or '').strip('/')
os.makedirs(os.path.join(OUT, 'frames'), exist_ok=True)
T0 = time.time()
log_fh = open(os.path.join(OUT, 'events.jsonl'), 'a')


def log(kind, **data):
    data = dict(t=round(time.time() - T0, 3), at=time.strftime('%H:%M:%S'), kind=kind, **data)
    log_fh.write(json.dumps(data, ensure_ascii=False) + '\n')
    log_fh.flush()


def reduce_url(url):
    if not url or url.startswith(('data:', 'blob:', 'about:', 'chrome')):
        return (url or '')[:40]
    p = urllib.parse.urlsplit(url)
    return '%s://%s%s' % (p.scheme, p.netloc, p.path)


def is_local(url):
    return urllib.parse.urlsplit(url or '').hostname == LOCAL


def reduce_body(path, text):
    try:
        d = json.loads(text)
    except Exception:  # noqa: BLE001
        return {'unparsed_length': len(text or '')}
    if norm_path(path) == '/customer/section/load':
        cart = d.get('cart') or {}
        return {'cart_summary_count': cart.get('summary_count'), 'cart_items': len(cart.get('items') or [])}
    if isinstance(d, dict):
        d = dict(d)
        for k in ('url', 'redirect'):
            if isinstance(d.get(k), str):
                d[k] = reduce_url(d[k])
    return d


try:
    devtools_json(PORT, '/json/version')
    sys.exit('port %d already has a Chrome with DevTools (another capture still open?): close it first' % PORT)
except SystemExit:
    raise
except Exception:  # noqa: BLE001  nothing listening: expected
    pass
proc = subprocess.Popen([CHROME, '--remote-debugging-port=%d' % PORT, '--user-data-dir=' + os.path.join(OUT, 'profile'),
                         '--no-first-run', '--no-default-browser-check', '--new-window', 'about:blank'],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
for _ in range(60):
    try:
        version = devtools_json(PORT, '/json/version')
        break
    except Exception:  # noqa: BLE001
        time.sleep(0.5)
else:
    sys.exit('Chrome DevTools did not come up')
ws = WS(version['webSocketDebuggerUrl'])
log('start', chrome=version.get('Browser'))

sessions = {}      # sessionId -> {'target': id, 'type': ..., 'url': ..., 'main': bool}
pending = {}       # command id -> ('body', sessionId, path, requestId)
requests = {}      # (sessionId, requestId) -> path for body capture
main_sid = None
last_frame = 0.0


def enable(sid, info):
    global main_sid
    if any(s['target'] == info['targetId'] for s in sessions.values()):
        ws.post('Target.detachFromTarget', {'sessionId': sid})
        return False
    sessions[sid] = {'target': info['targetId'], 'type': info['type'], 'url': info.get('url', ''), 'main': False}
    for m in ('Network.enable', 'Page.enable', 'Runtime.enable', 'Log.enable'):
        ws.post(m, {}, sid)
    ws.post('Runtime.runIfWaitingForDebugger', {}, sid)
    if main_sid is None and info['type'] == 'page':
        main_sid = sid
        sessions[sid]['main'] = True
        ws.post('Page.navigate', {'url': 'https://%s/' % LOCAL}, sid)
        ws.post('Page.startScreencast', {'format': 'jpeg', 'quality': 60, 'maxWidth': 1280, 'maxHeight': 1600}, sid)
    return True


ws.post('Target.setDiscoverTargets', {'discover': True})
ws.post('Target.setAutoAttach', {'autoAttach': True, 'waitForDebuggerOnStart': True, 'flatten': True})

while True:
    if os.path.exists(os.path.join(OUT, 'STOP')) or proc.poll() is not None:
        break
    if not ws.extra and not select.select([ws.s], [], [], 2)[0]:
        continue
    try:
        msg = ws.recv()
    except Exception as e:  # noqa: BLE001
        log('observer_socket_closed', error=type(e).__name__)
        break
    mid, method, sid, p = msg.get('id'), msg.get('method'), msg.get('sessionId'), msg.get('params') or {}
    if mid in pending:
        _, bsid, path, rid = pending.pop(mid)
        res = msg.get('result') or {}
        text = res.get('body', '')
        if res.get('base64Encoded'):
            text = base64.b64decode(text).decode('utf-8', 'replace')
        log('response_body', path=path, body=reduce_body(path, text))
        continue
    if not method:
        continue
    if method == 'Target.attachedToTarget':
        info = p['targetInfo']
        if info['type'] in ('page', 'iframe') and enable(p['sessionId'], info):
            log('target_attached', type=info['type'], url=reduce_url(info.get('url')), opener=bool(info.get('openerId')))
        elif info['type'] not in ('page', 'iframe'):
            ws.post('Runtime.runIfWaitingForDebugger', {}, p['sessionId'])
    elif method == 'Target.targetCreated' and p['targetInfo']['type'] == 'page':
        info = p['targetInfo']
        log('target_created', url=reduce_url(info.get('url')), opener=bool(info.get('openerId')))
    elif method == 'Target.targetInfoChanged' and p['targetInfo']['type'] == 'page':
        info = p['targetInfo']
        log('target_url', url=reduce_url(info.get('url')))
        for s in sessions.values():
            if s['target'] == info['targetId']:
                s['url'] = info.get('url', '')
    elif method == 'Target.targetDestroyed':
        log('target_closed', target_known=any(s['target'] == p.get('targetId') for s in sessions.values()))
    elif method == 'Page.screencastFrame':
        ws.post('Page.screencastFrameAck', {'sessionId': p['sessionId']}, sid)
        now = time.time()
        if now - last_frame >= 0.5:
            last_frame = now
            name = 'frame-%07.2f.jpg' % (now - T0)
            with open(os.path.join(OUT, 'frames', name), 'wb') as fh:
                fh.write(base64.b64decode(p['data']))
    elif sid in sessions:
        local = is_local(sessions[sid]['url'])
        if method == 'Network.requestWillBeSent':
            url = p['request']['url']
            log('request', main=sessions[sid]['main'], method=p['request']['method'], url=reduce_url(url), type=p.get('type'))
            path = norm_path(urllib.parse.urlsplit(url).path)
            if urllib.parse.urlsplit(url).hostname == LOCAL and path in BODY_PATHS:
                requests[(sid, p['requestId'])] = path
        elif method == 'Network.responseReceived':
            r = p['response']
            if r.get('status', 0) >= 300 or (sid, p['requestId']) in requests or p.get('type') in ('Document', 'XHR', 'Fetch'):
                log('response', main=sessions[sid]['main'], status=r.get('status'), url=reduce_url(r.get('url')), type=p.get('type'))
        elif method == 'Network.loadingFailed':
            log('request_failed', main=sessions[sid]['main'], error=p.get('errorText'), blocked=p.get('blockedReason'), type=p.get('type'))
        elif method == 'Network.loadingFinished' and (sid, p['requestId']) in requests:
            path = requests.pop((sid, p['requestId']))
            cid = ws.post('Network.getResponseBody', {'requestId': p['requestId']}, sid)
            pending[cid] = ('body', sid, path, p['requestId'])
        elif method == 'Page.frameNavigated' and not p['frame'].get('parentId'):
            log('navigated', main=sessions[sid]['main'], url=reduce_url(p['frame'].get('url')))
        elif local and method == 'Runtime.consoleAPICalled':
            text = ' '.join(str(a.get('value', a.get('description', a.get('type')))) for a in p.get('args', []))[:400]
            log('console', level=p.get('type'), text=text)
        elif local and method == 'Runtime.exceptionThrown':
            d = p.get('exceptionDetails') or {}
            log('js_exception', text=((d.get('exception') or {}).get('description') or d.get('text') or '')[:400])
        elif local and method == 'Log.entryAdded':
            e = p.get('entry') or {}
            log('browser_log', level=e.get('level'), source=e.get('source'), text=(e.get('text') or '')[:300], url=reduce_url(e.get('url')))

log('stop', chrome_running=proc.poll() is None)
log_fh.close()
