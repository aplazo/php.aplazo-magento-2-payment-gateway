"""Smoke check on a local Magento checkout (test only). Stops before Place Order: no order, loan or stock reservation.

Adds the test product to a guest cart, fills a synthetic Mexican address, selects Aplazo and reports what the
checkout rendered plus any JavaScript errors. Loading the checkout makes the module validate its credentials
once against the configured Aplazo API, as every checkout page load does.

Environment:
  MAGENTO_BASE_URL      default https://magento.test
  MAGENTO_PRODUCT_PATH  URL path of a simple, in-stock product (default aplazo-producto-prueba.html)
  MAGENTO_PRODUCT_SKU   its SKU (default APLAZO-TEST-001)
  CHROME                Chrome/Chromium binary
"""
import json, os, shutil, subprocess, sys, tempfile, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cdp import WS, devtools_json, evaluate, wait_for

BASE_URL = os.environ.get('MAGENTO_BASE_URL', 'https://magento.test').rstrip('/')
PRODUCT_PATH = os.environ.get('MAGENTO_PRODUCT_PATH', 'aplazo-producto-prueba.html').lstrip('/')
CHROME = os.environ.get('CHROME') or next((p for p in ('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                                                        shutil.which('google-chrome') or '', shutil.which('chromium') or '')
                                           if p and os.path.exists(p)), None)
PRODUCT_SKU = os.environ.get('MAGENTO_PRODUCT_SKU', 'APLAZO-TEST-001')
PORT = 9333
if not CHROME:
    sys.exit('Chrome not found: set CHROME')

profile = tempfile.mkdtemp()
chrome = subprocess.Popen([CHROME, '--headless=new', '--disable-gpu', '--no-first-run', '--remote-debugging-port=%d' % PORT,
                           '--user-data-dir=' + profile, 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    for _ in range(40):
        try:
            targets = devtools_json(PORT)
            page = [t for t in targets if t['type'] == 'page'][0]
            break
        except Exception:
            time.sleep(0.25)
    ws = WS(page['webSocketDebuggerUrl'])
    ws.send('Runtime.enable'); ws.send('Log.enable'); ws.send('Page.enable')

    ws.send('Page.navigate', {'url': BASE_URL + '/' + PRODUCT_PATH})
    wait_for(ws, "document.readyState==='complete' && !!document.querySelector('#product-addtocart-button:not([disabled])')")
    time.sleep(2)
    evaluate(ws, "document.querySelector('#product-addtocart-button').click(); true")
    wait_for(ws, "!!document.querySelector('.message-success, [data-ui-id=\"message-success\"]') || "
                 "(window.localStorage['mage-cache-storage']||'').indexOf(" + json.dumps(PRODUCT_SKU) + ")>-1", 30)
    time.sleep(2)

    ws.send('Page.navigate', {'url': BASE_URL + '/checkout/'})
    ok = wait_for(ws, "!!document.querySelector('input#aplazo_gateway') || !!document.querySelector('#checkout-step-shipping')", 60)
    # Fill a Mexican guest address only to reach the payment step; Place Order is never clicked.
    fill = r"""
    (async () => {
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      const set = (sel, v) => { const el = document.querySelector(sel); if (!el) return false;
        el.value = v; el.dispatchEvent(new Event('change', {bubbles:true})); el.dispatchEvent(new Event('keyup', {bubbles:true})); return true; };
      for (let i = 0; i < 40 && !document.querySelector('#customer-email'); i++) await sleep(500);
      set('#customer-email', 'qa-local@magento.test');
      set('[name="firstname"]', 'QA'); set('[name="lastname"]', 'Local');
      set('[name="street[0]"]', 'Calle 1'); set('[name="city"]', 'CDMX');
      set('[name="country_id"]', 'MX'); await sleep(800);
      const region = document.querySelector('[name="region_id"]');
      if (region && region.options.length > 1) { region.selectedIndex = 1; region.dispatchEvent(new Event('change', {bubbles:true})); }
      set('[name="region"]', 'Ciudad de México');
      set('[name="postcode"]', '06600'); set('[name="telephone"]', '5555555555');
      for (let i = 0; i < 40 && !document.querySelector('input[value="flatrate_flatrate"]'); i++) await sleep(500);
      const rate = document.querySelector('input[value="flatrate_flatrate"]'); if (rate) rate.click();
      await sleep(800);
      const next = document.querySelector('#shipping-method-buttons-container button, button.continue');
      if (next) next.click();
      for (let i = 0; i < 60 && !document.querySelector('input#aplazo_gateway'); i++) await sleep(500);
      const radio = document.querySelector('input#aplazo_gateway'); if (radio) radio.click();
      await sleep(1500);
      return !!radio;
    })()
    """
    reached_payment = evaluate(ws, fill, timeout=90)
    report = evaluate(ws, r"""
    (() => {
      const cfg = (window.checkoutConfig.payment || {}).aplazo_gateway || null;
      const box = document.querySelector('input#aplazo_gateway') ? document.querySelector('input#aplazo_gateway').closest('.payment-method') : null;
      const btn = box ? box.querySelector('button.action.primary.checkout') : null;
      let moduleLoaded = false;
      try { moduleLoaded = !!require.defined && require.defined('Aplazo_AplazoPayment/js/model/popup-checkout'); } catch (e) {}
      return {
        checkoutMode: cfg && cfg.checkoutMode, aplazoOrigin: cfg && cfg.aplazoOrigin, active: cfg && cfg.active,
        aplazoMethodRendered: !!box, placeOrderButtonEnabled: btn ? !btn.disabled : null,
        noticeRendered: !!(box && box.querySelector('.aplazo-popup-notice')),
        popupModuleLoaded: moduleLoaded
      };
    })()
    """)
    problems = []
    for e in ws.events:
        m = e.get('method')
        if m == 'Runtime.exceptionThrown':
            problems.append(e['params']['exceptionDetails'].get('exception', {}).get('description', 'exception')[:200])
        elif m == 'Log.entryAdded' and e['params']['entry'].get('level') == 'error':
            problems.append(e['params']['entry'].get('text', '')[:200])
        elif m == 'Runtime.consoleAPICalled' and e['params'].get('type') == 'error':
            problems.append(' '.join(str(a.get('value', a.get('description', ''))) for a in e['params']['args'])[:200])
    relevant = [p for p in problems if 'aplazo' in p.lower() or 'knockout' in p.lower() or 'binding' in p.lower() or 'require' in p.lower()]
    print(json.dumps({'reached_payment_step': reached_payment, 'report': report,
                      'js_errors_total': len(problems), 'js_errors_relevant': relevant}, ensure_ascii=False, indent=1))
finally:
    chrome.kill()
    chrome.wait()
    shutil.rmtree(profile, ignore_errors=True)
