@echo off
chcp 65001 >nul
title LEVOTEK - detener servicios
echo ============================================
echo   LEVOTEK - deteniendo servicios locales
echo ============================================
echo.

REM --- Backend PHP (8080) y Frontend Vite (3001/3002) por puerto ---
for %%P in (8080 3001 3002) do (
  for /f "tokens=5" %%I in ('netstat -ano ^| findstr ":%%P " ^| findstr LISTENING') do (
    echo Deteniendo puerto %%P (PID %%I)...
    taskkill /F /PID %%I >nul 2>&1
  )
)

REM --- MySQL de XAMPP (3307) con cierre limpio. NO toca el 3306 del sistema ---
echo Deteniendo MySQL XAMPP (3307)...
"C:\xampp\mysql\bin\mysqladmin.exe" -u root -P 3307 -h 127.0.0.1 shutdown >nul 2>&1

echo.
echo Listo. Tu MySQL del sistema (puerto 3306) NO se toca.
pause
