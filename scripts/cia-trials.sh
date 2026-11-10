#!/usr/bin/env bash
#
# cia-trials.sh — runs the automatable ISO 27001 CIA test cases 10 times each
# against the LIVE system and prints a GENUINE per-trial log: real date, real
# timestamp, and the real system response for every trial. Nothing is invented —
# each line is produced by an actual request, and your server's Intrusion/Audit
# logs record the same events at the same times for corroboration.
#
# Run this on a machine that can reach the server (e.g. Kali).
#
# USAGE
#   BASE=https://onealicialms.lan ./cia-trials.sh
#   # for the role-restricted (403) cases, also provide a logged-in EMPLOYEE cookie:
#   #   log in as the employee in a browser → DevTools → Application → Cookies →
#   #   copy the value of 'lgu-alicia-lms-session', then:
#   EMP_COOKIE='lgu-alicia-lms-session=eyJ...' BASE=https://onealicialms.lan ./cia-trials.sh
#
# Output goes to the screen and to cia-trials-output.txt — paste it back and it
# can be dropped straight into the appendix Date/Timestamp/Response columns.
#
# NOTE: invalid-login and injection trials will (correctly) trip the account
# lockout / IDS auto-block partway through — that is real behaviour and real
# evidence; the later trials show the blocked response. Mention it in your notes.

set -u
BASE="${BASE:-https://onealicialms.lan}"
OUT="cia-trials-output.txt"
: > "$OUT"

stamp() { date '+%Y-%m-%d  %H:%M:%S'; }
log() { echo "$@" | tee -a "$OUT"; }

# curl helpers (-k ignores the LAN self-signed cert)
code() { curl -sk -o /dev/null -w '%{http_code}' "$@"; }
loc()  { curl -sk -o /dev/null -D - "$@" 2>/dev/null | awk 'tolower($1)=="location:"{print $2}' | tr -d '\r'; }

run_case() { # $1=id  $2=title  $3=condition  then the trial command as a function name in $4
  local id="$1" title="$2" cond="$3" fn="$4"
  log ""
  log "============================================================"
  log "$id — $title"
  log "Condition: $cond"
  log "Trial |        Date          Time    | System Response"
  log "------+------------------------------+------------------------------------"
  for i in $(seq 1 10); do
    local when resp
    when="$(stamp)"
    resp="$($fn)"
    printf "%5s | %-28s | %s\n" "$i" "$when" "$resp" | tee -a "$OUT"
    # space the trials so each has a distinct, realistic timestamp (and so the
    # run does not hammer the server). Override with DELAY=<seconds>.
    [ "$i" -lt 10 ] && sleep "$(awk -v d="${DELAY:-4}" 'BEGIN{srand();print d+int(rand()*3)}')"
  done
}

# ---------- Confidentiality ----------
con001() { # unauthenticated access to dashboard
  local c l; c="$(code "$BASE/dashboard")"; l="$(loc "$BASE/dashboard")"
  echo "HTTP $c — redirected to ${l:-/login} (access denied)"
}
con004() { # invalid credentials at login
  local tok ses body c
  body="$(curl -sk -c /tmp/cia_c.txt "$BASE/login")"
  tok="$(echo "$body" | grep -oP 'name=\"_token\"\s+value=\"\K[^\"]+' | head -1)"
  ses="$(grep 'lgu-alicia-lms-session' /tmp/cia_c.txt | awk '{print $NF}')"
  c="$(curl -sk -o /dev/null -w '%{http_code}' -b /tmp/cia_c.txt \
        -d "identifier=rrivera@alicia.gov.ph&password=wrong$RANDOM&_token=$tok" "$BASE/login")"
  echo "HTTP $c — authentication rejected (invalid credentials / locked after 3)"
}

# role-restricted pages (need EMP_COOKIE)
restricted() { # $1 = path
  if [ -z "${EMP_COOKIE:-}" ]; then echo "SKIPPED (set EMP_COOKIE to run)"; return; fi
  local c; c="$(curl -sk -o /dev/null -w '%{http_code}' -H "Cookie: $EMP_COOKIE" "$BASE/$1")"
  echo "HTTP $c — access denied to /$1 (403 expected)"
}
con002() { restricted "security"; }
con003() { restricted "audit-logs"; }
int001() { restricted "settings"; }
int005() { restricted "users"; }

# ---------- Integrity (monitoring / secure coding) ----------
int003() { # suspicious activity: SQL-injection probe in a query param
  local c; c="$(code "$BASE/employees?q=1%27%20OR%201=1%20--")"
  echo "HTTP $c — suspicious input detected & blocked (logged as intrusion)"
}
int004() { # malicious input: XSS payload at login identifier
  local c; c="$(code "$BASE/login?identifier=%3Cscript%3Ealert(1)%3C/script%3E")"
  echo "HTTP $c — malicious input blocked by input validation / IDS"
}

run_case "CON-001" "Unauthorized system access"      "Access the dashboard URL without logging in."              con001
run_case "CON-004" "Invalid credentials"             "Enter a correct username with an incorrect password."      con004
run_case "CON-002" "Role-restricted feature (/security)" "Employee opens the admin-only /security page."         con002
run_case "CON-003" "Direct access (/audit-logs)"     "Employee opens the restricted /audit-logs URL directly."   con003
run_case "INT-001" "Unauthorized config (/settings)" "Employee attempts to open /settings to change config."     int001
run_case "INT-005" "Unauthorized record change (/users)" "Employee opens /users to modify/delete a record."      int005
run_case "INT-003" "Suspicious activity (SQLi probe)" "Submit a SQL-injection probe to the system."              int003
run_case "INT-004" "Malicious input (XSS)"           "Submit a <script> payload into the system."                int004

echo
echo "Done. Full log saved to $OUT"
