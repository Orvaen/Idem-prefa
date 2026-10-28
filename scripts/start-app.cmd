@echo off
setlocal
set "APP_BROWSER="
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set "APP_BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not defined APP_BROWSER if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set "APP_BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if not defined APP_BROWSER if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" set "APP_BROWSER=%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe"
if not defined APP_BROWSER if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "APP_BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not defined APP_BROWSER if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" set "APP_BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if not defined APP_BROWSER (
    echo Microsoft Edge ou Google Chrome est necessaire pour ouvrir la fenetre d'application.
    exit /b 1
)
start "" "%APP_BROWSER%" --app="http://localhost:8080/"
