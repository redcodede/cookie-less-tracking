<?php
/**
 * This file is used to track media usage on the site.
 */

$uri = $_SERVER['REQUEST_URI'];
$label = isset($_GET['requested']) && $_GET['requested'] === '1' ? 'requested' : 'loaded'; /* unless media was specifically requested, label will be null resulting in value 'loaded' */
$cleanURI = str_replace('clt=1', '', $uri);

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
\Redcodede\CookieLessTracking\CookieLessTracking::trackMediaUsage($cleanURI, $label);

$stripURI = str_replace('requested=1', '', $uri);
$newHeader = str_contains($stripURI, '?') === false ? $stripURI . '?clt=1' : $stripURI . '&clt=1';
if (!isset($_GET['clt'])) header("Location: $newHeader");
