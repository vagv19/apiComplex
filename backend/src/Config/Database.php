<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = strtolower((string) config('DB_DRIVER', 'mysql'));
        $dsn = '';

        if ($driver === 'sqlite') {
            $path = (string) config('DB_PATH', __DIR__ . '/../../database/app.sqlite');
            $dsn = 'sqlite:' . $path;
        } else {
            $host = (string) config('DB_HOST', '127.0.0.1');
            $port = (string) config('DB_PORT', '3306');
            $dbName = (string) config('DB_NAME', 'bdcomplex_v2');
            $charset = (string) config('DB_CHARSET', 'utf8mb4');
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
        }

        try {
            $pdo = new PDO($dsn, (string) config('DB_USERNAME', 'root'), (string) config('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            if ($driver === 'mysql') {
                $pdo->exec('SET NAMES utf8mb4');
            }

            self::$connection = $pdo;
            if ($driver === 'sqlite') {
                self::ensureSchema();
            }
            return $pdo;
        } catch (PDOException $e) {
            throw new \RuntimeException('No se pudo conectar con la base de datos.');
        }
    }

    public static function ensureSchema(): void
    {
        if (strtolower((string) config('DB_DRIVER', 'mysql')) !== 'sqlite') {
            return;
        }

        $path = (string) config('DB_PATH', __DIR__ . '/../../database/app.sqlite');
        if (!is_file($path)) {
            touch($path);
        }

        $pdo = self::$connection ?? new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$connection = $pdo;

        $schema = file_get_contents(__DIR__ . '/../../database/schema.sqlite.sql');
        if ($schema === false) {
            return;
        }

        foreach (explode(';', $schema) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $pdo->exec($trimmed);
            }
        }
    }

    public static function reset(): void
    {
        self::$connection = null;
    }
}
