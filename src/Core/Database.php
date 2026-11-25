<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(?array $overrideConfig = null): PDO
    {
        if (self::$instance !== null && $overrideConfig === null) {
            return self::$instance;
        }

        $config = $overrideConfig ?? require dirname(__DIR__, 2) . '/config/database.php';
        $driver = $config['driver'] ?? 'mysql';

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = $config['sqlite_path'] ?: ':memory:';
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON;');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $config['host'],
                    $config['port'],
                    $config['database'],
                    $config['charset'] ?? 'utf8mb4'
                );
                $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
                $pdo->exec("SET time_zone = '+00:00'");
            }
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        if ($overrideConfig === null) {
            self::$instance = $pdo;
        }
        return $pdo;
    }

    public static function setConnection(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
