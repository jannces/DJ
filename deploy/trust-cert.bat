@echo off
REM ============================================================================
REM  Trust this system's certificate on THIS PC, so the browser stops warning.
REM
REM  Right-click this file and choose "Run as administrator".
REM  Run it once on each PC that uses the system.
REM
REM  It does not change the certificate or the server. It tells Windows that
REM  this office signed it -- which is the only thing the browser is
REM  complaining about. The traffic was already encrypted.
REM
REM  To undo:  deploy\trust-cert.bat remove
REM ============================================================================
setlocal

set SITE=onealicialms.local
set CRT=%~dp0certs\lms.crt

echo.
echo ============================================================
echo   Trust the LGU Alicia LMS certificate on this PC
echo ============================================================
echo.

net session >nul 2>&1
if errorlevel 1 (
  echo [X] This must be run as administrator.
  echo     Right-click trust-cert.bat and choose "Run as administrator".
  goto :fail
)

if not exist "%CRT%" (
  echo [X] No certificate at %CRT%
  echo     Run deploy\setup-https.bat first, or copy lms.crt here from
  echo     the server -- the .crt is public and safe to copy. NEVER copy
  echo     lms.key, which is the private half and belongs on the server only.
  goto :fail
)

if /I "%~1"=="remove" goto :remove

REM The machine store, not the user store: every account on this PC, and
REM services too. -f overwrites a previous copy, so this can be run again
REM after the certificate is regenerated.
certutil -addstore -f Root "%CRT%"
if errorlevel 1 (
  echo.
  echo [X] Could not add the certificate to the Trusted Root store.
  goto :fail
)

echo.
echo ============================================================
echo   Done. CLOSE AND REOPEN the browser completely -- it caches
echo   the old verdict for the tab, and reloading is not enough.
echo.
echo   https://%SITE% should then show a padlock rather than
echo   "Not secure".
echo ============================================================
echo.
pause
exit /b 0

:remove
REM Named by the certificate's subject, which is what certutil matches on.
certutil -delstore Root "%SITE%"
echo.
echo   Removed. The browser will warn about the certificate again.
echo.
pause
exit /b 0

:fail
echo.
pause
exit /b 1
