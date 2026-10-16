@echo off
setlocal
title Instalar topper. Render Farm
rem One-file installer for topper. Render Farm (built by tools/renderfarm/build.sh).
rem Below "exit /b" this file carries the installer script and the app as base64;
rem cmd never reads that far.
set "SELF=%~f0"
set "PS1=%TEMP%\topper-render-farm-setup.ps1"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p = (Get-Content -LiteralPath $env:SELF -Raw) -split ('#__'+'PS1__#'); $s = ($p[1] -split ('#__'+'ZIP__#'))[0]; Set-Content -LiteralPath $env:PS1 -Value $s -Encoding ASCII"
if errorlevel 1 goto failed
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS1%" "%SELF%"
if errorlevel 1 goto failed
del "%PS1%" >nul 2>&1
echo.
echo   Pronto. O render farm esta a abrir noutra janela (da primeira vez demora
echo   um ou dois minutos). A partir de agora basta o botao Abrir no admin.
echo.
timeout /t 15
exit /b 0

:failed
echo.
echo   A instalacao falhou - veja a mensagem acima e envie uma captura deste ecra.
echo.
pause
exit /b 1
