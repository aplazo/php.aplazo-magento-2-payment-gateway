"""Tiny DevTools-protocol client (stdlib only). Test only.

Network access is loopback only: the DevTools endpoints of a Chrome these tools start and the local mock servers.
URLs are built here from the fixed host 127.0.0.1 and a checked port and path. The HTTP opener has no file:// handler
and does not follow redirects. Chrome's DevTools socket is plain (unencrypted) WebSocket by design, so it is accepted
only on loopback.
"""
import base64, json, os, re, socket, struct, time, urllib.parse, urllib.request

LOOPBACK = '127.0.0.1'
_LOOPBACK_NAMES = ('127.0.0.1', 'localhost')
_LOCAL_PATH = re.compile(r'^/[A-Za-z0-9_./-]*(\?[A-Za-z0-9_.=&%-]*)?$')
_DEVTOOLS_PATH = re.compile(r'^/json(/list|/version|/(close|activate)/[A-Za-z0-9]+)?$')


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # the 3xx is raised as an HTTPError instead of being followed


def _check_port(port):
    if isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535:
        raise ValueError('invalid local port: %r' % (port,))


def local_request(port, path, method='GET', https=False, context=None, timeout=15):
    """GET/POST to http(s)://127.0.0.1:<port><path>; nothing else can be reached through it."""
    _check_port(port)
    if not isinstance(path, str) or not _LOCAL_PATH.match(path) or '..' in path or '//' in path:
        raise ValueError('invalid local path: %r' % (path,))
    if method not in ('GET', 'POST', 'PUT'):
        raise ValueError('invalid method: %r' % (method,))
    opener = urllib.request.OpenerDirector()
    for handler in (urllib.request.HTTPHandler(), urllib.request.HTTPSHandler(context=context), _NoRedirect(),
                    urllib.request.HTTPDefaultErrorHandler(), urllib.request.HTTPErrorProcessor()):
        opener.add_handler(handler)
    url = '%s://%s:%d%s' % ('https' if https else 'http', LOOPBACK, port, path)
    with opener.open(urllib.request.Request(url, method=method), timeout=timeout) as response:
        return response.read()


def devtools_json(port, path='/json'):
    """One of Chrome's DevTools HTTP endpoints on loopback (/json, /json/list, /json/version, /json/close/<id>)."""
    if not _DEVTOOLS_PATH.match(path):
        raise ValueError('unexpected DevTools endpoint: %r' % (path,))
    body = local_request(port, path)
    return json.loads(body) if body.lstrip()[:1] in (b'[', b'{') else body.decode()


class WS:
    def __init__(self, url):
        parts = urllib.parse.urlsplit(url)
        if parts.scheme != 'ws' or parts.hostname not in _LOOPBACK_NAMES or parts.port is None:
            raise ValueError('DevTools socket must be on loopback: %r' % (url,))
        _check_port(parts.port)
        host_port, path = '%s:%d' % (parts.hostname, parts.port), parts.path.lstrip('/')
        self.s = socket.create_connection((parts.hostname, parts.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall(('GET /%s HTTP/1.1\r\nHost: %s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                        'Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n' % (path, host_port, key)).encode())
        buf = b''
        while b'\r\n\r\n' not in buf:
            buf += self.s.recv(4096)
        self.extra = buf.split(b'\r\n\r\n', 1)[1]
        self.id = 0
        self.events = []

    def _recv_exact(self, n):
        while len(self.extra) < n:
            self.extra += self.s.recv(65536)
        out, self.extra = self.extra[:n], self.extra[n:]
        return out

    def recv(self):
        data = b''
        while True:
            b1, b2 = self._recv_exact(2)
            ln = b2 & 0x7f
            if ln == 126:
                ln = struct.unpack('>H', self._recv_exact(2))[0]
            elif ln == 127:
                ln = struct.unpack('>Q', self._recv_exact(8))[0]
            data += self._recv_exact(ln)
            if b1 & 0x80:
                return json.loads(data.decode())

    def post(self, method, params=None, session_id=None):
        """Sends a command without waiting for its answer (for a reader that runs elsewhere). Returns its id."""
        self.id += 1
        message = {'id': self.id, 'method': method, 'params': params or {}}
        if session_id:
            message['sessionId'] = session_id
        payload = json.dumps(message).encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(payload)
        header += bytes([0x80 | n]) if n < 126 else (bytes([0x80 | 126]) + struct.pack('>H', n) if n < 65536 else bytes([0x80 | 127]) + struct.pack('>Q', n))
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))
        return self.id

    def send(self, method, params=None, timeout=30):
        self.post(method, params)
        end = time.time() + timeout
        while time.time() < end:
            msg = self.recv()
            if msg.get('id') == self.id:
                return msg.get('result', msg)
            self.events.append(msg)
        raise TimeoutError(method)


def evaluate(ws, expr, timeout=30):
    r = ws.send('Runtime.evaluate', {'expression': expr, 'awaitPromise': True, 'returnByValue': True}, timeout)
    return r.get('result', {}).get('value')


def wait_for(ws, expr, timeout=40):
    end = time.time() + timeout
    while time.time() < end:
        if evaluate(ws, expr):
            return True
        time.sleep(0.5)
    return False
