@echo off
REM ============================================================
REM  LGU Alicia LMS - START the system
REM
REM  Double-click this file, or run it from a terminal:
REM      start.bat
REM
REM  It starts MySQL, the queue worker and Apache, then opens the
REM  browser at https://onealicialms.local. Use stop.bat to finish.
REM
REM  APACHE, NOT "php artisan serve". The dev server speaks plain
REM  HTTP on a port and cannot serve HTTPS at all, so it could never
REM  answer on https://onealicialms.local whatever .env says.
REM ============================================================
setlocal enabledelayedexpansion

cd /d "%~dp0"

REM Change this if XAMPP is not installed in the default folder.
set XAMPP=C:\xampp
set SITE=onealicialms.local

echo.
echo ============================================================
echo   LGU Alicia LMS - Starting
echo   Folder: %CD%
echo ============================================================
echo.

REM --- Checks ------------------------------------------------------------
where php >nul 2>&1
if errorlevel 1 (
  echo [X] PHP was not found on your PATH.
  echo     Add %XAMPP%\php to your PATH, or run this from the XAMPP shell.
  goto :fail
)

if not exist "artisan" (
  echo [X] This does not look like the project folder.
  echo     The file "artisan" was not found next to start.bat.
  goto :fail
)

if not exist "vendor\autoload.php" (
  echo [X] The PHP libraries are not installed yet.
  echo     Run this once, then try again:   composer install
  goto :fail
)

if not exist ".env" (
  echo [!] No .env file found - creating one from .env.example...
  copy /Y ".env.example" ".env" >nul
  php artisan key:generate
  echo.
  echo     Open .env and check DB_DATABASE, DB_USERNAME and DB_PASSWORD
  echo     before continuing. Press any key when you have.
  pause >nul
)

REM --- 1. MySQL ----------------------------------------------------------
echo [1/4] Checking MySQL...
netstat -an | find ":3306" | find "LISTENING" >nul
if not errorlevel 1 (
  echo       MySQL is already running.
  goto :mysqlready
)

if not exist "%XAMPP%\mysql\bin\mysqld.exe" (
  echo [X] MySQL was not found at %XAMPP%\mysql.
  echo     Start MySQL yourself from the XAMPP control panel, then run this again.
  echo     If XAMPP is installed elsewhere, edit the XAMPP line at the top of this file.
  goto :fail
)

echo       Starting MySQL...
start "LGU Alicia - MySQL" /MIN "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone

set /a WAITED=0
:waitmysql
timeout /t 1 /nobreak >nul
netstat -an | find ":3306" | find "LISTENING" >nul
if not errorlevel 1 goto :mysqlready
set /a WAITED+=1
if !WAITED! lss 30 goto :waitmysql
echo [X] MySQL did not come up within 30 seconds.
echo     Open the XAMPP control panel and check its error log.
goto :fail

:mysqlready
echo       MySQL is ready.

REM --- 2. Queue worker ---------------------------------------------------
REM One-time passwords and notifications are queued, so nothing reaches the
REM user unless a worker is running. Keep this window open.
echo [2/4] Starting the background worker...
start "LGU Alicia - Queue Worker" /MIN cmd /c "php artisan queue:work --tries=3"

REM --- 3. Web server (Apache, with HTTPS) --------------------------------
echo [3/4] Starting Apache...

if not exist "deploy\certs\lms.crt" (
  echo [X] No TLS certificate found at deploy\certs\lms.crt
  echo     Run this once, then try again:   deploy\make-cert.bat
  goto :fail
)

findstr /C:"%SITE%" %SystemRoot%\System32\drivers\etc\hosts >nul 2>&1
if errorlevel 1 (
  echo [!] "%SITE%" is not in this PC's hosts file, so the name will not
  echo     resolve here. Add this line to
  echo       %SystemRoot%\System32\drivers\etc\hosts
  echo     as Administrator:
  echo.
  echo       127.0.0.1   %SITE%
  echo.
  echo     On the other office PCs use the SERVER'S address instead of
  echo     127.0.0.1 -- or add one record on the router, which is one
  echo     edit rather than one per PC.
  echo.
)

netstat -an | findstr /C:":443 " | findstr /C:"LISTENING" >nul
if not errorlevel 1 (
  echo       Apache is already running.
  goto :webready
)

if not exist "%XAMPP%\apache\bin\httpd.exe" (
  echo [X] Apache was not found at %XAMPP%\apache.
  echo     Start it from the XAMPP control panel instead, then run this again.
  goto :fail
)

start "LGU Alicia - Apache" /MIN "%XAMPP%\apache\bin\httpd.exe"

set /a WAITED=0
:waitweb
timeout /t 1 /nobreak >nul
netstat -an | findstr /C:":443 " | findstr /C:"LISTENING" >nul
if not errorlevel 1 goto :webready
set /a WAITED+=1
if !WAITED! lss 20 goto :waitweb
echo [X] Apache did not start listening on port 443.
echo     Usual causes, in order:
echo       - mod_ssl is not enabled in httpd.conf
echo       - the vhost from deploy\apache-vhost.conf is not included
echo       - another program already holds port 443 (Skype, IIS, VMware)
echo     Check %XAMPP%\apache\logs\error.log for the reason.
goto :fail

:webready

REM --- 4. Open the browser -----------------------------------------------
echo [4/4] Opening the browser...
start "" "https://%SITE%"

echo.
echo ============================================================
echo   The system is running.
echo.
echo   Address:  https://%SITE%
echo.
echo   The browser warns about the certificate the first time on each
echo   PC. That is expected: it is signed by this office rather than
echo   bought from a public authority. Choose Advanced, then Continue.
echo   The connection is encrypted either way.
echo.
echo   Every PC needs "%SITE%" to point at this server -- one DNS
echo   record on the router, or a hosts-file line per PC. The server's
echo   IP will change when this moves to the agency, and that mapping
echo   is the only thing that has to change with it.
echo.
echo   Keep this helper window open:
echo     - LGU Alicia - Queue Worker
echo   Apache runs in the background; stop.bat closes it.
echo.
echo   Run stop.bat when you are finished.
echo ============================================================
echo.
pause
exit /b 0

:fail
echo.
echo ============================================================
echo   Startup stopped. Nothing further was changed.
echo ============================================================
echo.
pause
exit /b 1
