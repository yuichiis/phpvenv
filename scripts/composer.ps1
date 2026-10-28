$composerHome = php -r "echo getenv('COMPOSER_HOME');"

if (-not $composerHome) {
    $composerHome = $env:APPDATA + "\Composer"
}

$composerPath = $composerHome + "\composer.phar"
$defaultComposerPath = $env:APPDATA + "\Composer\composer.phar"

if (Test-Path $composerPath) {
    php $composerPath @args
} elseif (Test-Path $defaultComposerPath) {
    php $defaultComposerPath @args
} else {
    php "$PSScriptRoot\composer.phar" @args
}