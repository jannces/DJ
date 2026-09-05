@echo off
REM ============================================================================
REM  LGU Alicia LMS - why can another device not reach this server?
REM
REM  Run this ON THE SERVER, as administrator.
REM  It changes NOTHING. It only reports, in the order things actually fail.
REM
REM  Usage:  deploy\check-lan.bat
REM ============================================================================
setlocal

set SITE=onealicialms.lan
set XAMPP=%~1
if "%XAMPP%"=="" set XAMPP=C:\xampp

pushd "%~dp0.."
set ROOT=%CD%
popd
set LOCALVHOST=%ROOT%\deploy\apache-vhost.local.conf

echo.
echo ============================================================
echo   LGU Alicia LMS - LAN reachability check
echo   Read-only. Nothing here changes any setting.
echo ============================================================

net session >nul 2>&1
if errorlevel 1 (
  echo.
  echo [!] Not running as administrator. The firewall checks below will be
  echo     incomplete. Right-click check-lan.bat, "Run as administrator".
)

REM --- 1. This machine's address ---------------------------------------------
echo.
echo [1/6] This server's addresses
echo ------------------------------------------------------------
set IPFILE=%TEMP%\lms-chk-ip.txt
del "%IPFILE%" 2>nul
powershell -NoProfile -Command "foreach ($a in [System.Net.Dns]::GetHostAddresses([System.Net.Dns]::GetHostName())) { if ($a.AddressFamily -eq 'InterNetwork') { $a.IPAddressToString } }" > "%IPFILE%" 2>nul
set HOSTIP=
if exist "%IPFILE%" for /f "usebackq delims=" %%s in ("%IPFILE%") do (
  echo       %%s
  if not "%%s"=="127.0.0.1" set HOSTIP=%%s
)
del "%IPFILE%" 2>nul
echo.
echo       A phone must be given THIS address. If the phone's own Wi-Fi
echo       address does not start with the same first three numbers, it is
echo       on a different network and nothing below will help -- check that
echo       it is on the office Wi-Fi and not a guest SSID or mobile data.

REM --- 2. Is Apache listening, and on what? ----------------------------------
REM
REM 0.0.0.0:443 means every interface, which is what is needed.
REM 127.0.0.1:443 means loopback only: the server can reach itself and no
REM other device on earth can, however open the firewall is.
echo.
echo [2/6] What is listening on 443 and 80
echo ------------------------------------------------------------
netstat -an | findstr /C:":443 " | findstr /C:"LISTENING"
netstat -an | findstr /C:":80 " | findstr /C:"LISTENING"
echo.
echo       Look for 0.0.0.0:443 -- every interface, which is correct.
echo       127.0.0.1:443 alone means loopback only: no other device can
echo       reach it no matter what the firewall says.

REM --- 3. The firewall PROFILE, which is the usual answer ---------------------
REM
REM The rules are scoped profile=private,domain. If Windows has classified
REM this network as Public -- which it does by default for a connection it
REM has not been told to trust -- the rules do not apply at all, and every
REM inbound connection is dropped silently. The server works perfectly on
REM itself and is invisible to the entire office.
echo.
echo [3/6] Which firewall profile is this network on
echo ------------------------------------------------------------
powershell -NoProfile -Command "try { foreach ($p in Get-NetConnectionProfile) { '      ' + $p.InterfaceAlias + '  =  ' + $p.NetworkCategory } } catch { '      (Get-NetConnectionProfile unavailable on this Windows)' }"
echo.
echo       It must say Private or DomainAuthenticated.
echo.
echo       If it says PUBLIC, that is almost certainly the problem: the
echo       firewall rules are scoped to private and domain networks, so on a
echo       Public network they do not apply and every inbound connection is
echo       dropped without a trace. Fix it in
echo         Settings ^> Network ^& Internet ^> ^(your Wi-Fi or Ethernet^)
echo       and set the network profile to Private. No restart needed.

REM --- 4. Are the rules actually there and enabled? ---------------------------
echo.
echo [4/6] The firewall rules
echo ------------------------------------------------------------
netsh advfirewall firewall show rule name="LGU Alicia LMS (HTTPS)" >nul 2>&1
if errorlevel 1 (
  echo       MISSING - run deploy\setup-https.bat as administrator.
) else (
  netsh advfirewall firewall show rule name="LGU Alicia LMS (HTTPS)" | findstr /C:"Enabled" /C:"Profiles" /C:"LocalPort" /C:"RemoteIP"
)

REM --- 5. Does the vhost answer to the IP as well as the name? ----------------
REM
REM Apache matches the request's Host header against ServerName and
REM ServerAlias. An IP matches neither unless it is listed, so a request to
REM https://192.168.254.102 falls through to the FIRST *:443 vhost Apache
REM loaded -- XAMPP's own _default_:443 for www.example.com, since httpd.conf
REM includes httpd-ssl.conf before httpd-vhosts.conf. The visitor gets XAMPP's
REM dashboard under XAMPP's certificate, with nothing to suggest this system
REM exists.
echo.
echo [5/6] Does the vhost answer to the IP, not just the name
echo ------------------------------------------------------------
if not exist "%LOCALVHOST%" (
  echo       %LOCALVHOST% is missing - run deploy\setup-https.bat.
) else (
  findstr /C:"ServerAlias" "%LOCALVHOST%"
  echo.
  echo       There should be a ServerAlias line carrying this server's IP.
  echo       Without it, https://^<ip^> reaches XAMPP's default site instead
  echo       of this system. If it is missing or commented, re-run
  echo       deploy\setup-https.bat.
)

REM --- 6. And who is allowed in ----------------------------------------------
echo.
echo [6/6] Which addresses the vhost allows
echo ------------------------------------------------------------
if exist "%LOCALVHOST%" findstr /C:"Require ip" "%LOCALVHOST%"
echo.
echo       A device outside these ranges gets 403 Forbidden, which is a
echo       DIFFERENT symptom from unreachable: it means the connection
echo       succeeded and Apache refused it.

echo.
echo ============================================================
echo   Reading the result
echo.
echo   Times out / "cannot be reached"  -^> steps 1, 2, 3
echo                                       usually the Public network profile
echo   403 Forbidden                    -^> step 6, the subnet
echo   XAMPP's dashboard appears        -^> step 5, the ServerAlias
echo   Certificate warning              -^> expected; trust-cert.bat, or
echo                                       tap through on a phone
echo.
echo   Still stuck? Test from another PC first. If a PC works and a phone
echo   does not, the router has client isolation ^(sometimes called AP
echo   isolation^) turned on, which blocks device-to-device traffic on
echo   Wi-Fi. That is a router setting, not a setting on this server.
echo ============================================================
echo.
pause
exit /b 0
