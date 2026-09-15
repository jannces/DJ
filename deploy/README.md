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

## Moving the server to a different network

Another Wi-Fi, another router, or the office itself: the server's IP address
changes, and five things are pinned to the old one.

**On the server**, this is the whole migration:

```
deploy\setup-https.bat        (as administrator)
start.bat
```

It re-detects the address and rebuilds everything from it — the vhost's
`Require ip` subnet, the `ServerAlias`, and the certificate, which is now
reissued when it no longer covers this machine's address rather than only when
it no longer covers the hostname.

**Then on every other PC**, because the certificate was reissued and the old
one is no longer valid anywhere:

```
deploy\connect-client.bat <new server IP>     (as administrator)
```

Skipping this leaves that PC pointing at an address nothing answers on, and
trusting a certificate that no longer exists.

**Check the network profile.** Windows classifies every network it has not been
told about as **Public**, and the firewall rules are scoped to private and
domain networks — so joining a new Wi-Fi silently undoes the firewall step. The
rules stay listed and stop matching. `setup-https.bat` now warns about this, and
`check-lan.bat` shows which adapter is which. Set it to Private in Settings →
Network & Internet → your adapter.

**Ask for a fixed address.** A DHCP reservation on the router, binding the
server to one address, avoids repeating all of the above every time the lease
changes. Worth doing before the LGU rollout rather than after.

**Redo the router DNS record** for phones: `onealicialms.lan` → the new IP.

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
