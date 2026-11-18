<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

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
        $normalizedScriptName = str_replace('\\', '/', $scriptName);
        if (basename($normalizedScriptName) !== 'index.php') {
            return $path;
        }

        $scriptDir = rtrim(str_replace('\\', '/', dirname($normalizedScriptName)), '/');
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
            $contentType = '';
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'content-type' && is_string($value)) {
                    $contentType = strtolower(trim(strtok($value, ';')));
                    break;
                }
            }
            if ($contentType === '') {
                $rawContentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
                $contentType = strtolower(trim(explode(';', $rawContentType, 2)[0]));
            }

            if ($contentType === 'application/json') {
                try {
                    $decoded = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new HttpException(400, 'El cuerpo JSON no es válido.');
                }
                if (!is_array($decoded) || !str_starts_with(ltrim($rawInput), '{')) {
                    throw new HttpException(400, 'El cuerpo JSON debe ser un objeto.');
                }
                $body = $decoded;
            } elseif ($contentType === 'application/x-www-form-urlencoded') {
                parse_str($rawInput, $body);
            } elseif ($contentType !== 'multipart/form-data') {
                throw new HttpException(415, 'El cuerpo debe usar application/json.');
            }
        }

        return new self($method, $path, $query, $headers, $body);
    }
}
