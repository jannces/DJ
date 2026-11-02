#!/usr/bin/env bash
#
# run-zap-scans.sh — automated OWASP ZAP scanning for the LGU Alicia LMS.
#
# Runs the ZAP baseline scan N times (default 50) against the application, in
# two sessions per run: an UNAUTHENTICATED session and an AUTHENTICATED
# employee-role session. Each run writes a JSON + HTML report. Feed the JSON
# reports to scripts/zap/ingest-zap-results.php to build the evidence pack.
#
# This is an AUTHORIZED self-assessment of your own system. Run it from Kali
# (or any host with ZAP) that can reach the application over the LAN.
#
# ---------------------------------------------------------------------------
# PREREQUISITES
#   - OWASP ZAP installed:  sudo apt install zaproxy      (provides zap-baseline.py)
#     ...or Docker:         docker pull zaproxy/zap-stable
#   - The application reachable at $TARGET below.
#
# BEFORE YOU RUN — two things that will otherwise ruin the scan:
#   1. The app's IDS auto-blocks an IP after a few events. A scanner trips this
#      instantly. Add this host's IP to the authorized-device allow-list, OR
#      raise security.auto_block_threshold / set security.ids_enabled=0 in
#      Settings for the test window, then restore it. Note this in your report.
#   2. The authenticated session must get past the email OTP. ZAP cannot solve
#      OTP, so log in MANUALLY in a browser (password -> OTP -> dashboard), copy
#      the session cookie, and export it before running (see ZAP_AUTH_COOKIE).
#
# USAGE
#   export ZAP_AUTH_COOKIE='lms_session=eyJpdiI6...'   # from your logged-in browser
#   ./run-zap-scans.sh                                 # 50 runs, both sessions
#   RUNS=5 ./run-zap-scans.sh                           # quick 5-run trial
#   TARGET=https://192.168.1.10 ./run-zap-scans.sh      # override target
#
# If ZAP_AUTH_COOKIE is unset, only the unauthenticated session is scanned and
# a warning is printed.
# ---------------------------------------------------------------------------
set -u

TARGET="${TARGET:-https://onealicialms.lan}"
RUNS="${RUNS:-50}"
OUTDIR="${OUTDIR:-zap-results}"
COOKIE_NAME="${COOKIE_NAME:-lms_session}"   # Laravel session cookie name

mkdir -p "$OUTDIR"

# --- locate a ZAP baseline runner ------------------------------------------
if command -v zap-baseline.py >/dev/null 2>&1; then
  ZAP() { zap-baseline.py "$@"; }
elif command -v docker >/dev/null 2>&1; then
  echo "[*] Using Docker image zaproxy/zap-stable"
  ZAP() { docker run --rm -v "$(pwd)/$OUTDIR:/zap/wrk:rw" --network host zaproxy/zap-stable zap-baseline.py "$@"; }
else
  echo "[X] Neither zap-baseline.py nor docker found. Install ZAP: sudo apt install zaproxy" >&2
  exit 1
fi

echo "============================================================"
echo "  OWASP ZAP automated scan"
echo "  Target : $TARGET"
echo "  Runs   : $RUNS   (x2 sessions each)"
echo "  Output : $OUTDIR/"
echo "============================================================"

if [ -z "${ZAP_AUTH_COOKIE:-}" ]; then
  echo "[!] ZAP_AUTH_COOKIE is not set — only the UNAUTHENTICATED session will be scanned."
  echo "    To scan as a logged-in employee, export the cookie first (see header)."
fi

# Replacer config that injects the captured auth cookie on every request.
auth_opts() {
  cat <<EOF
-z
replacer.full_list(0).description=auth
replacer.full_list(0).enabled=true
replacer.full_list(0).matchtype=REQ_HEADER
replacer.full_list(0).matchstr=Cookie
replacer.full_list(0).regex=false
replacer.full_list(0).replacement=${ZAP_AUTH_COOKIE}
EOF
}

run_scan() {   # $1 = run label (e.g. 01)   $2 = session (unauth|auth)
  local label="$1" session="$2"
  local base="run-${label}-${session}"
  echo
  echo "--- run ${label} / ${session} -----------------------------------------"

  # -I: do not fail the whole script on warnings; the ingester decides pass/fail.
  # -j: use the ajax spider too (SPA-safe). Remove if scans are too slow.
  local common=( -t "$TARGET" -J "${base}.json" -r "${base}.html" -I )

  if [ "$session" = "auth" ]; then
    # shellcheck disable=SC2046
    ZAP "${common[@]}" $(auth_opts)
  else
    ZAP "${common[@]}"
  fi
}

for i in $(seq -w 1 "$RUNS"); do
  run_scan "$i" "unauth"
  if [ -n "${ZAP_AUTH_COOKIE:-}" ]; then
    run_scan "$i" "auth"
  fi
done

echo
echo "============================================================"
echo "  Done. $(ls "$OUTDIR"/*.json 2>/dev/null | wc -l) JSON reports in $OUTDIR/"
echo "  Next: php scripts/zap/ingest-zap-results.php $OUTDIR"
echo "============================================================"
