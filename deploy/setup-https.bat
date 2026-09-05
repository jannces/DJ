@echo off
REM ============================================================================
REM  LGU Alicia LMS - one-time HTTPS setup for XAMPP.
REM
REM  Right-click this file and choose "Run as administrator".
REM  It needs that to write into C:\xampp and the hosts file.
REM
REM  Usage:   deploy\setup-https.bat            (XAMPP at C:\xampp)
REM           deploy\setup-https.bat D:\xampp   (XAMPP somewhere else)
REM
REM  WHAT IT CHANGES, and it says so as it goes:
REM    1. .env                      APP_URL + SESSION_SECURE_COOKIE
REM    2. httpd.conf                enables mod_ssl, mod_rewrite, the two Includes
REM    3. httpd-vhosts.conf         includes this project's vhost
REM    4. apache-vhost.local.conf   GENERATED from the template with your real
REM                                 project path and your real subnet
REM    5. deploy\certs\             the self-signed certificate, if missing
REM    6. hosts                     onealicialms.local -> 127.0.0.1
REM
REM  Every file it edits is backed up next to itself first, with a timestamp.
REM  It can be run twice: nothing is appended or enabled a second time.
REM ============================================================================
setlocal

set SITE=onealicialms.local
set XAMPP=%~1
if "%XAMPP%"=="" set XAMPP=C:\xampp

REM The project root is the folder above this script.
pushd "%~dp0.."
set ROOT=%CD%
popd

set CONF=%XAMPP%\apache\conf\httpd.conf
set VHOSTS=%XAMPP%\apache\conf\extra\httpd-vhosts.conf
set TEMPLATE=%ROOT%\deploy\apache-vhost.conf
set LOCALVHOST=%ROOT%\deploy\apache-vhost.local.conf
set HOSTSFILE=%SystemRoot%\System32\drivers\etc\hosts

echo.
echo ============================================================
echo   LGU Alicia LMS - HTTPS setup
echo   Project : %ROOT%
echo   XAMPP   : %XAMPP%
echo   Address : https://%SITE%
echo ============================================================
echo.

REM --- Administrator? -------------------------------------------------------
REM net session fails for a standard user. Checked up front rather than
REM half-way through, so this never leaves the config partly edited.
net session >nul 2>&1
if errorlevel 1 (
  echo [X] This must be run as administrator.
  echo     Close this window, right-click setup-https.bat and choose
  echo     "Run as administrator".
  goto :fail
)

REM --- Everything it needs, checked before anything is written --------------
if not exist "%CONF%"     ( echo [X] Not found: %CONF%   & echo     Pass your XAMPP folder as an argument. & goto :fail )
if not exist "%VHOSTS%"   ( echo [X] Not found: %VHOSTS% & goto :fail )
if not exist "%TEMPLATE%" ( echo [X] Not found: %TEMPLATE% & goto :fail )
if not exist "%ROOT%\public\index.php" ( echo [X] %ROOT%\public\index.php is missing - wrong folder? & goto :fail )
where php >nul 2>&1
if errorlevel 1 ( echo [X] PHP is not on your PATH. Add %XAMPP%\php to it. & goto :fail )

REM A timestamp for the backup filenames, from PowerShell rather than %DATE%.
REM %DATE% is formatted by the machine's locale: on one Windows it reads
REM "Fri 09/05/2026" and slicing it gives digits, on another it reads
REM "09/05/2026" and the same slice returns "5/" -- a slash, which is illegal
REM in a filename, so every backup copy fails and the script edits the config
REM with nothing to restore from. That would only break on some machines.
for /f "usebackq delims=" %%t in (`powershell -NoProfile -Command "Get-Date -Format 'yyyyMMdd-HHmmss'"`) do set STAMP=%%t
if "%STAMP%"=="" set STAMP=backup

