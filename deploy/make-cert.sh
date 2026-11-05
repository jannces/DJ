#!/usr/bin/env bash
# Generate a self-signed TLS certificate for LAN HTTPS.
# Usage: deploy/make-cert.sh [hostname] [server-ip]
#        deploy/make-cert.sh onealicialms.lan 192.168.254.102
#
# The optional server IP goes into the certificate alongside the name. It
# matters for phones: a phone has no hosts file, so where the router cannot
# hold a DNS record the only way in is https://<ip>, and a certificate that
# does not list that IP fails with NAME_MISMATCH.
set -e
CN="${1:-onealicialms.lan}"
SERVER_IP="${2:-}"
DIR="$(dirname "$0")/certs"
mkdir -p "$DIR"

SAN="DNS:$CN,DNS:localhost,IP:127.0.0.1"
[ -n "$SERVER_IP" ] && SAN="$SAN,IP:$SERVER_IP"

# extendedKeyUsage and basicConstraints are both stated explicitly.
#
# serverAuth: Apple has required it on TLS server certificates since iOS 13.
# Without it a certificate can be installed and trusted on an iPhone and the
# connection still fails -- every step appears to have worked.
#
# CA:TRUE: Android's "Install a certificate -> CA certificate" screen refuses
# anything without it. It was already being set, but only as a side effect of
# the v3_ca section of whichever openssl.cnf happened to be found -- an
# accident, on a value phones depend on.
openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout "$DIR/lms.key" -out "$DIR/lms.crt" \
  -subj "/C=PH/ST=Isabela/L=Alicia/O=LGU Alicia/CN=$CN" \
  -addext "subjectAltName=$SAN" \
  -addext "extendedKeyUsage=serverAuth" \
  -addext "basicConstraints=critical,CA:TRUE"

# Verify rather than announce. The Windows counterpart of this script was
# printing "Created ..." unconditionally, so an openssl that had failed to read
# its config -- and therefore dropped the subjectAltName -- still looked like a
# success. A certificate with no SAN is refused outright by every browser.
if ! openssl x509 -in "$DIR/lms.crt" -noout -ext subjectAltName 2>/dev/null | grep -q "$CN"; then
  echo "ERROR: the certificate carries no subjectAltName for $CN - browsers will refuse it." >&2
  exit 1
fi

# Same reasoning: a dropped -addext leaves a certificate that looks fine and
# fails only on iPhones.
if ! openssl x509 -in "$DIR/lms.crt" -noout -ext extendedKeyUsage 2>/dev/null | grep -q "TLS Web Server Authentication"; then
  echo "ERROR: the certificate has no serverAuth extended key usage - iPhones will refuse it." >&2
  exit 1
fi

echo "Created $DIR/lms.crt and $DIR/lms.key for CN=$CN"
echo "Valid 825 days, covering $SAN"
echo "Import lms.crt into client trust stores to avoid browser warnings."
