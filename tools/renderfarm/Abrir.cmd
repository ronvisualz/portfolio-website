@echo off
rem Started by the topperfarm:// link (the Abrir button in the topper. admin) and the
rem Desktop shortcut. A Python installed by the installer is not yet on the PATH of
rem a browser that was already open, so put it there before starting the app.
if exist "%LOCALAPPDATA%\Programs\Python\Python313\python.exe" set "PATH=%LOCALAPPDATA%\Programs\Python\Python313;%LOCALAPPDATA%\Programs\Python\Python313\Scripts;%PATH%"
call "%~dp0Blender Farm.bat"
