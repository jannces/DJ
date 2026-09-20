@echo off
REM ============================================================================
REM  LGU Alicia LMS - what was removed, and does the system still behave?
REM
REM  Double-click this file, or run:  debug.bat
REM
REM  WHAT IT IS FOR
REM  Something stopped working and you suspect a block of code went missing --
REM  a line deleted while editing, a chunk lost to a bad paste, a file half
REM  reverted. This finds what was removed and prints it back out so you can
REM  put it where it was.
REM
REM  HOW IT KNOWS
REM  Git already records every line this project has ever had. A removal is not
REM  something that has to be guessed at: it is a minus sign in a diff. This
REM  script asks the three questions that matter in order -- what is removed but
REM  not committed, what is removed and staged, what your commits removed that
REM  the published branch still has -- and then runs the test suite, which is
REM  the only thing that can actually answer "does it still behave as before".
REM
REM  WHAT IT CANNOT DO
REM  If code was removed, committed and pushed weeks ago, and no test covered
REM  it, nothing here will notice. Git only knows what it was told. That is
REM  what the 800-odd tests are for, and why step 5 matters more than steps
REM  2 to 4.
REM
REM  IT CHANGES NOTHING. Every repair is printed as a command for you to run
REM  yourself -- restoring a file throws away whatever is in it now, and only
REM  you know whether that was wanted.
REM ============================================================================
setlocal

cd /d "%~dp0"

set REPORT=%CD%\debug-report.txt
set T=%TEMP%

echo.
echo ============================================================
echo   LGU Alicia LMS - removed-code check
echo   Folder: %CD%
echo ============================================================
echo.
echo   Nothing will be changed. Findings are printed, and the full
echo   detail is written to debug-report.txt
echo.

REM --- Prerequisites ---------------------------------------------------------
where git >nul 2>&1
if errorlevel 1 (
  echo [X] Git is not installed, or not on your PATH.
  echo     Without it there is no record of what the code used to be.
  goto :fail
)

if not exist "artisan" (
  echo [X] This does not look like the project folder.
  echo     The file "artisan" was not found next to debug.bat.
  goto :fail
)

git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
  echo [X] This folder is not a Git repository, so there is nothing to
  echo     compare against.
  goto :fail
)

for /f "delims=" %%b in ('git rev-parse --abbrev-ref HEAD') do set BRANCH=%%b
echo   Branch: %BRANCH%
echo.

echo LGU Alicia LMS - removed-code report > "%REPORT%"
echo Generated: %DATE% %TIME% >> "%REPORT%"
echo Branch: %BRANCH% >> "%REPORT%"
echo. >> "%REPORT%"

REM --- 1. Removed, not yet committed -----------------------------------------
REM
REM The common case by far: an edit made a few minutes ago that took out more
REM than it meant to. `git diff` compares the files as they are now against the
REM last commit, so every minus line here is something this working copy has
REM that the last good version did not.
echo [1/5] Code removed but not committed...
set UNC=0
git diff --numstat > "%T%\lms-dbg-1.txt" 2>nul
powershell -NoProfile -Command "@(Get-Content '%T%\lms-dbg-1.txt' -ErrorAction SilentlyContinue | Where-Object { $_ -match '^\d+\s+[1-9]' }).Count" > "%T%\lms-dbg-1c.txt" 2>nul
if exist "%T%\lms-dbg-1c.txt" for /f "usebackq delims=" %%n in ("%T%\lms-dbg-1c.txt") do set UNC=%%n

if "%UNC%"=="0" (
  echo       Nothing removed.
) else (
  echo       %UNC% file^(s^) have lines removed:
  echo.
  for /f "tokens=1,2,*" %%a in ('git diff --numstat') do if not "%%b"=="0" if not "%%b"=="-" echo         %%b line^(s^) gone from %%c
  echo.
  echo   ---------------------------------------------------------- >> "%REPORT%"
  echo   1. REMOVED BUT NOT COMMITTED >> "%REPORT%"
  echo   Lines beginning with - are what is missing. Put them back. >> "%REPORT%"
  echo   ---------------------------------------------------------- >> "%REPORT%"
  git diff >> "%REPORT%"
  echo.
  echo       Written to debug-report.txt. To put a whole file back exactly
  echo       as it was at the last commit:
  echo.
  echo           git checkout -- path\to\file
  echo.
  echo       That DISCARDS every change in that file, not only the removal.
  echo       Read the report first.
)

REM --- 2. Removed and staged -------------------------------------------------
REM
REM Separate from the above because `git diff` alone does not show it. A file
REM that has been `git add`ed is invisible to step 1, so a removal staged and
REM then forgotten would read as "nothing removed".
echo.
echo [2/5] Code removed and already staged...
set STG=0
git diff --cached --numstat > "%T%\lms-dbg-2.txt" 2>nul
powershell -NoProfile -Command "@(Get-Content '%T%\lms-dbg-2.txt' -ErrorAction SilentlyContinue | Where-Object { $_ -match '^\d+\s+[1-9]' }).Count" > "%T%\lms-dbg-2c.txt" 2>nul
if exist "%T%\lms-dbg-2c.txt" for /f "usebackq delims=" %%n in ("%T%\lms-dbg-2c.txt") do set STG=%%n

