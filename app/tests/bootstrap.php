<?php

use Symfony\Component\Dotenv\Dotenv;

// Force test env if not already provided by phpunit.xml
if (!isset($_SERVER['APP_ENV']) && !getenv('APP_ENV')) {
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
}

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
