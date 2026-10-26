"""LOCAL TEST ONLY (BNPL-1664). Mock of Aplazo checkout served over HTTPS on 127.0.0.1:8443, reached by a test Chrome
launched with --host-resolver-rules="MAP checkout.aplazo.net 127.0.0.1:8443", so its origin is the one the plugin
expects (https://checkout.aplazo.net). It never calls Magento and never cancels or pays anything.

  POST /__scenario?name=<plan>   plan for the next checkout page loads
  GET  /__hits                   JSON of every checkout page load (path, query, opener present)
"""
import json, os, ssl, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE = os.path.dirname(os.path.abspath(__file__))
STATE = {'scenario': 'stay', 'hits': []}
LOCK = threading.Lock()
MERCHANT = 'https://magento.test'

PAGE = """<!doctype html><html><head><meta charset="utf-8"><title>Mock Aplazo checkout (BNPL-1664)</title></head>
<body><p id="t">Mock Aplazo checkout - test only (BNPL-1664) - plan: %(plan)s</p>
<script>
var PLAN = %(plan_json)s, M = %(merchant_json)s;
fetch('/__opener?present=' + (window.opener ? '1' : '0') + '&q=' + encodeURIComponent(location.search));
function send(s, extra) { if (window.opener) { window.opener.postMessage(Object.assign({type: 'aplazo:checkout:' + s, version: 1}, extra || {}), M); } }
function at(ms, f) { setTimeout(f, ms); }
var plans = {
  stay: function () {},
  success: function () { at(400, function () { send('success', {redirectUrl: 'https://evil.example/steal'}); }); },
  failure: function () { at(400, function () { send('failure'); }); },
  close: function () { at(400, function () { send('close'); }); },
  pending_stay: function () { at(400, function () { send('pending'); }); },
  filter_then_close: function () {
    at(200, function () { window.opener.postMessage({type: 'aplazo:checkout:success', version: 2}, M); });
    at(300, function () { window.opener.postMessage('aplazo:checkout:success', M); });
    at(400, function () { window.opener.postMessage({type: 'aplazo:checkout:paid', version: 1}, M); });
    // Same origin, wrong source: a child frame of this window.
    var same = document.createElement('iframe');
    same.srcdoc = "<script>parent.opener.postMessage({type:'aplazo:checkout:success',version:1}, " + JSON.stringify(M) + ");<\\/script>";
    document.body.appendChild(same);
    // Opaque origin: a sandboxed frame.
    var opaque = document.createElement('iframe');
    opaque.setAttribute('sandbox', 'allow-scripts');
    opaque.srcdoc = "<script>try{parent.opener.postMessage({type:'aplazo:checkout:success',version:1}, '*');}catch(e){}<\\/script>";
    document.body.appendChild(opaque);
    at(1500, function () { send('close'); });
  }
};
(plans[PLAN] || plans.stay)();
</script></body></html>"""


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code, body=b'', ctype='text/plain'):
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        u = urlparse(self.path)
        if u.path == '/__scenario':
            with LOCK:
                STATE['scenario'] = parse_qs(u.query).get('name', ['stay'])[0]
            return self._send(204)
        self._send(404)

    def do_GET(self):
        u = urlparse(self.path)
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        if u.path == '/__hits':
            with LOCK:
                return self._send(200, json.dumps(STATE['hits']).encode(), 'application/json')
        if u.path == '/__opener':
            with LOCK:
                STATE['hits'].append({'kind': 'opener', 'query': q})
            return self._send(204)
        if u.path.startswith('/mock'):
            with LOCK:
                plan = STATE['scenario']
                STATE['hits'].append({'kind': 'page', 'path': u.path, 'query': q, 'plan': plan})
            body = PAGE % {'plan': plan, 'plan_json': json.dumps(plan), 'merchant_json': json.dumps(MERCHANT)}
            return self._send(200, body.encode(), 'text/html; charset=utf-8')
        self._send(404)


if __name__ == '__main__':
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    # A throwaway self-signed certificate for checkout.aplazo.net, created as the note explains (never committed).
    cert_dir = os.environ.get('MOCK_CERT_DIR', os.path.join(HERE, '..', '.out', 'magento-e2e'))
    ctx.load_cert_chain(os.path.join(cert_dir, 'cert.pem'), os.path.join(cert_dir, 'key.pem'))
    srv = ThreadingHTTPServer(('127.0.0.1', 8443), H)
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    print('mock https on 127.0.0.1:8443', flush=True)
    srv.serve_forever()