REM --- 1. .env ---------------------------------------------------------------
echo [1/6] Setting APP_URL and SESSION_SECURE_COOKIE in .env...
if not exist "%ROOT%\.env" (
  echo       No .env yet - copying .env.example.
  copy /Y "%ROOT%\.env.example" "%ROOT%\.env" >nul
  php artisan key:generate
)
copy /Y "%ROOT%\.env" "%ROOT%\.env.backup-%STAMP%" >nul
REM Replace-only: .env is copied from .env.example above when absent, and that
REM file carries both keys, so there is never a missing line to append. Avoids
REM escaping quotes inside a PowerShell command inside a batch file.
powershell -NoProfile -Command "$p='%ROOT%\.env'; $c=Get-Content $p; $c=$c -replace '^APP_URL=.*$','APP_URL=https://%SITE%'; $c=$c -replace '^SESSION_SECURE_COOKIE=.*$','SESSION_SECURE_COOKIE=true'; Set-Content -Path $p -Value $c"
if errorlevel 1 ( echo [X] Could not edit .env & goto :fail )
echo       Backed up to .env.backup-%STAMP%

REM A cached config ignores .env completely, which is how a corrected APP_URL
REM appears to have no effect at all.
php artisan config:clear >nul 2>&1
echo       Config cache cleared.

REM --- 2. httpd.conf ---------------------------------------------------------
echo [2/6] Enabling mod_ssl, mod_rewrite and the Includes...
copy /Y "%CONF%" "%CONF%.backup-%STAMP%" >nul
powershell -NoProfile -Command ^
  "$p='%CONF%'; $c=Get-Content $p;" ^
  "$c=$c -replace '^\s*#\s*(LoadModule\s+ssl_module\s.*)$','$1';" ^
  "$c=$c -replace '^\s*#\s*(LoadModule\s+rewrite_module\s.*)$','$1';" ^
  "$c=$c -replace '^\s*#\s*(Include\s+conf/extra/httpd-ssl\.conf.*)$','$1';" ^
  "$c=$c -replace '^\s*#\s*(Include\s+conf/extra/httpd-vhosts\.conf.*)$','$1';" ^
  "Set-Content -Path $p -Value $c"
if errorlevel 1 ( echo [X] Could not edit httpd.conf & goto :fail )
echo       Backed up to httpd.conf.backup-%STAMP%

REM --- 3. The vhost, generated with YOUR paths and YOUR subnet ---------------
echo [3/6] Writing %LOCALVHOST%...

REM The subnet is read off this machine rather than guessed. The template ships
REM 192.168.254.0/24; on any other network that value serves every client a
REM flat 403, which looks like a broken site rather than a config line.
REM
REM Via a temp file, NOT `for /f ... in (`powershell ...`)`. Inside a backtick
REM FOR block cmd parses the command itself, so the pipe in the PowerShell
REM pipeline has to be escaped and the whole thing is fragile -- it is why this
REM step reported "Could not read this machine's IPv4 address" on the first
REM real run. Outside a FOR block a pipe inside double quotes is literal, and
REM cmd leaves it alone.
REM
REM .NET's DNS lookup rather than Get-NetIPAddress: no module to be missing,
REM works on every PowerShell that ships with Windows.
set IPFILE=%TEMP%\lms-setup-ip.txt
del "%IPFILE%" 2>nul
powershell -NoProfile -Command "@([System.Net.Dns]::GetHostAddresses([System.Net.Dns]::GetHostName()) | Where-Object { $_.AddressFamily -eq 'InterNetwork' } | ForEach-Object { $_.IPAddressToString } | Where-Object { $_ -notlike '127.*' -and $_ -notlike '169.254.*' })[0]" > "%IPFILE%" 2>nul

set HOSTIP=
if exist "%IPFILE%" for /f "usebackq delims=" %%s in ("%IPFILE%") do set HOSTIP=%%s
del "%IPFILE%" 2>nul

set SUBNET=
if not "%HOSTIP%"=="" for /f "tokens=1,2,3 delims=." %%a in ("%HOSTIP%") do set SUBNET=%%a.%%b.%%c.0/24

if "%SUBNET%"=="" (
  echo [!] Could not read this machine's IPv4 address.
  echo     Falling back to 127.0.0.1 only - this PC will work, the rest of
  echo     the office will get 403 until you set the subnet by hand in
  echo     %LOCALVHOST%
  set SUBNET=127.0.0.1/32
) else (
  echo       This PC: %HOSTIP%   subnet: %SUBNET%
)

set FSROOT=%ROOT:\=/%
powershell -NoProfile -Command ^
  "$c=Get-Content -Raw '%TEMPLATE%';" ^
  "$c=$c -replace 'C:/xampp/htdocs/lms','%FSROOT%';" ^
  "$c=$c -replace 'Require ip 192\.168\.254\.0/24','Require ip %SUBNET%';" ^
  "$c='# GENERATED by deploy\setup-https.bat - edit apache-vhost.conf instead.' + [Environment]::NewLine + $c;" ^
  "Set-Content -Path '%LOCALVHOST%' -Value $c -NoNewline"
