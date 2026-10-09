@echo off
chcp 65001 >nul
title Orquestador MultiTiendas - detener servicios
echo Deteniendo servicios del Orquestador...

REM Solo se detienen procesos de ESTE proyecto: otros proyectos que usen los mismos puertos
REM (p. ej. otro Vite en el 3001) no se tocan.
set "RAIZ=%~dp0"

REM --- 1) Ventanas que abrio iniciar.bat (backend y frontend), con todo lo que corre dentro ---
taskkill /FI "WINDOWTITLE eq Orquestador Backend*" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq Orquestador Frontend*" /T /F >nul 2>&1

REM --- 2) php / node del proyecto iniciados de otra forma: se reconocen por la carpeta del proyecto
REM        o, si es php.exe, por escuchar en el 8082 (puerto exclusivo del backend del Orquestador) ---
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$raiz = $env:RAIZ.TrimEnd('\');" ^
  "$back = @(Get-NetTCPConnection -LocalPort 8082 -State Listen -ErrorAction SilentlyContinue | ForEach-Object OwningProcess);" ^
  "Get-CimInstance Win32_Process -Filter \"Name='php.exe' OR Name='node.exe'\" |" ^
  "  Where-Object { ($_.CommandLine -and $_.CommandLine.IndexOf($raiz, [StringComparison]::OrdinalIgnoreCase) -ge 0) -or ($_.Name -eq 'php.exe' -and $back -contains $_.ProcessId) } |" ^
  "  ForEach-Object { Write-Host ('  Deteniendo ' + $_.Name + ' (PID ' + $_.ProcessId + ')'); if (-not $env:SOLO_LISTAR) { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue } }"

REM --- 3) MariaDB de XAMPP (3307) con cierre limpio. NO toca el MySQL del sistema (3306) ---
echo Deteniendo MariaDB XAMPP (3307)...
"C:\xampp\mysql\bin\mysqladmin.exe" -u root -P 3307 -h 127.0.0.1 shutdown >nul 2>&1
taskkill /FI "WINDOWTITLE eq Orquestador MySQL*" /T /F >nul 2>&1

echo Listo. Solo se detuvo el Orquestador; otros proyectos y tu MySQL del sistema (3306) siguen igual.
pause
