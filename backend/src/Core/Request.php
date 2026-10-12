<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $body
    ) {
    }

    public static function normalizePath(string $path): string
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($scriptDir !== '' && $scriptDir !== '.' && $scriptDir !== '/') {
            if ($path === $scriptDir) {
                return '/';
            }

            if (str_starts_with($path, $scriptDir . '/')) {
                $path = substr($path, strlen($scriptDir));
                $path = $path === '' ? '/' : $path;
            }
        }

        return $path;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = self::normalizePath(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $query = $_GET;
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        $body = [];
        $rawInput = file_get_contents('php://input');
        if (is_string($rawInput) && $rawInput !== '') {
            $decoded = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $body = $decoded;
            } else {
                parse_str($rawInput, $body);
            }
        }

        return new self($method, $path, $query, $headers, $body);
    }
}
