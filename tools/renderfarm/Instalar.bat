@echo off
title Instalar topper. Render Farm
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
if errorlevel 1 (
    echo.
    echo   Installation failed - see the message above.
    echo.
    pause
    exit /b 1
)
echo.
echo   Installed. A second black window is starting the render farm now
echo   ^(the first start takes a minute or two^). From now on the "Abrir"
echo   button in the topper. admin, Tools tab, starts it.
echo.
pause
