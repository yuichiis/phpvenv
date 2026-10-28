#!/usr/bin/env bash

composerHome=$(php -r "echo trim(getenv('COMPOSER_HOME'));")

if [ -z "$composerHome" ]; then
    composerHome="$HOME/.config/composer"
fi

composerPath="$composerHome/composer.phar"
defaultComposerPath="$HOME/.config/composer/composer.phar"

scriptDir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -f "$composerPath" ]; then
    php "$composerPath" "$@"
elif [ -f "$defaultComposerPath" ]; then
    php "$defaultComposerPath" "$@"
else
    php "$scriptDir/composer.phar" "$@"
fi
