@echo off
REM ============================================================================
REM  Generate a self-signed TLS certificate for LAN HTTPS (Windows/XAMPP).
REM
REM  Usage: deploy\make-cert.bat [hostname] [xampp-folder] [nopause]
REM         deploy\make-cert.bat
REM         deploy\make-cert.bat onealicialms.local D:\xampp
REM ============================================================================
setlocal

set CN=%~1
if "%CN%"=="" set CN=onealicialms.local

set XAMPP=%~2
if "%XAMPP%"=="" set XAMPP=C:\xampp

set DIR=%~dp0certs
set OPENSSL=%XAMPP%\apache\bin\openssl.exe

if not exist "%OPENSSL%" (
  echo [X] openssl.exe not found at %OPENSSL%
  echo     Pass your XAMPP folder as the second argument.
  goto :fail
)

REM ---------------------------------------------------------------------------
REM  OPENSSL_CONF, or the certificate comes out with no subjectAltName.
REM
REM  XAMPP's openssl.exe is built with a default config path of
REM  C:\Apache24\conf\openssl.cnf -- a folder that does not exist on a XAMPP
REM  install. Without a config, `-addext subjectAltName=...` is dropped, and a
REM  certificate with no SAN is refused outright by Chrome and Edge
REM  (ERR_CERT_COMMON_NAME_INVALID). The old version of this script printed
REM  "Created ..." regardless, so the failure looked like success.
REM ---------------------------------------------------------------------------
set OPENSSL_CONF=
if exist "%XAMPP%\apache\conf\openssl.cnf" set OPENSSL_CONF=%XAMPP%\apache\conf\openssl.cnf
if not defined OPENSSL_CONF if exist "%XAMPP%\php\extras\openssl\openssl.cnf" set OPENSSL_CONF=%XAMPP%\php\extras\openssl\openssl.cnf
if not defined OPENSSL_CONF if exist "%XAMPP%\php\extras\ssl\openssl.cnf" set OPENSSL_CONF=%XAMPP%\php\extras\ssl\openssl.cnf

if not defined OPENSSL_CONF (
  echo [X] Could not find openssl.cnf under %XAMPP%.
  echo     Looked in apache\conf, php\extras\openssl and php\extras\ssl.
  echo     Without it the certificate would be issued with no subjectAltName,
  echo     and every browser would refuse it.
  goto :fail
)
echo       Using config: %OPENSSL_CONF%

if not exist "%DIR%" mkdir "%DIR%"

"%OPENSSL%" req -x509 -nodes -days 825 -newkey rsa:2048 ^
  -keyout "%DIR%\lms.key" -out "%DIR%\lms.crt" ^
  -subj "/C=PH/ST=Isabela/L=Alicia/O=LGU Alicia/CN=%CN%" ^
  -addext "subjectAltName=DNS:%CN%,DNS:localhost,IP:127.0.0.1"

if errorlevel 1 (
  echo [X] openssl failed - see the message above. No certificate was written.
  goto :fail
)

if not exist "%DIR%\lms.crt" ( echo [X] openssl reported success but wrote no certificate. & goto :fail )
if not exist "%DIR%\lms.key" ( echo [X] openssl wrote no private key. & goto :fail )

REM Verify rather than assume. "The file exists" is not the same as "the
REM browser will accept it", and the difference is exactly the SAN.
"%OPENSSL%" x509 -in "%DIR%\lms.crt" -noout -ext subjectAltName | findstr /C:"%CN%" >nul
if errorlevel 1 (
  echo [X] The certificate was written but carries no subjectAltName for %CN%.
  echo     Browsers will refuse it. Check that %OPENSSL_CONF% is readable.
  goto :fail
)

echo.
echo       Created %DIR%\lms.crt
echo       Created %DIR%\lms.key
echo       Valid 825 days, covering %CN%, localhost and 127.0.0.1.
echo.
echo       Import lms.crt into client trust stores to avoid browser warnings.

if /I "%~3"=="nopause" exit /b 0
pause
exit /b 0

:fail
if /I "%~3"=="nopause" exit /b 1
pause
exit /b 1
