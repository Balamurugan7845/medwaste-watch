@echo off
setlocal
echo ===================================================
echo  MedWaste Watch - Local Web Server Launcher
echo  Smarter Medicine Management. Less Waste.
echo ===================================================

:: Detect PHP location
set PHP_BIN=php
where php >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    if exist "C:\xampp\php\php.exe" (
        set PHP_BIN=C:\xampp\php\php.exe
    ) else if exist "C:\laragon\bin\php\current\php.exe" (
        set PHP_BIN=C:\laragon\bin\php\current\php.exe
    ) else (
        echo [ERROR] PHP executable was not found in PATH or C:\xampp\php\php.exe.
        echo Please ensure XAMPP is installed or add PHP to your Windows Environment PATH.
        pause
        exit /b 1
    )
)

echo Starting local server at http://127.0.0.1:8000
echo Serving frontend and backend via router.php...
echo.
echo Press Ctrl+C in this terminal window to stop the server.
echo.

:: Open browser automatically
start http://127.0.0.1:8000/login.html

"%PHP_BIN%" -S 127.0.0.1:8000 "%~dp0router.php"
