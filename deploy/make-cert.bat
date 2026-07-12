@echo off
REM Generate a self-signed TLS certificate for LAN HTTPS (Windows/XAMPP).
REM Usage: deploy\make-cert.bat lms.alicia.local
set CN=%1
if "%CN%"=="" set CN=lms.alicia.local
set DIR=%~dp0certs
if not exist "%DIR%" mkdir "%DIR%"
"C:\xampp\apache\bin\openssl.exe" req -x509 -nodes -days 825 -newkey rsa:2048 ^
  -keyout "%DIR%\lms.key" -out "%DIR%\lms.crt" ^
  -subj "/C=PH/ST=Isabela/L=Alicia/O=LGU Alicia/CN=%CN%" ^
  -addext "subjectAltName=DNS:%CN%,IP:127.0.0.1"
echo Created %DIR%\lms.crt and %DIR%\lms.key
pause
