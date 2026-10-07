@echo off
REM ============================================================
REM  LGU Alicia LMS - STOP the system
REM
REM  Double-click this file, or run it from a terminal:
REM      stop.bat           stop the web server and the worker
REM      stop.bat all       also shut MySQL down
REM
REM  MySQL is left running by default, because phpMyAdmin needs
REM  it and you often still want to look at the database.
REM ============================================================
setlocal

cd /d "%~dp0"

set XAMPP=C:\xampp

echo.
echo ============================================================
echo   LGU Alicia LMS - Stopping
echo ============================================================
echo.

REM --- 1. The web server and the worker ----------------------------------
REM Apache serves the site now, not "php artisan serve", so stopping the
REM window start.bat opened is no longer enough -- httpd keeps running in the
REM background and port 443 stays held. Stop the process itself.
echo [1/3] Stopping Apache...
taskkill /FI "WINDOWTITLE eq LGU Alicia - Apache*" /T /F >nul 2>&1
taskkill /IM httpd.exe /F >nul 2>&1
if errorlevel 1 (echo       Not running.) else (echo       Stopped.)

echo [2/3] Closing the background worker...
taskkill /FI "WINDOWTITLE eq LGU Alicia - Queue Worker*" /T /F >nul 2>&1
if errorlevel 1 (echo       Not running.) else (echo       Stopped.)

REM --- 2. Anything started by hand ---------------------------------------
REM A serve or worker launched from your own terminal has a different window
REM title, so match on what the process is actually running instead.
powershell -NoProfile -Command "$p = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match 'artisan (serve|queue:work)' }); if ($p.Count -gt 0) { $p | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }; Write-Host ('      Also stopped ' + $p.Count + ' process(es) started by hand.') }" 2>nul

REM --- 3. MySQL, only when asked -----------------------------------------
if /I not "%1"=="all" (
  echo [3/3] Leaving MySQL running.
  echo       Use "stop.bat all" if you want it shut down too.
  goto :done
)

echo [3/3] Shutting MySQL down...
netstat -an | find ":3306" | find "LISTENING" >nul
if errorlevel 1 (
  echo       MySQL was not running.
  goto :done
)

REM Ask it to close cleanly first; only force it if that fails.
if exist "%XAMPP%\mysql\bin\mysqladmin.exe" (
  "%XAMPP%\mysql\bin\mysqladmin.exe" -u root shutdown >nul 2>&1
)
timeout /t 3 /nobreak >nul
netstat -an | find ":3306" | find "LISTENING" >nul
if not errorlevel 1 (
  echo       It did not close cleanly - forcing it.
  taskkill /IM mysqld.exe /F >nul 2>&1
)
echo       Stopped.

:done
echo.
echo ============================================================
echo   The system is stopped. Run start.bat to bring it back up.
echo ============================================================
echo.
pause
exit /b 0