if "%STG%"=="0" (
  echo       Nothing removed.
) else (
  echo       %STG% staged file^(s^) have lines removed:
  echo.
  for /f "tokens=1,2,*" %%a in ('git diff --cached --numstat') do if not "%%b"=="0" if not "%%b"=="-" echo         %%b line^(s^) gone from %%c
  echo.
  echo   ---------------------------------------------------------- >> "%REPORT%"
  echo   2. REMOVED AND STAGED >> "%REPORT%"
  echo   ---------------------------------------------------------- >> "%REPORT%"
  git diff --cached >> "%REPORT%"
  echo       To unstage without losing anything:  git restore --staged .
)

REM --- 3. Removed by commits this PC has not pushed ---------------------------
REM
REM The case that looks like nothing is wrong. The working tree is clean, git
REM status says so, and the code is still missing -- because the removal was
REM committed. Comparing against the published branch is what finds it.
echo.
echo [3/5] Code removed by commits not yet pushed...
set UPSTREAM=
for /f "delims=" %%u in ('git rev-parse --abbrev-ref --symbolic-full-name @{u} 2^>nul') do set UPSTREAM=%%u

if "%UPSTREAM%"=="" (
  echo       This branch has no published copy to compare against - skipped.
) else (
  git fetch --quiet origin %BRANCH% >nul 2>&1
  set LOC=0
  git diff --numstat %UPSTREAM%..HEAD > "%T%\lms-dbg-3.txt" 2>nul
  powershell -NoProfile -Command "@(Get-Content '%T%\lms-dbg-3.txt' -ErrorAction SilentlyContinue | Where-Object { $_ -match '^\d+\s+[1-9]' }).Count" > "%T%\lms-dbg-3c.txt" 2>nul
  if exist "%T%\lms-dbg-3c.txt" for /f "usebackq delims=" %%n in ("%T%\lms-dbg-3c.txt") do set LOC=%%n
  call :report3
)
goto :tests

:report3
if "%LOC%"=="0" (
  echo       Nothing removed - this PC matches %UPSTREAM%.
  goto :eof
)
echo       %LOC% file^(s^) lost lines in commits that are only on this PC:
echo.
for /f "tokens=1,2,*" %%a in ('git diff --numstat %UPSTREAM%..HEAD') do if not "%%b"=="0" if not "%%b"=="-" echo         %%b line^(s^) gone from %%c
echo.
echo   ---------------------------------------------------------- >> "%REPORT%"
echo   3. REMOVED BY UNPUSHED COMMITS ^(vs %UPSTREAM%^) >> "%REPORT%"
echo   ---------------------------------------------------------- >> "%REPORT%"
git diff %UPSTREAM%..HEAD >> "%REPORT%"
echo       To take a file back to the published version:
echo.
echo           git checkout %UPSTREAM% -- path\to\file
goto :eof

:tests

REM --- 4. Is anything obviously broken right now? ----------------------------
echo.
echo [4/5] Checking the code parses...
if not exist "vendor\autoload.php" (
  echo       Libraries are not installed - run update.bat first. Skipped.
  goto :suite
)
php -l artisan >nul 2>&1
php artisan --version >nul 2>&1
if errorlevel 1 (
  echo [!] Laravel could not start at all. Something removed is load-bearing.
  echo     The report's step 1 and 3 sections are where to look.
  echo.
  php artisan --version
  echo.
)

:suite
REM --- 5. The test suite, which is the actual answer --------------------------
REM
REM Steps 1 to 3 find what changed. Only this finds what BROKE. Every rule this
REM system is supposed to follow has a test with a sentence explaining what it
REM is protecting, so a failure here names the behaviour that went missing --
REM which is closer to "put this block back" than any diff can get.
echo.
echo [5/5] Running the test suite - this takes a minute...
echo.
if not exist "vendor\autoload.php" (
  echo       Libraries are not installed - run update.bat first. Skipped.
  goto :summary
)

echo   ---------------------------------------------------------- >> "%REPORT%"
echo   5. TEST SUITE >> "%REPORT%"
echo   ---------------------------------------------------------- >> "%REPORT%"
php artisan test >> "%REPORT%" 2>&1
if errorlevel 1 (
  echo   [X] Tests FAILED. Each failure below names the behaviour that is
  echo       missing - that is the block to restore.
  echo.
  REM Only the failure lines. The full run is in the report.
  findstr /C:"FAILED" /C:"Tests:" /C:"⨯" "%REPORT%"
) else (
  echo   [OK] All tests pass. Whatever was removed, nothing the suite
  echo        covers behaves differently from before.
  findstr /C:"Tests:" "%REPORT%"
)

:summary
echo.
echo ============================================================
echo   Done. Full detail: debug-report.txt
echo.
echo   How to read it:
echo     Lines starting with -   were REMOVED  ^(put these back^)
echo     Lines starting with +   were ADDED
echo.
echo   If you want help, send me debug-report.txt - it has the
echo   removed blocks and the test failures in one place.
echo ============================================================
echo.
del "%T%\lms-dbg-1.txt" "%T%\lms-dbg-1c.txt" 2>nul
del "%T%\lms-dbg-2.txt" "%T%\lms-dbg-2c.txt" 2>nul
del "%T%\lms-dbg-3.txt" "%T%\lms-dbg-3c.txt" 2>nul
pause
exit /b 0

:fail
echo.
echo ============================================================
echo   Stopped. Nothing was changed.
echo ============================================================
echo.
pause
exit /b 1
