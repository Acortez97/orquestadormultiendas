@echo off
chcp 65001 >nul
title LEVOTEK - servicios locales
echo ============================================
echo   LEVOTEK - iniciando servicios locales
echo ============================================
echo.

REM --- 1) MySQL de XAMPP (puerto 3307, NO toca el MySQL del sistema en 3306) ---
echo [1/3] Iniciando MySQL (XAMPP, puerto 3307)...
start "LEVOTEK MySQL" /min "C:\xampp\mysql\bin\mysqld.exe" --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
echo      Esperando a que MySQL levante...
timeout /t 6 /nobreak >nul

REM --- 2) Backend PHP (puerto 8080) ---
echo [2/3] Iniciando Backend PHP (puerto 8080)...
set "DB_HOST=127.0.0.1"
set "DB_PORT=3307"
set "DB_NAME=levotek"
set "DB_USER=root"
set "DB_PASS="
set "APP_DEBUG=true"
set "CORS_ORIGIN=*"
start "LEVOTEK Backend" "C:\xampp\php\php.exe" -S 127.0.0.1:8080 -t "%~dp0backend\api"

REM --- 3) Frontend Vite (puerto 3001) ---
echo [3/3] Iniciando Frontend (Vite, puerto 3001)...
start "LEVOTEK Frontend" cmd /k "cd /d "%~dp0frontend" && npm run dev"

echo.
echo ============================================
echo   Servicios iniciados:
echo     - MySQL    : 127.0.0.1:3307  (BD levotek)
echo     - Backend  : http://127.0.0.1:8080
echo     - Frontend : http://localhost:3001
echo.
echo   Entra en:  http://localhost:3001
echo   Usuario :  admin@levotek.mx
echo   Clave   :  admin123
echo ============================================
echo.
echo (Si es la PRIMERA vez, abre una sola vez:)
echo   http://127.0.0.1:8080/install.php?go=1
echo.
pause
