@echo off
REM ============================================================
REM  LGU Alicia LMS - UPDATE this PC from the GitHub repository
REM
REM  Double-click this file, or run it from a terminal:
REM      update.bat            update the branch you are on
REM      update.bat main       switch to and update another branch
REM
REM  It backs up the database first, downloads the newest code,
REM  installs any new libraries, applies database changes and
REM  clears the caches. It stops safely if anything goes wrong.
REM ============================================================
setlocal

cd /d "%~dp0"

echo.
echo ============================================================
echo   LGU Alicia LMS - Update
echo   Folder: %CD%
echo ============================================================
echo.

REM --- Tidy up old .env backups ------------------------------------------
REM
REM setup-https.bat copies .env every time it runs and it prunes its own
REM copies, but it is run once and then not again for weeks -- so on a machine
REM where the HTTPS setup took a few attempts, two dozen of them sit in the
REM project root until the next run that may never come. Twenty-five had
REM collected on one.
REM
REM They are pruned here as well because THIS is the script that gets run
REM regularly. Housekeeping belongs where the traffic is.
REM
REM Each one is a full copy of .env -- database password, APP_KEY, mail
REM credentials -- so this is tidiness and exposure at the same time. ONE is
REM kept, the newest: enough to undo the last setup run, which is the only one
REM anybody has ever wanted to undo. Keeping more is keeping more copies of the
REM database password lying around for no benefit.
set PRUNED=
del "%TEMP%\lms-envprune.txt" 2>nul
powershell -NoProfile -Command "$f=@(Get-ChildItem -Path '%CD%' -File -Force | Where-Object { $_.Name -like '.env.backup-*' } | Sort-Object LastWriteTime -Descending); if ($f.Count -gt 1) { $f | Select-Object -Skip 1 | Remove-Item -Force -ErrorAction SilentlyContinue; $f.Count - 1 } else { 0 }" > "%TEMP%\lms-envprune.txt" 2>nul
if exist "%TEMP%\lms-envprune.txt" for /f "usebackq delims=" %%n in ("%TEMP%\lms-envprune.txt") do set PRUNED=%%n
del "%TEMP%\lms-envprune.txt" 2>nul
if not "%PRUNED%"=="" if not "%PRUNED%"=="0" (
  echo   Tidied %PRUNED% old .env backup^(s^) - the newest is kept.
  echo.
)

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

REM --- Check the database is up ------------------------------------------
REM
REM Before the branch, the pull or composer. An update that cannot migrate has
REM nothing to offer, and finding that out at step 5 means the code on disk has
REM already moved while the database has not -- after which "Nothing further
REM was changed" is not true.
REM
REM Only when the libraries are installed, because artisan cannot run without
REM them. The fresh-install path is covered by the same check at step 5.
if exist "vendor\autoload.php" (
  php artisan lms:db-check
  if errorlevel 1 (
    echo.
    echo [X] The update cannot continue without the database.
    echo     Nothing has been changed.
    echo.
    echo     Run start.bat first - it starts MySQL and Apache - then run
    echo     update.bat again. The XAMPP Control Panel works too.
    goto :fail
  )
  echo.
)

REM --- Work out which branch to update -----------------------------------
REM Default to the branch already checked out, so you never have to remember
REM its name. Pass one as an argument only when you want to switch.
set BRANCH=%1
if "%BRANCH%"=="" (
  for /f "delims=" %%b in ('git rev-parse --abbrev-ref HEAD') do set BRANCH=%%b
)
echo   Branch: %BRANCH%
echo.

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
set HAVEBACKUP=
if exist "vendor\autoload.php" (
  echo [1/7] Backing up the database...
  php artisan lms:backup
  REM The exit code was never read. With MySQL down the backup failed, the
  REM script carried on, and step 5 then told the operator their backup zip was
  REM in storage\app\backups -- a file that had never been written. Pointing
  REM somebody at a safety net that does not exist is worse than having none.
  if errorlevel 1 (
    echo.
    echo [X] The backup failed, so the update stops here.
    echo     Migrating without one is how a bad update becomes unrecoverable.
    goto :fail
  )
  set HAVEBACKUP=1
) else (
  echo [1/7] Skipping backup - libraries are not installed yet.
)

REM --- Step 2: switch branch if a different one was asked for -------------
echo.
echo [2/7] Selecting the branch...
for /f "delims=" %%c in ('git rev-parse --abbrev-ref HEAD') do set CURRENT=%%c
if /I not "%CURRENT%"=="%BRANCH%" (
  git fetch origin %BRANCH%
  git checkout %BRANCH%
  if errorlevel 1 (
    echo.
    echo [X] Could not switch to "%BRANCH%". Check the branch name.
    goto :fail
  )
) else (
  echo       Already on %BRANCH%.
)

REM --- Step 3: download the newest code -----------------------------------
echo.
echo [3/7] Downloading the newest code...
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

REM --- Step 4: install any new PHP libraries ------------------------------
echo.
echo [4/7] Installing PHP libraries...
call composer install --no-interaction --prefer-dist
if errorlevel 1 (
  echo.
  echo [X] Installing libraries failed. Read the message above.
  goto :fail
)

REM --- Step 5: apply new database changes ---------------------------------
echo.
echo [5/7] Updating the database...

REM Again, because the pre-flight above is skipped on a first install and
REM because MySQL can stop between then and now.
php artisan lms:db-check >nul 2>&1
if errorlevel 1 (
  echo.
  php artisan lms:db-check
  echo.
  echo [X] The database update cannot run. The new code is already in place,
  echo     so run start.bat to bring MySQL up, then update.bat again.
  goto :fail
)

php artisan migrate --force
if errorlevel 1 (
  echo.
  echo [X] The database update failed.
  echo.
  REM Say it only if it is there. This told people about a zip that was never
  REM written whenever the backup step had failed.
  if defined HAVEBACKUP (
    echo     Your backup zip is in storage\app\backups.
    echo     To go back: unzip it, open phpMyAdmin, select the lms_alicia
    echo     database, use the Import tab and choose the .sql file inside.
  ) else (
    echo     NO BACKUP WAS TAKEN this run, so there is nothing to restore
    echo     from. The database is as the failed migration left it.
  )
  goto :fail
)

REM --- Step 6: keep uploaded files reachable ------------------------------
echo.
echo [6/7] Checking the storage link...
php artisan storage:link >nul 2>&1

REM --- Step 7: clear caches so the new code takes effect -------------------
echo.
echo [7/7] Clearing caches...
php artisan optimize:clear

:done
echo.
echo ============================================================
echo   Update finished.
echo ============================================================
git log --oneline %BEFORE%..HEAD
echo.
echo   If the system is running, restart it so the new code loads:
echo       stop.bat     then     start.bat
echo.
echo   Then press Ctrl+F5 in your browser. Most changes are in the
echo   stylesheet, and without a hard refresh you will still see the
echo   cached copy and think nothing happened.
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
