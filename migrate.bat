@echo off
setlocal
echo ===================================================
echo  MedWaste Watch - Database Migration and Seeder
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

echo Using PHP: %PHP_BIN%
"%PHP_BIN%" "%~dp0backend\database\migrate.php"

echo.
pause
