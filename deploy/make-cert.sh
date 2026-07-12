#!/usr/bin/env bash
# Generate a self-signed TLS certificate for LAN HTTPS.
# Usage: deploy/make-cert.sh lms.alicia.local
set -e
CN="${1:-lms.alicia.local}"
DIR="$(dirname "$0")/certs"
mkdir -p "$DIR"
openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout "$DIR/lms.key" -out "$DIR/lms.crt" \
  -subj "/C=PH/ST=Isabela/L=Alicia/O=LGU Alicia/CN=$CN" \
  -addext "subjectAltName=DNS:$CN,IP:127.0.0.1"
echo "Created $DIR/lms.crt and $DIR/lms.key for CN=$CN"
echo "Import lms.crt into client trust stores to avoid browser warnings."
