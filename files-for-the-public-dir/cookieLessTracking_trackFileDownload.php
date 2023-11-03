<?php
/**
 * This file is used to track file downloads.
 * You need to modify the public/.htaccess to pipe the request through this file.
 * @see README.md
 */

$file = $_SERVER['REQUEST_URI'];

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
\Redcodede\CookieLessTracking\CookieLessTracking::trackFileDownload($file, null);

header("Location: $file?download=true");
