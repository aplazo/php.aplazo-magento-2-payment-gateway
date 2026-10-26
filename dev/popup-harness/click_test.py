"""Clicks the harness button in a VISIBLE Chrome window, with Chrome's normal popup blocker. Test only.

Runs (all by default, or pass names): allowed  untrusted  blocked
  allowed    trusted mouse click (DevTools Input.dispatchMouseEvent), default profile
  untrusted  element.click() from script, no user gesture (control: Chrome blocks the popup)
  blocked    trusted mouse click, profile with pop-ups blocked for http://localhost:8098
Starts its own server.py. Environment: CHROME, MAGENTO_SRC / OUT / MODULE_JS as in run.sh.
"""
import json, os, shutil, subprocess, sys, tempfile, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cdp import WS, devtools_json, evaluate, wait_for

H = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.abspath(os.environ.get('OUT', os.path.join(H, '.out')))
MODULE_JS = os.environ.get('MODULE_JS', os.path.join(H, '..', '..', 'view', 'frontend', 'web', 'js'))
CHROME = os.environ.get('CHROME') or next((p for p in ('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                                                        shutil.which('google-chrome') or '', shutil.which('chromium') or '')
                                           if p and os.path.exists(p)), None)
RUNS = {'allowed': ('trusted', False), 'untrusted': ('script', False), 'blocked': ('trusted', True)}


def run(name, click, block_site, port=9444):
    profile = tempfile.mkdtemp()
    if block_site:
        os.makedirs(os.path.join(profile, 'Default'))
        with open(os.path.join(profile, 'Default', 'Preferences'), 'w') as fh:
            json.dump({'profile': {'content_settings': {'exceptions': {'popups': {
                'http://localhost:8098,*': {'setting': 2}}}}}}, fh)
    result_file = os.path.join(OUT, 'results-click-%s.json' % name)
    if os.path.exists(result_file):
        os.remove(result_file)
    chrome = subprocess.Popen([CHROME, '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=%d' % port,
                               '--user-data-dir=' + profile, '--window-size=900,700', 'about:blank'],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(60):
            try:
                page = [t for t in devtools_json(port) if t['type'] == 'page'][0]
                break
            except Exception:
                time.sleep(0.25)
        ws = WS(page['webSocketDebuggerUrl'])
        ws.send('Page.enable'); ws.send('Runtime.enable')
        ws.send('Page.navigate', {'url': 'http://localhost:8098/harness.html?suite=click-%s' % name})
        wait_for(ws, "document.title === 'ready' && !!document.getElementById('pay')", 30)
        time.sleep(0.5)
        if click == 'trusted':
            rect = evaluate(ws, "(() => { const r = document.getElementById('pay').getBoundingClientRect(); "
                                "return {x: r.x + r.width/2, y: r.y + r.height/2}; })()")
            for t in ('mouseMoved', 'mousePressed', 'mouseReleased'):
                ws.send('Input.dispatchMouseEvent', {'type': t, 'x': rect['x'], 'y': rect['y'], 'button': 'left', 'clickCount': 1})
        else:
            evaluate(ws, "document.getElementById('pay').click(); true")
        for _ in range(40):
            if os.path.exists(result_file):
                break
            time.sleep(0.25)
        if not os.path.exists(result_file):
            return 'no result'
        with open(result_file) as fh:
            return json.load(fh)[0]['observed']
    finally:
        chrome.kill()
        chrome.wait()
        shutil.rmtree(profile, ignore_errors=True)


if __name__ == '__main__':
    if not CHROME:
        sys.exit('Chrome not found: set CHROME')
    if not (os.path.exists(os.path.join(OUT, 'lib', 'jquery.js')) and os.path.exists(os.path.join(OUT, 'lib', 'knockout.js'))):
        sys.exit('Run run.sh once (with MAGENTO_SRC) to copy jquery.js and knockout.js into %s/lib' % OUT)
    server = subprocess.Popen([sys.executable, '-I', os.path.join(H, 'server.py'), '--module-js', MODULE_JS, '--out', OUT],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(1)
        keys = ('clickTrusted', 'placeOrderReturned', 'openCalls', 'openReturnedNull', 'purchaseHits', 'mocklog',
                'successRedirects', 'redirectCalls', 'notice', 'popupClosed')
        for name in (sys.argv[1:] or list(RUNS)):
            click, block = RUNS[name]
            o = run(name, click, block)
            print(name, json.dumps({k: o.get(k) for k in keys} if isinstance(o, dict) else o, ensure_ascii=False))
    finally:
        server.kill()
