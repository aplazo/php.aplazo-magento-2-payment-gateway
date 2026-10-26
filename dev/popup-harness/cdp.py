"""Tiny DevTools-protocol client (stdlib only). Test only."""
import base64, json, os, socket, struct, time


class WS:
    def __init__(self, url):
        host_port, path = url[len('ws://'):].split('/', 1)
        host, port = host_port.split(':')
        self.s = socket.create_connection((host, int(port)))
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

    def send(self, method, params=None, timeout=30):
        self.id += 1
        payload = json.dumps({'id': self.id, 'method': method, 'params': params or {}}).encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(payload)
        header += bytes([0x80 | n]) if n < 126 else (bytes([0x80 | 126]) + struct.pack('>H', n) if n < 65536 else bytes([0x80 | 127]) + struct.pack('>Q', n))
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))
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
