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
        $loadedIni = $this->getLoadedIniFile();
        $hasMainIni = false;
        if ($loadedIni && $this->fs->exists($loadedIni)) {
            $this->fs->copy($loadedIni, $cliDir . DIRECTORY_SEPARATOR . 'php.ini');
            echo "Copied php.ini\n";
            $hasMainIni = true;
        }

        $scanned = $this->getScannedIniFiles();
        if ($scanned) {
            $files = array_map('trim', explode(',', $scanned));
            $loadedReal = ($loadedIni && is_string($loadedIni)) ? realpath($loadedIni) : false;
            foreach ($files as $file) {
                if (!$file || !$this->fs->exists($file)) {
                    continue;
                }
                // Same file as the loaded php.ini -> already copied as cli/php.ini
                if ($loadedReal !== false) {
                    $fileReal = realpath($file);
                    if ($fileReal !== false && $this->isSamePath($fileReal, $loadedReal)) {
                        continue;
                    }
                }
                // No loaded php.ini (e.g. scoop installs have no php.ini next
                // to php.exe but load it via PHP_INI_SCAN_DIR as one of the
                // scanned files): promote a scanned "php.ini" to cli/php.ini
                // so that -c/PHPRC point to a real main ini file like the
                // other patterns.
                if (!$hasMainIni && strtolower(basename($file)) === 'php.ini') {
                    $this->fs->copy($file, $cliDir . DIRECTORY_SEPARATOR . 'php.ini');
                    echo "Copied php.ini (from scan dir)\n";
                    $hasMainIni = true;
                    continue;
                }
                $this->fs->copy($file, $confDDir . DIRECTORY_SEPARATOR . basename($file));
            }
            echo "Copied additional ini files\n";
        }
    }

    protected function getLoadedIniFile(): string|false
    {
        return php_ini_loaded_file();
    }

    protected function getScannedIniFiles(): string|false
    {
        return php_ini_scanned_files();
    }

    private function isSamePath(string $a, string $b): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return strtolower($a) === strtolower($b);
        }
        return $a === $b;
    }

    private function generateScripts(string $envName, string $envDir, string $binDir, string $cliDir, string $confDDir): void
    {
        $phpBinary = PHP_BINARY;

        // Windows Batch scripts
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'activate.bat', $this->getActivateBat($envName, $envDir, $binDir, $confDDir));
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'deactivate.bat', $this->getDeactivateBat());
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'php.bat', $this->getPhpBat($phpBinary, $envDir, $cliDir, $confDDir));

        // PowerShell
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'Activate.ps1', $this->getActivatePs1($envName, $envDir, $binDir, $confDDir));
        $this->writeWindowsFile($binDir . DIRECTORY_SEPARATOR . 'php.ps1', $this->getPhpPs1($phpBinary, $envDir, $cliDir, $confDDir));

        // Bash
        $this->writeUnixFile($binDir . DIRECTORY_SEPARATOR . 'activate', $this->getActivateSh($envName, $envDir, $binDir, $confDDir));
        if (!$this->isWindows) {
            $this->writeUnixFile($binDir . DIRECTORY_SEPARATOR . 'php', $this->getPhpSh($phpBinary, $envDir, $cliDir, $confDDir));
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
            echo "  {$envDir}\\Scripts\\activate.bat (cmd)\n";
            echo "  . {$envDir}\\Scripts\\Activate.ps1 (powershell)\n";
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
set "PATH={$binDir};%VIRTUAL_ENV%\\composer\\vendor\\bin;%PATH%"
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
\$env:PATH = "{$binDir};\$env:VIRTUAL_ENV\\composer\\vendor\\bin;" + \$env:PATH
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
export PATH="{$binDir}:\$VIRTUAL_ENV/composer/vendor/bin:\$PATH"
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

    private function getPhpBat(string $phpBinary, string $envDir, string $cliDir, string $confDDir): string
    {
        return <<<BAT
@echo off
setlocal
set "VIRTUAL_ENV={$envDir}"
set "PHP_INI_SCAN_DIR={$confDDir}"
set "COMPOSER_HOME={$envDir}\composer"
set "PHPRC={$cliDir}"
if "%~1"=="--ini" (
    "{$phpBinary}" --ini -c "{$cliDir}" %2 %3 %4 %5 %6 %7 %8 %9
) else (
    if /I "%~n1"=="artisan" (
        "{$phpBinary}" %*
    ) else (
        "{$phpBinary}" -c "{$cliDir}" %*
    )
)
BAT;
    }

    private function getPhpSh(string $phpBinary, string $envDir, string $cliDir, string $confDDir): string
    {
        return <<<SH
#!/usr/bin/env bash
export VIRTUAL_ENV="{$envDir}"
export PHP_INI_SCAN_DIR="{$confDDir}"
export COMPOSER_HOME="\$VIRTUAL_ENV/composer"
export PHPRC="{$cliDir}"
if [ "\$1" = "--ini" ]; then shift; exec "{$phpBinary}" --ini -c "{$cliDir}" "\$@";
else
    _phpvenv_is_artisan=0
    if [ -n "\$1" ]; then
        _phpvenv_base=\$(basename "\$1")
        _phpvenv_base=\${_phpvenv_base%.php}
        if [ "\$_phpvenv_base" = "artisan" ]; then _phpvenv_is_artisan=1; fi
    fi
    if [ "\$_phpvenv_is_artisan" = "1" ]; then exec "{$phpBinary}" "\$@"; else exec "{$phpBinary}" -c "{$cliDir}" "\$@"; fi
fi
SH;
    }

    private function getPhpPs1(string $phpBinary, string $envDir, string $cliDir, string $confDDir): string
    {
        return <<<PS1
\$oldVirtualEnv      = \$env:VIRTUAL_ENV
\$oldPhpIniScanDir   = \$env:PHP_INI_SCAN_DIR
\$oldComposerHome    = \$env:COMPOSER_HOME
\$oldPhprc           = \$env:PHPRC
try {
    \$env:VIRTUAL_ENV = "{$envDir}"
    \$env:PHP_INI_SCAN_DIR = "{$confDDir}"
    \$env:COMPOSER_HOME = "\$env:VIRTUAL_ENV\composer"
    \$env:PHPRC = "{$cliDir}"
    if (\$args.Length -gt 0 -and \$args[0] -eq "--ini") {
        \$rest = @("--ini", "-c", "{$cliDir}")
        if (\$args.Length -gt 1) { \$rest += \$args[1..(\$args.Length - 1)] }
        & "{$phpBinary}" @rest
    } elseif (\$args.Length -gt 0 -and ([System.IO.Path]::GetFileNameWithoutExtension(\$args[0]) -ieq "artisan")) {
        & "{$phpBinary}" @args
    } else {
        & "{$phpBinary}" -c "{$cliDir}" @args
    }
} finally {
    if (\$oldVirtualEnv) { \$env:VIRTUAL_ENV = \$oldVirtualEnv } else { Remove-Item Env:VIRTUAL_ENV -ErrorAction SilentlyContinue }
    if (\$oldPhpIniScanDir) { \$env:PHP_INI_SCAN_DIR = \$oldPhpIniScanDir } else { Remove-Item Env:PHP_INI_SCAN_DIR -ErrorAction SilentlyContinue }
    if (\$oldComposerHome) { \$env:COMPOSER_HOME = \$oldComposerHome } else { Remove-Item Env:COMPOSER_HOME -ErrorAction SilentlyContinue }
    if (\$oldPhprc) { \$env:PHPRC = \$oldPhprc } else { Remove-Item Env:PHPRC -ErrorAction SilentlyContinue }
}
PS1;
    }
}