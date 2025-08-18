<?php
/**
 * This file is used to track media usage on the site.
 */

$uri = $_SERVER['REQUEST_URI'];
$label = $_GET['requested'] === '1' ? 'requested' : 'loaded'; /* unless media was specifically requested, label will be null resulting in value 'loaded' */

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
\Redcodede\CookieLessTracking\CookieLessTracking::trackMediaUsage($uri, $label);