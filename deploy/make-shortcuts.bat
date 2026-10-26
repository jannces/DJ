@echo off
REM ============================================================
REM  LGU Alicia LMS - put START / STOP icons on the desktop
REM
REM  Run this ON THE SERVER, once, after the project is in place:
REM      deploy\make-shortcuts.bat         your desktop only
REM      deploy\make-shortcuts.bat all     every user's desktop
REM                                        (run as administrator)
REM
REM  WHY A SHORTCUT AND NOT THE .BAT ITSELF
REM  A .bat file cannot carry a picture -- Windows draws the same
REM  gear icon for every one of them. A shortcut can point at the
REM  .bat AND carry its own icon, so that is what this makes:
REM  "LGU Alicia LMS - Start" and "LGU Alicia LMS - Stop", both
REM  showing the municipal seal (deploy\lms.ico).
REM
REM  The shortcuts point at THIS folder. If the project is moved,
REM  run this again; it replaces the old shortcuts.
REM ============================================================
setlocal

cd /d "%~dp0.."

echo.
echo ============================================================
echo   LGU Alicia LMS - Desktop shortcuts
echo   Folder: %CD%
echo ============================================================
echo.

if not exist "start.bat" (
  echo [X] start.bat was not found in %CD%.
  echo     Keep this file in the project's "deploy" folder.
  goto :fail
)

if not exist "deploy\lms.ico" (
  echo [X] The icon deploy\lms.ico is missing.
  goto :fail
)

REM Passed through the environment rather than spliced into the command
REM line, so a folder with spaces or an apostrophe in its name still works.
set "LMS_DIR=%CD%"
set "LMS_WHERE=Desktop"
if /I "%~1"=="all" set "LMS_WHERE=CommonDesktopDirectory"

REM GetFolderPath, not %USERPROFILE%\Desktop: with OneDrive backup on, the
REM real desktop is OneDrive\Desktop and a shortcut written to the other one
REM never appears.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference = 'Stop';" ^
  "$desk = [Environment]::GetFolderPath($env:LMS_WHERE);" ^
  "$ws = New-Object -ComObject WScript.Shell;" ^
  "foreach ($s in @(@('Start','start.bat','Start the LGU Alicia Leave Management System'), @('Stop','stop.bat','Stop the LGU Alicia Leave Management System'))) {" ^
  "  $lnk = $ws.CreateShortcut((Join-Path $desk ('LGU Alicia LMS - ' + $s[0] + '.lnk')));" ^
  "  $lnk.TargetPath = (Join-Path $env:LMS_DIR $s[1]);" ^
  "  $lnk.WorkingDirectory = $env:LMS_DIR;" ^
  "  $lnk.IconLocation = (Join-Path $env:LMS_DIR 'deploy\lms.ico') + ',0';" ^
  "  $lnk.Description = $s[2];" ^
  "  $lnk.Save();" ^
  "  Write-Host ('      Created: ' + $lnk.FullName)" ^
  "}"
if errorlevel 1 (
  echo.
  echo [X] The shortcuts could not be created.
  if /I "%~1"=="all" echo     "all" writes to the shared desktop: run this as administrator.
  goto :fail
)

echo.
echo ============================================================
echo   Done. Double-click "LGU Alicia LMS - Start" to bring the
echo   system up, and "LGU Alicia LMS - Stop" to shut it down.
echo.
echo   If the icon shows as a blank page, Windows is still using
echo   its old icon cache -- sign out and back in, and it appears.
echo ============================================================
echo.
pause
exit /b 0

:fail
echo.
pause
exit /b 1
