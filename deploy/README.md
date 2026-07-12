# Deployment helpers

| File | Purpose |
|------|---------|
| `make-cert.sh` / `make-cert.bat` | Generate a self-signed TLS cert (`certs/lms.crt`, `certs/lms.key`) |
| `apache-vhost.conf` | Apache VirtualHost (HTTP→HTTPS redirect, LAN-only, TLS) |
| `lms-queue.service` | systemd unit for the queue worker (Linux) |

See `docs/Deployment.md` for the full step-by-step LAN/XAMPP installation and the
Windows Task Scheduler entries for the queue worker and scheduler. Beginners should
follow `RUN_ON_YOUR_PC.md` in the project root.
