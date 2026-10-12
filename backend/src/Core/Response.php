<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(int $statusCode, bool $success, mixed $data = null, string $message = '', array $errors = []): void
    {
        http_response_code($statusCode);

        $payload = [
            'success' => $success,
            'message' => $message !== '' ? $message : ($success ? 'Operación realizada correctamente' : 'No fue posible completar la operación'),
        ];

        if ($success) {
            $payload['data'] = $data;
        } else {
            if ($errors !== []) {
                $payload['errors'] = $errors;
            }
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
