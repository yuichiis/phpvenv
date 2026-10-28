@echo off
setlocal

:: Capture the output of `php -r` into the `COMPOSER_HOME` variable.
for /f "delims=" %%i in ('php -r "echo trim(getenv('COMPOSER_HOME'));"') do set "COMPOSER_HOME=%%i"

if not defined COMPOSER_HOME (
    set "COMPOSER_HOME=%APPDATA%\Composer"
)
set "COMPOSER_PATH=%COMPOSER_HOME%\composer.phar"
set "DEFAULT_COMPOSER_PATH=%APPDATA%\Composer\composer.phar"

:: Check if the composer.phar file is present.
if exist "%COMPOSER_PATH%" (
    php "%COMPOSER_PATH%" %*
) else if exist "%DEFAULT_COMPOSER_PATH%" (
    php "%DEFAULT_COMPOSER_PATH%" %*
) else (
    php "%~dp0composer.phar" %*
)

endlocal