<?php
define('COMPOSER_HOME', getenv('COMPOSER_HOME'));
if(COMPOSER_HOME && file_exists(COMPOSER_HOME.'/vendor/autoload.php')) {
    $loader = require COMPOSER_HOME.'/vendor/autoload.php';
} else {
    throw new \Exception("Loader is not found.");
}
$loader->addPsr4('Rindow\\PhpVenv\\',__DIR__.'/../src');
return $loader;
