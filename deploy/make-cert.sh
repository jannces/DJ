#!/usr/bin/env bash
# Generate a self-signed TLS certificate for LAN HTTPS.
# Usage: deploy/make-cert.sh onealicialms.local
set -e
CN="${1:-onealicialms.local}"
DIR="$(dirname "$0")/certs"
mkdir -p "$DIR"
openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout "$DIR/lms.key" -out "$DIR/lms.crt" \
  -subj "/C=PH/ST=Isabela/L=Alicia/O=LGU Alicia/CN=$CN" \
  -addext "subjectAltName=DNS:$CN,DNS:localhost,IP:127.0.0.1"
# Verify rather than announce. The Windows counterpart of this script was
# printing "Created ..." unconditionally, so an openssl that had failed to read
# its config -- and therefore dropped the subjectAltName -- still looked like a
# success. A certificate with no SAN is refused outright by every browser.
if ! openssl x509 -in "$DIR/lms.crt" -noout -ext subjectAltName 2>/dev/null | grep -q "$CN"; then
  echo "ERROR: the certificate carries no subjectAltName for $CN - browsers will refuse it." >&2
  exit 1
fi

echo "Created $DIR/lms.crt and $DIR/lms.key for CN=$CN"
echo "Valid 825 days, covering $CN, localhost and 127.0.0.1."
echo "Import lms.crt into client trust stores to avoid browser warnings."
