<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Repair the storage tree BEFORE the framework boots. The deploy excludes
// `storage/**`, so a fresh release genuinely has no `storage/framework/views`,
// and the framework refuses to start without it — see the comments in that
// file for why neither Git nor `itrequest:install` can be the one to fix it.
require __DIR__.'/../bootstrap/ensure-storage.php';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
