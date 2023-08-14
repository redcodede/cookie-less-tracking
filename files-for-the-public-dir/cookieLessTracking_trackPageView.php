<?php
/**
 * This file is used to track page views, when the page is served from the static cache.
 * You need to modify the public/.htaccess to pipe the request through this file.
 * @see README.md
 */

$file = $_SERVER['REQUEST_URI'].'_'.$_SERVER['QUERY_STRING'].'.html';

ob_start();
readfile('static'.$file);
ob_flush();
flush();

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
\Redcodede\CookieLessTracking\CookieLessTracking::trackPageView();



