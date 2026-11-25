<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

try {
    $pdo = Database::getConnection();
    Auth::logout($pdo);
} catch (Throwable $e) {
    Auth::logout(null);
}

Response::flash('info', 'You have been securely signed out.');
Response::redirect('login.php');