if errorlevel 1 ( echo [X] Could not write %LOCALVHOST% & goto :fail )
echo       DocumentRoot: %FSROOT%/public

REM --- 4. Include it from httpd-vhosts.conf -----------------------------------
echo [4/6] Including it from httpd-vhosts.conf...
findstr /C:"apache-vhost.local.conf" "%VHOSTS%" >nul 2>&1
if not errorlevel 1 (
  echo       Already included.
) else (
  copy /Y "%VHOSTS%" "%VHOSTS%.backup-%STAMP%" >nul
  echo.>> "%VHOSTS%"
  echo # LGU Alicia LMS >> "%VHOSTS%"
  echo Include "%FSROOT%/deploy/apache-vhost.local.conf" >> "%VHOSTS%"
  echo       Added. Backed up to httpd-vhosts.conf.backup-%STAMP%
)

REM --- 5. Certificate ---------------------------------------------------------
echo [5/6] Certificate...
REM "The file exists" is not the same as "the browser will accept it". A
REM certificate issued without a readable openssl.cnf carries no
REM subjectAltName, and every browser refuses that outright -- so an existing
REM certificate is CHECKED, and replaced if it is one of those.
set CERTOK=
if exist "%ROOT%\deploy\certs\lms.crt" (
  "%XAMPP%\apache\bin\openssl.exe" x509 -in "%ROOT%\deploy\certs\lms.crt" -noout -ext subjectAltName 2>nul | findstr /C:"%SITE%" >nul
  if not errorlevel 1 set CERTOK=1
)

if defined CERTOK (
  echo       Already present and covers %SITE% - keeping it.
) else (
  if exist "%ROOT%\deploy\certs\lms.crt" (
    echo       Existing certificate has no subjectAltName for %SITE% - replacing it.
    copy /Y "%ROOT%\deploy\certs\lms.crt" "%ROOT%\deploy\certs\lms.crt.backup-%STAMP%" >nul
  )
  call "%ROOT%\deploy\make-cert.bat" %SITE% "%XAMPP%" nopause
  if errorlevel 1 ( echo [X] The certificate could not be created - see above. & goto :fail )
)

REM --- 6. hosts ---------------------------------------------------------------
echo [6/6] hosts file...
findstr /C:"%SITE%" "%HOSTSFILE%" >nul 2>&1
if not errorlevel 1 (
  echo       Already mapped.
) else (
  copy /Y "%HOSTSFILE%" "%HOSTSFILE%.backup-%STAMP%" >nul
  echo.>> "%HOSTSFILE%"
  echo 127.0.0.1       %SITE% >> "%HOSTSFILE%"
  echo       Added 127.0.0.1 %SITE%
)

REM --- Prove the config parses before anyone tries to start it ---------------
echo.
echo Checking the Apache configuration...
"%XAMPP%\apache\bin\httpd.exe" -t
if errorlevel 1 (
  echo.
  echo [X] Apache rejected the configuration - see the message above.
  echo     Nothing has been started. Your originals are beside each file,
  echo     named *.backup-%STAMP%
  goto :fail
)

echo.
echo ============================================================
echo   Done. Now run start.bat
echo.
echo   Address:  https://%SITE%
echo.
echo   The browser warns about the certificate the first time on
echo   each PC. That is expected - it is signed by this office,
echo   not bought from a public authority. Advanced, then Continue.
echo.
echo   ON THE OTHER OFFICE PCs, add this line to
echo     %%SystemRoot%%\System32\drivers\etc\hosts   (as administrator)
echo   using THIS server's address, not 127.0.0.1:
echo.
if not "%HOSTIP%"=="" echo       %HOSTIP%   %SITE%
if "%HOSTIP%"=="" echo       ^<this server's IP^>   %SITE%
echo.
echo   Better still: one DNS record on the router - one edit rather
echo   than one per PC, and one edit when the IP changes.
echo ============================================================
echo.
pause
exit /b 0

:fail
echo.
echo ============================================================
echo   Setup stopped. Any file already edited has a backup beside
echo   it named *.backup-%STAMP%
echo ============================================================
echo.
pause
exit /b 1
