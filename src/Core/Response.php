<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        Auth::startSession();
        $_SESSION['_flashes'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlashes(): array
    {
        Auth::startSession();
        $flashes = $_SESSION['_flashes'] ?? [];
        unset($_SESSION['_flashes']);
        return $flashes;
    }

    public static function baseUrl(string $path = ''): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $configured = $config['url'] ?? '';
        if ($configured !== '') {
            return rtrim($configured, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $publicPos = strpos($script, '/public');
        if ($publicPos !== false) {
            $basePath = substr($script, 0, $publicPos + 7);
        } else {
            // Document root is public/ itself (e.g. php -S 127.0.0.1:8080 -t public)
            $basePath = '';
        }
        return $scheme . '://' . $host . $basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
