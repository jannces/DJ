@echo off
REM ============================================================
REM  LGU Alicia LMS - Update this PC from the GitHub repository
REM
REM  Double-click this file, or run it from Command Prompt:
REM      update.bat            (updates from the "main" branch)
REM      update.bat mybranch   (updates from another branch)
REM
REM  It backs up the database first, downloads the newest code,
REM  installs any new libraries, applies database changes and
REM  clears the caches. It stops safely if anything goes wrong.
REM ============================================================
setlocal

cd /d "%~dp0"

set BRANCH=%1
if "%BRANCH%"=="" set BRANCH=main

echo.
echo ============================================================
echo   LGU Alicia LMS - Update
echo   Folder: %CD%
echo   Branch: %BRANCH%
echo ============================================================
echo.

REM --- Check Git is installed -------------------------------------------
where git >nul 2>&1
if errorlevel 1 (
  echo [X] Git is not installed, or not on your PATH.
  echo     Install it from https://git-scm.com/download/win and try again.
  goto :fail
)

REM --- Check we are in the project folder --------------------------------
if not exist "artisan" (
  echo [X] This does not look like the project folder.
  echo     The file "artisan" was not found next to update.bat.
  goto :fail
)

REM --- Refuse to run if there are uncommitted local edits ------------------
git diff --quiet
if errorlevel 1 goto :dirty
git diff --cached --quiet
if errorlevel 1 goto :dirty
goto :clean

:dirty
echo [!] You have local changes that have not been committed:
echo.
git status --short
echo.
echo     Updating now could overwrite them.
echo     Commit them, or undo them with "git checkout .", then run this again.
echo.
echo     Note: your .env file is never touched by an update - it is not
echo     tracked by Git, so your database password and settings are safe.
goto :fail

:clean

REM --- Remember the current version so we can report what changed ---------
for /f "delims=" %%i in ('git rev-parse HEAD') do set BEFORE=%%i

REM --- Step 1: back up the database before changing anything --------------
if exist "vendor\autoload.php" (
  echo [1/6] Backing up the database...
  php artisan lms:backup
) else (
  echo [1/6] Skipping backup - libraries are not installed yet.
)

REM --- Step 2: download the newest code -----------------------------------
echo.
echo [2/6] Downloading the newest code...
git pull origin %BRANCH%
if errorlevel 1 (
  echo.
  echo [X] Download failed. Check your internet connection, then try again.
  goto :fail
)

for /f "delims=" %%i in ('git rev-parse HEAD') do set AFTER=%%i

if "%BEFORE%"=="%AFTER%" (
  echo.
  echo [OK] You already have the newest version - nothing to install.
  goto :done
)

REM --- Step 3: install any new PHP libraries ------------------------------
echo.
echo [3/6] Installing PHP libraries...
call composer install --no-interaction --prefer-dist
if errorlevel 1 (
  echo.
  echo [X] Installing libraries failed. Read the message above.
  goto :fail
)

REM --- Step 4: apply new database changes ---------------------------------
echo.
echo [4/6] Updating the database...
php artisan migrate --force
if errorlevel 1 (
  echo.
  echo [X] The database update failed.
  echo.
  echo     Your backup zip is in storage\app\backups.
  echo     To go back: unzip it, open phpMyAdmin, select the lms_alicia
  echo     database, use the Import tab and choose the .sql file inside.
  goto :fail
)

REM --- Step 5: keep uploaded files reachable ------------------------------
echo.
echo [5/6] Checking the storage link...
php artisan storage:link >nul 2>&1

REM --- Step 6: clear caches so the new code takes effect -------------------
echo.
echo [6/6] Clearing caches...
php artisan optimize:clear

:done
echo.
echo ============================================================
echo   Update finished.
echo ============================================================
git log --oneline %BEFORE%..HEAD
echo.
echo   Start the system with:  php artisan serve
echo   Then open:              http://127.0.0.1:8000
echo ============================================================
echo.
pause
exit /b 0

:fail
echo.
echo ============================================================
echo   Update stopped. Nothing further was changed.
echo ============================================================
echo.
pause
exit /b 1
