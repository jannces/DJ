# Deployment helpers

| File | Purpose |
|------|---------|
| `setup-https.bat` | **Run as administrator once.** Does the whole HTTPS setup below, with backups |
| `trust-cert.bat` | Run as administrator on each PC to remove the browser's certificate warning |
| `make-cert.sh` / `make-cert.bat` | Generate a self-signed TLS cert (`certs/lms.crt`, `certs/lms.key`) |
| `apache-vhost.conf` | Apache VirtualHost (HTTP→HTTPS redirect, LAN-only, TLS) |
| `lms-queue.service` | systemd unit for the queue worker (Linux) |

See `docs/Deployment.md` for the full step-by-step LAN/XAMPP installation and the
Windows Task Scheduler entries for the queue worker and scheduler. Beginners should
follow `RUN_ON_YOUR_PC.md` in the project root.
