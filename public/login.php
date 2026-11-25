<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

if (Auth::check()) {
    Response::redirect('index.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter both your Ministry email address and password.';
    } else {
        try {
            $pdo = Database::getConnection();
            $user = Auth::attempt($pdo, $email, $password);
            if ($user) {
                Response::flash('success', 'Welcome back, ' . $user['full_name'] . '.');
                Response::redirect('index.php');
            } else {
                $error = 'Invalid credentials or inactive account. Please verify your email and password.';
            }
        } catch (Throwable $e) {
            $error = 'Unable to connect to the database. Please check your .env database configuration.';
        }
    }
}

$flashes = Response::pullFlashes();
$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Ministry of Youth and Sports, Republic of Liberia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
    <div class="card login-card">
        <div class="login-card-top">
            <img src="assets/img/mys-emblem.svg" alt="MYS Emblem" width="68" height="68" class="mb-2">
            <div class="text-uppercase small fw-bold" style="color:#F2D16B; letter-spacing:1.2px;">Republic of Liberia</div>
            <h1 class="h5 fw-bold mb-1">Ministry of Youth and Sports</h1>
            <div class="small text-white-50">Interview Panel Management &amp; Scoring System</div>
        </div>
        <div class="card-body p-4">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> py-2 small"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold small">Official Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" placeholder="name@mys.gov.lr" required autofocus>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold small">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-mys w-100 py-2 fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In to Portal
                </button>
            </form>

            <div class="mt-4 pt-3 border-top text-center small text-muted">
                <i class="bi bi-shield-lock me-1 text-primary"></i>
                Individual panelist assessments are protected by blind-scoring confidentiality until official release.
            </div>
        </div>
    </div>
</div>
</body>
</html>
