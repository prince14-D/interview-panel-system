<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function attempt(PDO $pdo, string $email, string $password): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute(['email' => trim(strtolower($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $upd = $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $upd->execute(['id' => $user['id']]);

        if (PHP_SAPI !== 'cli') {
            self::startSession();
            session_regenerate_id(true);
        }

        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'job_title' => $user['job_title'],
            'department_id' => $user['department_id'] ? (int)$user['department_id'] : null,
        ];

        AuditLogger::log($pdo, (int)$user['id'], 'user_login', 'user', (int)$user['id'], 'Successful login');
        return $_SESSION['user'];
    }

    public static function user(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && in_array($u['role'], ['admin', 'hr_officer'], true);
    }

    public static function isSuperAdmin(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    public static function isPanelist(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'panelist';
    }

    public static function requireAuth(string $loginUrl = '../login.php'): array
    {
        $u = self::user();
        if (!$u) {
            Response::flash('warning', 'Please sign in to access the Ministry Interview Panel System.');
            Response::redirect($loginUrl);
        }
        return $u;
    }

    public static function requireRole(array $roles, string $fallbackUrl = '../index.php'): array
    {
        $u = self::requireAuth();
        if (!in_array($u['role'], $roles, true)) {
            http_response_code(403);
            Response::flash('danger', 'Access denied: you do not have permission to view that resource.');
            Response::redirect($fallbackUrl);
        }
        return $u;
    }

    public static function logout(?PDO $pdo = null): void
    {
        self::startSession();
        $userId = self::id();
        if ($pdo && $userId) {
            AuditLogger::log($pdo, $userId, 'user_logout', 'user', $userId, 'User logged out');
        }
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        $expected = $_SESSION['_csrf_token'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function requireCsrf(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::verifyCsrf(is_string($token) ? $token : null)) {
            http_response_code(419);
            exit('Invalid or expired security token. Please refresh the page and try again.');
        }
    }
}
