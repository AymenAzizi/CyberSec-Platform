#!/usr/bin/env python3
"""Simple HTTP proxy: localhost:3000 -> localhost:8000 with logging"""
import http.server, http.client, sys, datetime

class Proxy(http.server.BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        ts = datetime.datetime.now().strftime("%H:%M:%S")
        sys.stderr.write(f"[{ts}] {self.client_address[0]} {self.command} {self.path} -> {format % args}\n")
        sys.stderr.flush()

    def do_GET(self): self.proxy()
    def do_POST(self): self.proxy()
    def do_PUT(self): self.proxy()
    def do_DELETE(self): self.proxy()
    def do_PATCH(self): self.proxy()
    def do_HEAD(self): self.proxy()
    def do_OPTIONS(self): self.proxy()

    def proxy(self):
        try:
            length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(length) if length else b''
            
            # Forward to Laravel on 8000
            conn = http.client.HTTPConnection('127.0.0.1', 8000, timeout=120)
            headers = {k: v for k, v in self.headers.items()}
            headers['Host'] = self.headers.get('Host', '127.0.0.1:3000')
            headers['X-Forwarded-For'] = self.client_address[0]
            headers['X-Forwarded-Proto'] = 'http'
            headers['X-Real-IP'] = self.client_address[0]
            
            conn.request(self.command, self.path, body=body, headers=headers)
            resp = conn.getresponse()
            
            self.send_response(resp.status, resp.reason)
            for k, v in resp.getheaders():
                if k.lower() not in ('transfer-encoding', 'connection', 'keep-alive'):
                    self.send_header(k, v)
            self.end_headers()
            self.wfile.write(resp.read())
        except Exception as e:
            self.send_response(502)
            self.send_header('Content-Type', 'text/plain')
            self.end_headers()
            self.wfile.write(f'Proxy error: {e}'.encode())

if __name__ == '__main__':
    server = http.server.ThreadingHTTPServer(('0.0.0.0', 3000), Proxy)
    print('Proxy on :3000 -> 127.0.0.1:8000', flush=True)
    server.serve_forever()
