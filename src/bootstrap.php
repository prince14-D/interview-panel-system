<?php
declare(strict_types=1);

/**
 * Application Bootstrap
 * Loads Composer autoloader if installed, plus PSR-4 fallback for App\ namespace.
 */

$rootDir = dirname(__DIR__);

// PSR-4 autoloader for App\ (works even before `composer install`)
spl_autoload_register(static function (string $class) use ($rootDir): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $rootDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Load Composer dependencies (PhpSpreadsheet, Dompdf) when installed
$composerAutoload = $rootDir . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

use App\Core\Env;
use App\Core\Auth;

Env::load($rootDir . '/.env');

$appConfig = require $rootDir . '/config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'Africa/Monrovia');

if (!empty($appConfig['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

Auth::startSession();

/**
 * Global HTML escape helper for templates
 */
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return \App\Core\Response::e($value);
    }
}
