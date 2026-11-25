<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;

if (!Auth::check()) {
    Response::redirect('login.php');
}

if (Auth::isAdmin()) {
    Response::redirect('admin/dashboard.php');
}

Response::redirect('panelist/dashboard.php');
