<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static bool $loaded = false;
    private static array $values = [];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $pos));
            $val = trim(substr($trimmed, $pos + 1));

            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            self::$values[$key] = $val;
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $envVal = getenv($key);
        if ($envVal !== false && $envVal !== '') {
            return (string)$envVal;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset(self::$values[$key]) && self::$values[$key] !== '') {
            return (string)self::$values[$key];
        }
        return $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $val = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }
}
