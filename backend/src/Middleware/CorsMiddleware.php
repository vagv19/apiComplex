<?php

declare(strict_types=1);

namespace App\Middleware;

class CorsMiddleware
{
    public static function handle(callable $next): mixed
    {
        $allowedOrigins = array_map('trim', explode(',', (string) config('ALLOWED_ORIGINS', 'http://localhost:3000')));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }

        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');

        if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS')) {
            http_response_code(204);
            exit;
        }

        return $next();
    }
}
