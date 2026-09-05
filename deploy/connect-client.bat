@echo off
REM ============================================================================
REM  LGU Alicia LMS - set up ONE OTHER PC to reach the system.
REM
REM  Run this on each office PC that is NOT the server. It does the two things
REM  a client needs, and checks that they worked:
REM
REM    1. hosts     onealicialms.local -> the SERVER's IP address
REM    2. certutil  trusts this office's certificate, so the browser stops
REM                 showing the red warning page
REM
REM  Right-click this file and choose "Run as administrator".
REM  Both steps write to protected locations; without that it can do neither.
REM
REM  Usage:  deploy\connect-client.bat                  (it asks for the IP)
REM          deploy\connect-client.bat 192.168.254.102  (or pass it)
REM
REM  FIND THE IP by running  ipconfig  ON THE SERVER and reading the
REM  "IPv4 Address" line. Not 127.0.0.1 -- on this PC that would mean this PC.
REM
REM  WHAT IT DOES NOT DO: it does not touch Apache, the database, or anything
REM  on the server. It is safe to run twice, and the hosts file is backed up
REM  beside itself before it is edited.
REM ============================================================================
setlocal

set SITE=onealicialms.local
set SITERX=onealicialms\.local
set HOSTSFILE=%SystemRoot%\System32\drivers\etc\hosts
set CRT=%~dp0certs\lms.crt
set TRUST=%~dp0trust-cert.bat

echo.
echo ============================================================
echo   LGU Alicia LMS - connect this PC to the server
echo ============================================================
echo.

REM --- Administrator? --------------------------------------------------------
REM Checked before anything, not half-way through.
net session >nul 2>&1
if errorlevel 1 (
  echo [X] This must be run as administrator.
  echo     Close this window, right-click connect-client.bat and choose
  echo     "Run as administrator".
  goto :fail
)

REM --- The server's address --------------------------------------------------
set IP=%~1
if "%IP%"=="" (
  echo   Run  ipconfig  ON THE SERVER and read its "IPv4 Address" line.
  echo.
  set /p IP=  Server IP address:
)
if "%IP%"=="" ( echo [X] No address given. & goto :fail )

REM Validate it before writing it into a system file. A typo here does not
REM produce an error message later -- it produces a site that never loads.
set IPOK=
set VFILE=%TEMP%\lms-ipok.txt
del "%VFILE%" 2>nul
powershell -NoProfile -Command "$ok=$false; try { $a=[System.Net.IPAddress]::Parse('%IP%'); $ok=($a.AddressFamily -eq 'InterNetwork') } catch {}; if ($ok) { 'ok' }" > "%VFILE%" 2>nul
if exist "%VFILE%" for /f "usebackq delims=" %%v in ("%VFILE%") do set IPOK=%%v
del "%VFILE%" 2>nul

if not defined IPOK (
  echo [X] "%IP%" is not a valid IPv4 address.
  echo     It should look like 192.168.254.102
  goto :fail
)

REM 127.x is the single most likely mistake: copying the line off the server,
REM where 127.0.0.1 is correct. On any other PC it points the name back at
REM itself, and the browser reports a refused connection.
echo %IP% | findstr /B /C:"127." >nul
if not errorlevel 1 (
  echo [X] %IP% is this PC's own loopback address, not the server's.
  echo.
  echo     If this IS the server, you do not need this script -- run
  echo     deploy\setup-https.bat instead.
  echo     Otherwise run  ipconfig  on the server and use its IPv4 Address.
  goto :fail
)

echo   Server : %IP%
echo   Address: https://%SITE%
echo.

REM --- Is the server actually answering? -------------------------------------
REM Done first so the diagnosis is available before anything is changed, and
REM so a firewall problem on the server is not mistaken for a problem here.
REM
REM A raw TCP connect with a timeout, rather than ping: ping can succeed while
REM 443 is blocked, and can fail while the site works fine.
echo [1/4] Can this PC reach %IP% on port 443...
set REACH=
set RFILE=%TEMP%\lms-reach.txt
del "%RFILE%" 2>nul
powershell -NoProfile -Command "$c=New-Object Net.Sockets.TcpClient; try { $r=$c.BeginConnect('%IP%',443,$null,$null); if ($r.AsyncWaitHandle.WaitOne(4000)) { $c.EndConnect($r); 'ok' } } catch {} finally { $c.Close() }" > "%RFILE%" 2>nul
if exist "%RFILE%" for /f "usebackq delims=" %%r in ("%RFILE%") do set REACH=%%r
del "%RFILE%" 2>nul

if defined REACH (
  echo       Reachable.
) else (
  echo [!] No answer on %IP%:443. Continuing anyway - this PC will still be
  echo     set up, and it will work once the server does. The cause is one of:
  echo.
  echo       - The server is off, or Apache is not running ^(run start.bat there^)
  echo       - The firewall on the server has not been opened. Run
  echo         deploy\setup-https.bat as administrator ON THE SERVER; its
  echo         step [7/8] adds the rule for port 443.
  echo       - Wrong IP, or the server is on a different network
  echo.
)

REM --- hosts -----------------------------------------------------------------
REM onealicialms.local is not a real domain. It resolves only because a machine
REM has been told where to point it, and each PC has to be told separately.
echo [2/4] Pointing %SITE% at %IP%...

