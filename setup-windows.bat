@echo off
setlocal
cd /d "%~dp0"

echo ================================================
echo Trading Analysis Bot - First Time Setup
echo ================================================

where php >nul 2>nul || (echo ERROR: PHP is not available in PATH.& pause & exit /b 1)
where composer >nul 2>nul || (echo ERROR: Composer is not available in PATH.& pause & exit /b 1)

if not exist .env copy .env.example .env >nul
if not exist database\database.sqlite type nul > database\database.sqlite

call composer install
if errorlevel 1 (echo Composer install failed.& pause & exit /b 1)

php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear

echo.
echo Setup complete.
echo Add TWELVE_DATA_API_KEY to the .env file, then run start-server.bat.
pause
