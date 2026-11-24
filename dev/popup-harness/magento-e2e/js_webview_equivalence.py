#!/usr/bin/env python3
"""LOCAL TEST ONLY (BNPL-1664). Isolated: loads two versions of webview.js in the kit's headless Chrome (about:blank) and
compares isWebView() on the same user agents. Usage: python3 -I js_webview_equivalence.py <old webview.js> <new webview.js>"""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pf_e2e import Browser  # noqa: E402
from cdp import evaluate  # noqa: E402

UAS = [
    'Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/119.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 12; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Mobile Safari/537.36 WebView',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 300.0.0.0',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/440.0]',
    'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Mobile Safari/537.36 Line/13.0.0',
    'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Mobile Safari/537.36 Twitter for Android',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Safari/537.36 Edg/119.0',
    'Mozilla/5.0 (Linux; Android 13; wvx) AppleWebKit/537.36 Chrome/119.0 Mobile Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/119.0 Safari/537.36',
    'android wv',
    'ANDROID; WV)',
    '',
]
LOADER = "(function(src){ var m; window.define=function(d,f){ m=f(); }; (0,eval)(src); return m; })"


def main():
    old_src, new_src = (open(p, encoding='utf-8').read() for p in sys.argv[1:3])
    b = Browser()
    try:
        b.ws.send('Page.navigate', {'url': 'about:blank'})
        expr = ("(function(){ var L=%s; var o=L(%s), n=L(%s), uas=%s; return JSON.stringify(uas.map(function(u){"
                "return {ua:u, old:o.isWebView(u), new:n.isWebView(u)};})); })()") % (LOADER, json.dumps(old_src), json.dumps(new_src), json.dumps(UAS))
        rows = json.loads(evaluate(b.ws, expr, timeout=60))
        diff = [r for r in rows if r['old'] != r['new']]
        print(json.dumps({'user_agents': len(rows), 'differences': len(diff), 'rows': rows}, ensure_ascii=False))
    finally:
        b.quit()


if __name__ == '__main__':
    main()
