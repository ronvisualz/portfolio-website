@echo off
setlocal EnableExtensions
title Instalar topper. Render Farm

rem Installs the render farm for this Windows user (no admin rights needed):
rem   1. copies the app to %LOCALAPPDATA%\TopperRenderFarm
rem   2. registers the topperfarm:// link so the "Abrir" button in the
rem      topper. admin (Tools tab) can start it
rem   3. adds a "topper. Render Farm" shortcut to the Desktop
rem Run it again to update: your settings and keys live in %USERPROFILE%\.blenderfarm
rem and are never touched.

set "SRC=%~dp0"
set "DEST=%LOCALAPPDATA%\TopperRenderFarm"

if not exist "%SRC%farmd\__main__.py" (
    echo.
    echo   Extract the whole ZIP first ^(right-click it, "Extract All"^),
    echo   then run Instalar.bat from the extracted folder.
    echo.
    pause
    exit /b 1
)

echo.
echo   Installing to %DEST%
if not exist "%DEST%" mkdir "%DEST%"
rem /E copies everything; the existing .venv in DEST is kept, so updates are quick.
robocopy "%SRC%." "%DEST%" /E /XD .venv .git __pycache__ /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 (
    echo   Could not copy the files to %DEST%.
    pause
    exit /b 1
)

rem ---- topperfarm:// link -> run Blender Farm.bat (it opens the browser, or
rem      just shows the app if it is already running) ---------------------------
set "KEY=HKCU\Software\Classes\topperfarm"
reg add "%KEY%" /ve /d "URL:topper. Render Farm" /f >nul
reg add "%KEY%" /v "URL Protocol" /d "" /f >nul
reg add "%KEY%\shell\open\command" /ve /d "\"%ComSpec%\" /c \"\"%DEST%\Blender Farm.bat\"\"" /f >nul

rem ---- Desktop shortcut ------------------------------------------------------
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\topper. Render Farm.lnk');" ^
  "$s.TargetPath='%DEST%\Blender Farm.bat'; $s.WorkingDirectory='%DEST%'; $s.Save()" >nul 2>&1

echo.
echo   Installed. From now on, the "Abrir" button in the topper. admin
echo   ^(Tools tab^) starts the render farm, and so does the Desktop shortcut.
echo.
echo   Starting it now. The first start sets things up and takes a minute or two.
echo.
start "" "%DEST%\Blender Farm.bat"
timeout /t 3 >nul
endlocal
