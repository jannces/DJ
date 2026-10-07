@echo off
REM ============================================================
REM  LGU Alicia LMS - build the SERVER package
REM
REM  Run this on the DEVELOPMENT PC (the one with Git, Composer
REM  and internet), not on the server:
REM      deploy\package-server.bat
REM
REM  It produces   dist\lms-server-YYYYMMDD-HHMM.zip
REM  holding only what the server needs to run, ready to copy over
REM  on a USB stick and unzip into C:\xampp\htdocs\lms.
REM
REM  WHAT GOES IN
REM    app, bootstrap, config, database, public, resources, routes,
REM    storage (empty folders), vendor (production libraries only),
REM    deploy, artisan, composer.json/.lock, .env.example,
REM    start.bat, stop.bat, the two user-manual PDFs and the
REM    deployment/admin guides.
REM
REM  WHAT STAYS OUT
REM    tests, developer docs, Git history, Node/Vite files, CI
REM    config, test.bat and update.bat (both need Git), the demo-data
REM    seeder, and -- because the package is built from the last
REM    COMMIT, not from the folder -- your .env, your certificate and
REM    key, your logs and any uploaded files. Those belong to one
REM    machine and are never shipped.
REM ============================================================
setlocal

cd /d "%~dp0.."

echo.
echo ============================================================
echo   LGU Alicia LMS - Build the server package
echo   Folder: %CD%
echo ============================================================
echo.

REM --- Checks ------------------------------------------------------------
where git >nul 2>&1
if errorlevel 1 (
  echo [X] Git is not installed, or not on your PATH.
  goto :fail
)
where composer >nul 2>&1
if errorlevel 1 (
  echo [X] Composer is not installed, or not on your PATH.
  goto :fail
)
where tar >nul 2>&1
if errorlevel 1 (
  echo [X] tar was not found. It ships with Windows 10 and later.
  goto :fail
)
if not exist "artisan" (
  echo [X] This does not look like the project folder.
  goto :fail
)

REM The package is the last commit. Edits that were never committed are
REM not in it, and that is easy to forget, so say so before building.
git diff --quiet HEAD -- 2>nul
if errorlevel 1 (
  echo [!] You have changes that are not committed. They will NOT be in
  echo     the package -- it is built from the last commit only.
  echo     Press Ctrl+C to stop and commit first, or any key to go on.
  pause >nul
  echo.
)

for /f "usebackq delims=" %%t in (`powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmm"`) do set STAMP=%%t
for /f "usebackq delims=" %%c in (`git rev-parse --short HEAD`) do set COMMIT=%%c
set "NAME=lms-server-%STAMP%"
set "STAGE=%CD%\dist\%NAME%"

if exist "%STAGE%" rmdir /s /q "%STAGE%"
mkdir "%STAGE%" || goto :fail

REM --- 1. The source files ------------------------------------------------
REM git archive copies only tracked files, so nothing local leaks in by
REM accident. The exclusions below are the development-only parts.
echo [1/4] Copying the application files from commit %COMMIT%...
git archive --format=tar -o "dist\%NAME%.tar" HEAD -- . ^
  ":(exclude)tests" ^
  ":(exclude).github" ^
  ":(exclude).claude" ^
  ":(exclude)docs" ^
  ":(exclude)phpunit.xml" ^
  ":(exclude)package.json" ^
  ":(exclude)package-lock.json" ^
  ":(exclude)vite.config.js" ^
  ":(exclude).editorconfig" ^
  ":(exclude).styleci.yml" ^
  ":(exclude)test.bat" ^
  ":(exclude)update.bat" ^
  ":(exclude)database/seeders/DemoDataSeeder.php" ^
  ":(exclude)deploy/package-server.bat"
if errorlevel 1 goto :fail
tar -xf "dist\%NAME%.tar" -C "%STAGE%" || goto :fail
del "dist\%NAME%.tar"

REM The few documents the people running the server actually open.
git archive --format=tar -o "dist\%NAME%-docs.tar" HEAD -- ^
  docs/LMS_User_Guide_Booklet_Print.pdf ^
  docs/LMS_User_Manual_Brochure.pdf ^
  docs/Deployment.md ^
  docs/AdminGuide.md ^
  docs/UserGuide.md
if errorlevel 1 goto :fail
tar -xf "dist\%NAME%-docs.tar" -C "%STAGE%" || goto :fail
del "dist\%NAME%-docs.tar"

REM --- 2. Production libraries -------------------------------------------
REM --no-dev leaves out PHPUnit, Faker and the other test tools: smaller,
REM and nothing on the server that only exists to test it.
echo [2/4] Installing the production PHP libraries (needs internet)...
pushd "%STAGE%"
call composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
if errorlevel 1 (
  popd
  echo [X] composer install failed - see the messages above.
  goto :fail
)
REM package:discover just wrote a cache of THIS PC's paths; the server
REM builds its own on first start.
del /q "bootstrap\cache\*.php" 2>nul
popd

REM --- 3. Record what this is --------------------------------------------
echo [3/4] Writing VERSION.txt...
(
  echo LGU Alicia LMS - server package
  echo Built:  %STAMP%
  echo Commit: %COMMIT%
) > "%STAGE%\VERSION.txt"

REM --- 4. Zip it ---------------------------------------------------------
echo [4/4] Compressing...
if exist "dist\%NAME%.zip" del "dist\%NAME%.zip"
tar -a -c -f "dist\%NAME%.zip" -C "dist" "%NAME%" || goto :fail
rmdir /s /q "%STAGE%"

echo.
echo ============================================================
echo   Package ready:
echo     %CD%\dist\%NAME%.zip
echo.
echo   On the server, follow "Installing on the server" in
echo   deploy\README.md -- unzip, .env, database, HTTPS, then
echo   deploy\make-shortcuts.bat for the desktop icons.
echo ============================================================
echo.
pause
exit /b 0

:fail
echo.
echo ============================================================
echo   Packaging stopped. Nothing on this PC was changed except
echo   the dist folder.
echo ============================================================
echo.
pause
exit /b 1
