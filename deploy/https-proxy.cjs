// Minimal HTTPS TLS-terminating reverse proxy for local pentest evidence.
// Terminates TLS on 8443 using the self-signed cert from make-cert.sh and
// forwards plaintext HTTP to the Laravel app served by `php artisan serve`
// on 127.0.0.1:8000, so Chromium can be driven against a real https:// origin.
const fs = require('fs');
const http = require('http');
const https = require('https');
const path = require('path');

const CERT_DIR = path.join(__dirname, 'certs');
const TARGET_HOST = '127.0.0.1';
const TARGET_PORT = 8000;
const LISTEN_PORT = 8443;

const options = {
  key: fs.readFileSync(path.join(CERT_DIR, 'lms.key')),
  cert: fs.readFileSync(path.join(CERT_DIR, 'lms.crt')),
};

const server = https.createServer(options, (clientReq, clientRes) => {
  const proxyReq = http.request(
    {
      host: TARGET_HOST,
      port: TARGET_PORT,
      method: clientReq.method,
      path: clientReq.url,
      headers: {
        ...clientReq.headers,
        'X-Forwarded-Proto': 'https',
        'X-Forwarded-Host': clientReq.headers.host || 'localhost:8443',
        'X-Forwarded-For': clientReq.socket.remoteAddress,
      },
    },
    (proxyRes) => {
      clientRes.writeHead(proxyRes.statusCode, proxyRes.headers);
      proxyRes.pipe(clientRes);
    },
  );
  proxyReq.on('error', (err) => {
    if (!clientRes.headersSent) clientRes.writeHead(502);
    clientRes.end(`Bad gateway: ${err.message}`);
  });
  clientReq.pipe(proxyReq);
});

server.on('clientError', (err, socket) => {
  if (socket.writable) socket.end('HTTP/1.1 400 Bad Request\r\n\r\n');
});

server.listen(LISTEN_PORT, '0.0.0.0', () => {
  console.log(`HTTPS proxy listening on https://localhost:${LISTEN_PORT} -> http://${TARGET_HOST}:${TARGET_PORT}`);
});