REM What is in there NOW: no mapping, the right one, or a stale one?
REM
REM The line has to START with an address. A commented-out entry contains the
REM hostname just as happily as a real one, and mistaking the two is exactly
REM how the server ended up with the name in the file twice and no working
REM mapping -- both lines began with '#', which Windows ignores.
REM
REM A stale mapping matters as much as a missing one: DHCP hands the server a
REM different address and every client keeps quietly asking the old one.
set CURIP=
set HFILE=%TEMP%\lms-hosts.txt
del "%HFILE%" 2>nul
powershell -NoProfile -Command "$m=@(Select-String -Path '%HOSTSFILE%' -Pattern '^\s*([0-9A-Fa-f:.]+)\s+.*%SITERX%'); if ($m.Count -eq 0) { 'none' } else { $m[0].Matches[0].Groups[1].Value }" > "%HFILE%" 2>nul
if exist "%HFILE%" for /f "usebackq delims=" %%h in ("%HFILE%") do set CURIP=%%h
del "%HFILE%" 2>nul

if "%CURIP%"=="%IP%" ( echo       Already mapped to %IP%. & goto :hosts_done )

for /f "usebackq delims=" %%t in (`powershell -NoProfile -Command "Get-Date -Format 'yyyyMMdd-HHmmss'"`) do set STAMP=%%t
if "%STAMP%"=="" set STAMP=backup
copy /Y "%HOSTSFILE%" "%HOSTSFILE%.backup-%STAMP%" >nul
echo       Backed up to hosts.backup-%STAMP%

if "%CURIP%"=="none" goto :hosts_add

echo       Currently points at %CURIP% - correcting it to %IP%.
REM Written as an indexed loop rather than a ForEach-Object pipeline. A pipe
REM inside the quoted argument is not what breaks -- cmd leaves a quoted pipe
REM alone -- but escaping it "just in case" hands PowerShell a stray caret and
REM a parse error, and that mistake has already cost this project one run.
REM No pipe, nothing to get wrong.
powershell -NoProfile -Command "$p='%HOSTSFILE%'; $c=@(Get-Content $p); for ($i=0; $i -lt $c.Count; $i++) { if ($c[$i] -match '^\s*[0-9A-Fa-f:.]+\s+.*%SITERX%') { $c[$i]='%IP%       %SITE%' } }; Set-Content -Path $p -Value $c"
if errorlevel 1 ( echo [X] Could not edit the hosts file. & goto :fail )
goto :hosts_flush

:hosts_add
echo.>> "%HOSTSFILE%"
echo # LGU Alicia LMS >> "%HOSTSFILE%"
echo %IP%       %SITE% >> "%HOSTSFILE%"
echo       Added %IP%       %SITE%

REM Windows caches the failure too, so without this the next attempt can still
REM report NXDOMAIN from a name that now resolves perfectly well.
:hosts_flush
ipconfig /flushdns >nul
echo       DNS cache flushed.

:hosts_done

REM --- The certificate -------------------------------------------------------
REM Not required for the site to work -- the traffic is already encrypted --
REM but without it every user meets a full-page red warning, every day, and
REM teaching an office to click past certificate warnings is worse than the
REM warning.
echo [3/4] Trusting the certificate...
if not exist "%CRT%" (
  echo [!] No certificate at
  echo       %CRT%
  echo.
  echo     Copy it here FROM THE SERVER: deploy\certs\lms.crt
  echo     The .crt is public and safe to copy. NEVER copy lms.key -- that is
  echo     the private half and belongs on the server only.
  echo.
  echo     The site will still work without this; the browser will just warn
  echo     about the certificate each time.
  goto :cert_done
)

call "%TRUST%" nopause
if errorlevel 1 (
  echo [!] The certificate could not be trusted - see the message above.
  echo     The site will still work, with a warning page.
) else (
  echo       Trusted.
)
:cert_done

REM --- Prove it, rather than announce it -------------------------------------
echo [4/4] Checking https://%SITE% ...
REM The TLS 1.2 line is in its own try: on an old .NET that enum member does
REM not exist, and an unguarded throw there would skip the check entirely and
REM report nothing at all -- the one outcome worse than a clear failure.
REM -UseBasicParsing because without it Invoke-WebRequest borrows Internet
REM Explorer's engine and fails on a machine where IE has never been opened.
powershell -NoProfile -Command "try { [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12 } catch {}; try { $r=Invoke-WebRequest -Uri 'https://%SITE%/login' -UseBasicParsing -TimeoutSec 15; '      OK - the server answered with HTTP ' + [int]$r.StatusCode } catch { '      Could not load it yet: ' + $_.Exception.Message }"

echo.
echo ============================================================
echo   This PC is set up.
echo.
echo   Open:  https://%SITE%
echo.
echo   CLOSE THE BROWSER COMPLETELY first and reopen it. It caches
echo   both the failed name lookup and the old certificate verdict,
echo   and reloading the tab is not enough to clear either.
echo.
echo   If it still does not load:
echo     DNS_PROBE_FINISHED_NXDOMAIN -^> the hosts step; re-run this
echo     Times out                   -^> firewall on the SERVER; run
echo                                    setup-https.bat there
echo     403 Forbidden               -^> the Require ip subnet in the
echo                                    server's apache-vhost.local.conf
echo     Certificate warning         -^> copy lms.crt here and re-run
echo ============================================================
echo.
pause
exit /b 0

:fail
echo.
echo   Nothing was changed on this PC.
echo.
pause
exit /b 1
