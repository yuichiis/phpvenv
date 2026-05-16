<?php

declare(strict_types=1);

namespace Rindow\PhpVenv;

class EnvironmentCreator
{
    private Filesystem $fs;
    private bool $isWindows;

    public function __construct(Filesystem $fs, ?bool $isWindows = null)
    {
        $this->fs = $fs;
        $this->isWindows = $isWindows ?? (DIRECTORY_SEPARATOR === '\\');
    }

    public function create(string $envName, string $cwd): void
    {
        $envDir = $cwd . DIRECTORY_SEPARATOR . $envName;

        if ($this->fs->exists($envDir)) {
            throw new \RuntimeException("Directory already exists: {$envDir}");
        }

        $binDir = $this->isWindows
            ? $envDir . DIRECTORY_SEPARATOR . 'Scripts'
            : $envDir . DIRECTORY_SEPARATOR . 'bin';

        $cliDir = $envDir . DIRECTORY_SEPARATOR . 'cli';
        $confDDir = $cliDir . DIRECTORY_SEPARATOR . 'conf.d';

        $this->fs->mkdir($envDir);
        $this->fs->mkdir($binDir);
        $this->fs->mkdir($cliDir);
        $this->fs->mkdir($confDDir);

        echo "Creating virtual environment: {$envDir}\n";

        $this->copyConfiguration($cliDir, $confDDir);
        $this->generateScripts($envName, $envDir, $binDir, $cliDir, $confDDir);

        $this->printActivationHint($envName, $envDir);
    }

    private function copyConfiguration(string $cliDir, string $confDDir): void
    {
        $loadedIni = php_ini_loaded_file();
        if ($loadedIni && $this->fs->exists($loadedIni)) {
            $this->fs->copy($loadedIni, $cliDir . DIRECTORY_SEPARATOR . 'php.ini');
            echo "Copied php.ini\n";
        }

        $scanned = php_ini_scanned_files();
        if ($scanned) {
            $files = array_map('trim', explode(',', $scanned));
            foreach ($files as $file) {
                if ($file && $this->fs->exists($file)) {
                    $this->fs->copy($file, $confDDir . DIRECTORY_SEPARATOR . basename($file));
                }
            }
            echo "Copied additional ini files\n";
        }
    }

