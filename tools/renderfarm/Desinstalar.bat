@echo off
setlocal EnableExtensions
title Desinstalar topper. Render Farm
rem Removes the app, the topperfarm:// link and the Desktop shortcut.
rem Your settings and keys in %USERPROFILE%\.blenderfarm are left in place.
set "DEST=%LOCALAPPDATA%\TopperRenderFarm"
reg delete "HKCU\Software\Classes\topperfarm" /f >nul 2>&1
powershell -NoProfile -Command "Remove-Item -LiteralPath ([Environment]::GetFolderPath('Desktop')+'\topper. Render Farm.lnk') -ErrorAction SilentlyContinue" >nul 2>&1
if exist "%DEST%" rmdir /s /q "%DEST%"
echo.
echo   Removed. Your settings in %USERPROFILE%\.blenderfarm were kept.
echo.
pause
endlocal
