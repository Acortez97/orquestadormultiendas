@echo off
chcp 65001 >nul
title Orquestador MultiTiendas - servicios locales
echo ============================================
echo   Orquestador MultiTiendas - servicios locales
echo ============================================
echo.

REM --- 1) MariaDB de XAMPP en 3307 (forzado; NO toca el MySQL del sistema en 3306) ---
echo [1/3] Iniciando MariaDB (XAMPP, puerto 3307)...
start "Orquestador MySQL" /min "C:\xampp\mysql\bin\mysqld.exe" --defaults-file=C:\xampp\mysql\bin\my.ini --port=3307 --standalone
echo      Esperando a que MariaDB levante...
timeout /t 6 /nobreak >nul

REM --- 2) Backend PHP (puerto 8082). La BD y el JWT salen de backend\api\lib\config.local.php ---
echo [2/3] Iniciando Backend PHP (puerto 8082)...
start "Orquestador Backend" php -S 127.0.0.1:8082 -t "%~dp0backend\api"

REM --- 3) Frontend Vite (puerto 3001) ---
echo [3/3] Iniciando Frontend (Vite, puerto 3001)...
start "Orquestador Frontend" cmd /k "cd /d "%~dp0frontend" && npm run dev"

echo.
echo ============================================
echo   Servicios iniciados:
echo     - MariaDB  : 127.0.0.1:3307  (BD orquestadormultiendas)
echo     - Backend  : http://127.0.0.1:8082
echo     - Frontend : http://localhost:3001
echo.
echo   Primera vez (o para reinstalar todo):
echo     php backend\api\reset-db.php --go --demo
echo   Las credenciales generadas se imprimen al instalar.
echo ============================================
echo.
pause
