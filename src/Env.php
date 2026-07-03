<?php

declare(strict_types=1);

class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Env file not found: {$path}. Copy .env.example to .env and configure it.");
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
