@echo off
setlocal

cd /d "%~dp0"

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\release-plugin.ps1"
set "EXIT_CODE=%ERRORLEVEL%"

echo.
if not "%EXIT_CODE%"=="0" (
    echo Release failed.
) else (
    echo Release finished.
)

pause
exit /b %EXIT_CODE%
