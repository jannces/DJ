# Deployment helpers

| File | Purpose |
|------|---------|
| `setup-https.bat` | **On the SERVER, as administrator, once.** Whole HTTPS setup, with backups |
| `connect-client.bat` | **On every OTHER PC, as administrator.** Points the name at the server and trusts the certificate |
| `trust-cert.bat` | Trusts the certificate only. `connect-client.bat` calls this; run it alone if the name already resolves |
| `make-cert.sh` / `make-cert.bat` | Generate a self-signed TLS cert (`certs/lms.crt`, `certs/lms.key`) |
| `apache-vhost.conf` | Apache VirtualHost (HTTP→HTTPS redirect, LAN-only, TLS) |
| `lms-queue.service` | systemd unit for the queue worker (Linux) |

See `docs/Deployment.md` for the full step-by-step LAN/XAMPP installation and the
Windows Task Scheduler entries for the queue worker and scheduler. Beginners should
follow `RUN_ON_YOUR_PC.md` in the project root.

## Why the address is `.lan` and not `.local`

`.local` is reserved for mDNS/Bonjour. iOS and macOS resolve those names through
Bonjour and never send them to the router's DNS, and Windows can route them to
mDNS too — which is what produced a correct-looking hosts file and a browser
still reporting `DNS_PROBE_FINISHED_NXDOMAIN`. A `.local` name therefore cannot
be published to phones at all, whatever you configure.

`.lan` is treated as an ordinary name by every resolver, so both a hosts entry
and a router DNS record work.

`setup-https.bat` and `connect-client.bat` each remove the old `.local` hosts
lines and untrust the old certificate as their first step, so running them is
the whole migration. The hosts file is backed up beside itself first.

## Phones and tablets

A phone has no hosts file, so `connect-client.bat` cannot help it. Two things
are needed, and they are independent:

**Name.** Add one DNS record on the office router: `onealicialms.lan` → the
server's IPv4 address. That covers every device on the network at once, phones
included, and is one edit when the server's address changes.

If the router cannot hold a local DNS record, phones can use the address
directly — `https://192.168.254.102`. The server's own IP is now written into
the certificate's subjectAltName by `setup-https.bat`, so that no longer fails
with `NAME_MISMATCH`.

**Certificate.** Copy `certs/lms.crt` to the device (email or a shared folder).
The certificate now carries `CA:TRUE` and `extendedKeyUsage=serverAuth`
explicitly, which is what these two screens require:

- **Android** — Settings → Security → Encryption & credentials → Install a
  certificate → **CA certificate**. Android then shows a standing "network may
  be monitored" notice; that is expected for any privately-signed certificate
  and does not mean anything is wrong.
- **iPhone/iPad** — open the `.crt`, install the profile under Settings →
  General → VPN & Device Management, **then** enable it under Settings →
  General → About → Certificate Trust Settings. Missing that second screen is
  the usual reason an iPhone still refuses a certificate it has installed.

Skipping the certificate step is survivable: the traffic is encrypted either
way, users just meet a warning page each time.

Copy `lms.crt` freely — it is the public half. **Never copy `lms.key`.**
