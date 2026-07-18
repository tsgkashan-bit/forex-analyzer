@echo off
cd /d "%~dp0"
if not exist vendor\autoload.php (
    echo Project is not installed yet. Run setup-windows.bat first.
    pause
    exit /b 1
)
start "" http://127.0.0.1:8000/trading
php artisan serve --host=127.0.0.1 --port=8000
