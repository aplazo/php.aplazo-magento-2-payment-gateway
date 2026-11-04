"""Local test servers for the popup harness (BNPL-1607). Test only, never deployed.

  8098  harness, the merchant page stand-in: harness files, the module JS (read-only), /lib, /results, /hits
  8099  mock Aplazo checkout, no COOP header
  8100  mock Aplazo checkout with Cross-Origin-Opener-Policy: same-origin

The ports are fixed because the mock and the harness name each other's origin explicitly.
"""
import argparse
import json
import os
import threading
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE = os.path.dirname(os.path.abspath(__file__))
HITS = []
LOCK = threading.Lock()


def make_handler(port, module_js, lib_dir, out_dir):
    class Handler(SimpleHTTPRequestHandler):
        def __init__(self, *a, **kw):
            super().__init__(*a, directory=HERE, **kw)

        def log_message(self, *args):
            pass

        def end_headers(self):
            self.send_header('Cache-Control', 'no-store')
            if port == 8100 and self.path.startswith('/mock-checkout.html'):
                self.send_header('Cross-Origin-Opener-Policy', 'same-origin')
            super().end_headers()

        def translate_path(self, path):
            p = urlparse(path).path
            if port == 8098 and p.startswith('/module/js/'):
                return os.path.join(module_js, p[len('/module/js/'):])
            if p.startswith('/lib/'):
                return os.path.join(lib_dir, os.path.basename(p))
            return super().translate_path(path)

        def do_GET(self):
            u = urlparse(self.path)
            q = parse_qs(u.query)
            if u.path in ('/mock-checkout.html', '/mocklog'):
                with LOCK:
                    HITS.append({'port': port, 'path': u.path, 'query': {k: v[0] for k, v in q.items()}})
            if port == 8098 and u.path == '/hits':
                with LOCK:
                    body = json.dumps(HITS).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(body)
                return
            if u.path == '/mocklog':
                self.send_response(204)
                self.end_headers()
                return
            super().do_GET()

        def do_POST(self):
            u = urlparse(self.path)
            if port == 8098 and u.path == '/results':
                length = int(self.headers.get('Content-Length', 0))
                suite = parse_qs(u.query).get('suite', ['main'])[0]
                safe = ''.join(c for c in suite if c.isalnum() or c in '-_')
                with open(os.path.join(out_dir, 'results-%s.json' % safe), 'wb') as fh:
                    fh.write(self.rfile.read(length))
                self.send_response(204)
                self.end_headers()
                return
            self.send_response(404)
            self.end_headers()

    return Handler


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--module-js', default=os.path.join(HERE, '..', '..', 'view', 'frontend', 'web', 'js'),
                        help='module view/frontend/web/js directory (default: this repo)')
    parser.add_argument('--out', default=os.path.join(HERE, '.out'), help='results directory')
    parser.add_argument('--lib', default=None, help='directory with jquery.js and knockout.js (default: <out>/lib)')
    args = parser.parse_args()
    out_dir = os.path.abspath(args.out)
    lib_dir = os.path.abspath(args.lib or os.path.join(out_dir, 'lib'))
    module_js = os.path.abspath(args.module_js)
    os.makedirs(out_dir, exist_ok=True)
    for port in (8098, 8099, 8100):
        server = ThreadingHTTPServer(('127.0.0.1', port), make_handler(port, module_js, lib_dir, out_dir))
        threading.Thread(target=server.serve_forever, daemon=True).start()
    print('listening on 8098 8099 8100, module js: %s' % module_js, flush=True)
    threading.Event().wait()