    private function generateScripts(string $envName, string $envDir, string $binDir, string $cliDir, string $confDDir): void
    {
        $phpBinary = PHP_BINARY;

        // Windows Batch scripts
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'activate.bat', $this->getActivateBat($envName, $envDir, $binDir, $confDDir));
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'deactivate.bat', $this->getDeactivateBat());
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'php.bat', $this->getPhpBat($phpBinary, $cliDir));

        // PowerShell
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'Activate.ps1', $this->getActivatePs1($envName, $envDir, $binDir, $confDDir));
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'php.ps1', $this->getPhpPs1($phpBinary, $cliDir));

        // Bash
        $this->writeUnixFile($binDir . DIRECTORY_SEPARATOR . 'activate', $this->getActivateSh($envName, $envDir, $binDir, $confDDir));
        if (!$this->isWindows) {
            $this->writeUnixFile($binDir . DIRECTORY_SEPARATOR . 'php', $this->getPhpSh($phpBinary, $cliDir));
        }
    }

    private function writeUnixFile(string $path, string $content): void
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $this->fs->put($path, $content);
        $this->fs->chmod($path, 0755);
    }

    private function writeWindowsFile(string $path, string $content): void
    {
        $content = str_replace(["\r\n", "\n"], "\r\n", $content);
        $this->fs->put($path, $content);
    }

    private function printActivationHint(string $envName, string $envDir): void
    {
        echo "\nActivate:\n";
        if ($this->isWindows) {
            echo "  {$envDir}\\Scripts\\activate.bat\n";
            echo "  powershell -ExecutionPolicy Bypass -File {$envDir}\\Scripts\\Activate.ps1\n";
        } else {
            echo "  source {$envDir}/bin/activate\n";
        }
    }

    private function getActivateBat(string $envName, string $envDir, string $binDir, string $confDDir): string
    {
        return <<<BAT
@echo off
set "_OLD_VIRTUAL_PROMPT=%PROMPT%"
set "_OLD_VIRTUAL_PATH=%PATH%"
set "_OLD_PHP_INI_SCAN_DIR=%PHP_INI_SCAN_DIR%"
set "_OLD_COMPOSER_HOME=%COMPOSER_HOME%"
set "VIRTUAL_ENV={$envDir}"
set "PATH=%VIRTUAL_ENV%\\composer\\vendor\\bin;{$binDir};%PATH%"
set "PHP_INI_SCAN_DIR={$confDDir}"
set "PROMPT=({$envName}) %PROMPT%"
set "COMPOSER_HOME=%VIRTUAL_ENV%\composer"
doskey deactivate={$binDir}\\deactivate.bat
BAT;
    }

    private function getDeactivateBat(): string
    {
        return <<<BAT
@echo off
if defined _OLD_VIRTUAL_PROMPT (set "PROMPT=%_OLD_VIRTUAL_PROMPT%")
if defined _OLD_VIRTUAL_PATH (set "PATH=%_OLD_VIRTUAL_PATH%")
if defined _OLD_PHP_INI_SCAN_DIR (set "PHP_INI_SCAN_DIR=%_OLD_PHP_INI_SCAN_DIR%") else (set "PHP_INI_SCAN_DIR=")
if defined _OLD_COMPOSER_HOME (set "COMPOSER_HOME=%_OLD_COMPOSER_HOME%") else (set "COMPOSER_HOME=")
set _OLD_COMPOSER_HOME=
set _OLD_VIRTUAL_PROMPT=
set _OLD_VIRTUAL_PATH=
set _OLD_PHP_INI_SCAN_DIR=
set VIRTUAL_ENV=
BAT;
    }

    private function getActivatePs1(string $envName, string $envDir, string $binDir, string $confDDir): string
    {
        return <<<PS1
\$env:_OLD_VIRTUAL_PATH = \$env:PATH
\$env:_OLD_PHP_INI_SCAN_DIR = \$env:PHP_INI_SCAN_DIR
\$env:_OLD_COMPOSER_HOME = \$env:COMPOSER_HOME
if (Test-Path function:_OLD_VIRTUAL_PROMPT) { Remove-Item function:_OLD_VIRTUAL_PROMPT -ErrorAction SilentlyContinue }
Copy-Item function:prompt function:_OLD_VIRTUAL_PROMPT
\$env:VIRTUAL_ENV = "{$envDir}"
\$env:PATH = "\$env:VIRTUAL_ENV\\composer\\vendor\\bin;{$binDir};" + \$env:PATH
\$env:PHP_INI_SCAN_DIR = "{$confDDir}"
\$env:COMPOSER_HOME = "\$env:VIRTUAL_ENV\composer"
function global:prompt { "({$envName}) " + (& _OLD_VIRTUAL_PROMPT) }
function global:deactivate {
    \$env:PATH = \$env:_OLD_VIRTUAL_PATH
    if (\$env:_OLD_PHP_INI_SCAN_DIR) { \$env:PHP_INI_SCAN_DIR = \$env:_OLD_PHP_INI_SCAN_DIR }
    else { Remove-Item Env:PHP_INI_SCAN_DIR -ErrorAction SilentlyContinue }
    if (Test-Path function:_OLD_VIRTUAL_PROMPT) { Copy-Item function:_OLD_VIRTUAL_PROMPT function:prompt; Remove-Item function:_OLD_VIRTUAL_PROMPT -ErrorAction SilentlyContinue }
    if (\$env:_OLD_COMPOSER_HOME) { \$env:COMPOSER_HOME = \$env:_OLD_COMPOSER_HOME }
    else { Remove-Item Env:COMPOSER_HOME -ErrorAction SilentlyContinue }
    Remove-Item function:deactivate -ErrorAction SilentlyContinue
    Remove-Item Env:_OLD_COMPOSER_HOME -ErrorAction SilentlyContinue
    Remove-Item Env:_OLD_VIRTUAL_PATH -ErrorAction SilentlyContinue
    Remove-Item Env:_OLD_PHP_INI_SCAN_DIR -ErrorAction SilentlyContinue
    Remove-Item Env:VIRTUAL_ENV -ErrorAction SilentlyContinue
}
PS1;
    }

    private function getActivateSh(string $envName, string $envDir, string $binDir, string $confDDir): string
    {
        return <<<SH
#!/usr/bin/env bash
export _OLD_VIRTUAL_PATH="\$PATH"
export _OLD_PHP_INI_SCAN_DIR="\$PHP_INI_SCAN_DIR"
export _OLD_COMPOSER_HOME="\$COMPOSER_HOME"
if [ -n "\$PS1" ]; then export _OLD_VIRTUAL_PS1="\$PS1"; export PS1="({$envName}) \$PS1"; fi
export VIRTUAL_ENV="{$envDir}"
export PATH="\$VIRTUAL_ENV/composer/vendor/bin:{$binDir}:\$PATH"
export PHP_INI_SCAN_DIR="{$confDDir}"
export COMPOSER_HOME="\$VIRTUAL_ENV/composer"
deactivate () {
    export PATH="\$_OLD_VIRTUAL_PATH"
    if [ -n "\$_OLD_PHP_INI_SCAN_DIR" ]; then export PHP_INI_SCAN_DIR="\$_OLD_PHP_INI_SCAN_DIR"; else unset PHP_INI_SCAN_DIR; fi
    if [ -n "\$_OLD_VIRTUAL_PS1" ]; then export PS1="\$_OLD_VIRTUAL_PS1"; fi
    if [ -n "\$_OLD_COMPOSER_HOME" ]; then export COMPOSER_HOME="\$_OLD_COMPOSER_HOME"; else unset COMPOSER_HOME; fi
    unset _OLD_COMPOSER_HOME _OLD_VIRTUAL_PATH _OLD_PHP_INI_SCAN_DIR _OLD_VIRTUAL_PS1 VIRTUAL_ENV
    unset -f deactivate
}
SH;
    }

    private function getPhpBat(string $phpBinary, string $cliDir): string
    {
        return <<<BAT
@echo off
if "%~1"=="--ini" (
    shift
)
if "%~0"=="--ini" (
    "{$phpBinary}" --ini -c "{$cliDir}" %1 %2 %3 %4 %5 %6 %7 %8 %9
) else (
    "{$phpBinary}" -c "{$cliDir}" %*
)
BAT;
    }

    private function getPhpSh(string $phpBinary, string $cliDir): string
    {
        return <<<SH
#!/usr/bin/env bash
if [ "$1" = "--ini" ]; then shift; exec "{$phpBinary}" --ini -c "{$cliDir}" "\$@";
else exec "{$phpBinary}" -c "{$cliDir}" "\$@"; fi
SH;
    }

    private function getPhpPs1(string $phpBinary, string $cliDir): string
    {
        return <<<PS1
if (\$args.Length -gt 0 -and \$args[0] -eq "--ini") {
    \$rest = @("--ini", "-c", "{$cliDir}")
    if (\$args.Length -gt 1) { \$rest += \$args[1..(\$args.Length - 1)] }
    & "{$phpBinary}" @rest
} else {
    & "{$phpBinary}" -c "{$cliDir}" @args
}
PS1;
    }
}