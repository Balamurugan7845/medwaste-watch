@echo off
setlocal
echo ===================================================
echo  MedWaste Watch - Automated Test Runner
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
        echo [ERROR] PHP executable was not found.
        pause
        exit /b 1
    )
)

"%PHP_BIN%" "%~dp0backend\tests\run-tests.php"

echo.
pause
