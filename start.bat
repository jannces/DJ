@echo off
REM ============================================================
REM  LGU Alicia LMS - START the system
REM
REM  Double-click this file, or run it from a terminal:
REM      start.bat          serve on this PC only (127.0.0.1)
REM      start.bat lan      serve to the whole local network
REM
REM  It starts MySQL, the queue worker and the web server, then
REM  opens your browser. Use stop.bat when you are finished.
REM ============================================================
setlocal enabledelayedexpansion

cd /d "%~dp0"

REM Change this if XAMPP is not installed in the default folder.
set XAMPP=C:\xampp
set PORT=8000

set BIND=127.0.0.1
if /I "%1"=="lan" set BIND=0.0.0.0

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

REM --- 3. Web server -----------------------------------------------------
echo [3/4] Starting the web server...
start "LGU Alicia - Web Server" cmd /c "php artisan serve --host=%BIND% --port=%PORT%"

set /a WAITED=0
:waitweb
timeout /t 1 /nobreak >nul
netstat -an | find ":%PORT%" | find "LISTENING" >nul
if not errorlevel 1 goto :webready
set /a WAITED+=1
if !WAITED! lss 20 goto :waitweb
echo [X] The web server did not start.
echo     Look at the "LGU Alicia - Web Server" window for the reason.
goto :fail

:webready

REM --- 4. Open the browser -----------------------------------------------
echo [4/4] Opening the browser...
start "" "http://127.0.0.1:%PORT%"

echo.
echo ============================================================
echo   The system is running.
echo.
echo   On this PC:      http://127.0.0.1:%PORT%
if /I "%1"=="lan" (
  echo   On the network:  use one of the addresses below, with :%PORT%
  for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /C:"IPv4"') do (
    for /f "tokens=*" %%b in ("%%a") do echo                    http://%%b:%PORT%
  )
  echo.
  echo   Set SESSION_SECURE_COOKIE=false in .env for network access,
  echo   because the LAN is served over plain HTTP.
)
echo.
echo   Two windows are now open and must stay open:
echo     - LGU Alicia - Web Server
echo     - LGU Alicia - Queue Worker
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
