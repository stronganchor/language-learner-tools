@echo off
setlocal EnableExtensions

cd /d "%~dp0"

set "ROOT_DIR=%~dp0"
if "%ROOT_DIR:~-1%"=="\" set "ROOT_DIR=%ROOT_DIR:~0,-1%"
set "BUILDER_DIR=%ROOT_DIR%\offline-app-builder"
set "OUTPUT_APK=%BUILDER_DIR%\android\app\build\outputs\apk\debug\app-debug.apk"
set "APK_BASENAME="
set "EXIT_CODE=0"

if not exist "%BUILDER_DIR%\package.json" (
    echo offline-app-builder\package.json was not found.
    set "EXIT_CODE=1"
    goto finish
)

if "%~1"=="" (
    set /p "ZIP_INPUT=Path to LL Offline App Export zip: "
) else (
    set "ZIP_INPUT=%~1"
)

call :trim_value ZIP_INPUT
if not defined ZIP_INPUT (
    echo No zip path was provided.
    set "EXIT_CODE=1"
    goto finish
)

set "ZIP_INPUT=%ZIP_INPUT:"=%"

for %%I in ("%ZIP_INPUT%") do (
    set "ZIP_PATH=%%~fI"
    set "ZIP_DIR=%%~dpI"
    set "ZIP_NAME=%%~nI"
    set "ZIP_EXT=%%~xI"
)

if not exist "%ZIP_PATH%" (
    echo Bundle zip not found:
    echo %ZIP_PATH%
    set "EXIT_CODE=1"
    goto finish
)

if /I not "%ZIP_EXT%"==".zip" (
    echo Expected a .zip file:
    echo %ZIP_PATH%
    set "EXIT_CODE=1"
    goto finish
)

where node >nul 2>nul
if errorlevel 1 (
    echo Node.js was not found on PATH.
    set "EXIT_CODE=1"
    goto finish
)

where npm.cmd >nul 2>nul
if errorlevel 1 (
    echo npm was not found on PATH.
    set "EXIT_CODE=1"
    goto finish
)

if not exist "%BUILDER_DIR%\node_modules\" (
    echo Installing offline app builder dependencies...
    pushd "%BUILDER_DIR%" >nul
    call npm.cmd ci
    set "EXIT_CODE=%ERRORLEVEL%"
    popd >nul
    if not "%EXIT_CODE%"=="0" (
        echo Dependency install failed.
        goto finish
    )
)

echo.
echo Building debug APK from:
echo %ZIP_PATH%
echo.

pushd "%BUILDER_DIR%" >nul
call npm.cmd run build:debug -- "%ZIP_PATH%"
set "EXIT_CODE=%ERRORLEVEL%"
popd >nul

if not "%EXIT_CODE%"=="0" (
    echo APK build failed.
    goto finish
)

if not exist "%OUTPUT_APK%" (
    echo Build finished, but the APK was not found:
    echo %OUTPUT_APK%
    set "EXIT_CODE=1"
    goto finish
)

for /f "usebackq delims=" %%I in (`node "%BUILDER_DIR%\scripts\get-apk-name.mjs" 2^>nul`) do (
    if not defined APK_BASENAME set "APK_BASENAME=%%I"
)

if not defined APK_BASENAME (
    set "APK_BASENAME=%ZIP_NAME%"
)

set "DEST_APK=%ZIP_DIR%%APK_BASENAME%.apk"
copy /Y "%OUTPUT_APK%" "%DEST_APK%" >nul
if errorlevel 1 (
    echo APK built, but it could not be copied to:
    echo %DEST_APK%
    set "EXIT_CODE=1"
    goto finish
)

echo APK copied to:
echo %DEST_APK%

:finish
echo.
if not "%EXIT_CODE%"=="0" (
    echo Offline APK build failed.
) else (
    echo Offline APK build finished.
)

pause
exit /b %EXIT_CODE%

:trim_value
setlocal EnableDelayedExpansion
set "VALUE=!%~1!"
if not defined VALUE (
    endlocal & set "%~1=" & goto :eof
)

:trim_leading
if defined VALUE if "!VALUE:~0,1!"==" " (
    set "VALUE=!VALUE:~1!"
    goto trim_leading
)

:trim_trailing
if defined VALUE if "!VALUE:~-1!"==" " (
    set "VALUE=!VALUE:~0,-1!"
    goto trim_trailing
)

endlocal & set "%~1=%VALUE%"
goto :eof
