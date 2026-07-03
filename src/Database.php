<?php

declare(strict_types=1);

class Database
{
    public static function source(): PDO
    {
        return self::connect(
            $_ENV['SOURCE_HOST'],
            $_ENV['SOURCE_PORT'] ?? '3306',
            $_ENV['SOURCE_DB'],
            $_ENV['SOURCE_USER'],
            $_ENV['SOURCE_PASS']
        );
    }

    public static function target(): PDO
    {
        return self::connect(
            $_ENV['TARGET_HOST'],
            $_ENV['TARGET_PORT'] ?? '3306',
            $_ENV['TARGET_DB'],
            $_ENV['TARGET_USER'],
            $_ENV['TARGET_PASS']
        );
    }

    private static function connect(string $host, string $port, string $db, string $user, string $pass): PDO
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
