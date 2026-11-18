<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/dompdf/autoload.inc.php';
require_once __DIR__ . '/../src/Helpers/functions.php';
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Core/HttpException.php';
require_once __DIR__ . '/../src/Middleware/RolePermissions.php';
require_once __DIR__ . '/../src/Middleware/AuthorizationService.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/CorsMiddleware.php';
require_once __DIR__ . '/../src/Services/MatchSheetService.php';
require_once __DIR__ . '/../src/Services/MatchSheetPdfService.php';

use App\Core\HttpException;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;
use App\Services\MatchSheetPdfService;
use App\Services\MatchSheetService;

loadEnvFile(__DIR__ . '/../.env');

function sendCedulaError(int $status, string $message, array $errors = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors' => (object) $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

CorsMiddleware::handle(static function (): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        sendCedulaError(405, 'El método HTTP no está permitido para esta ruta.');
    }

    try {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        AuthMiddleware::requireAdmin(is_array($headers) ? $headers : []);
    } catch (HttpException $exception) {
        sendCedulaError($exception->statusCode, $exception->getMessage(), $exception->errors);
    }

    $matchIdInput = $_GET['match_id'] ?? null;
    $matchId = is_string($matchIdInput) ? filter_var($matchIdInput, FILTER_VALIDATE_INT) : false;
    if ($matchId === false || $matchId < 1) {
        sendCedulaError(422, 'El parámetro match_id debe ser un entero positivo.', [
            'match_id' => ['Proporciona un ID de partido válido.'],
        ]);
    }

    try {
        $match = (new MatchSheetService())->getMatchSheet($matchId);
        $logoPath = realpath(__DIR__ . '/../img/application/logo.jpg');
        if ($logoPath === false || !is_readable($logoPath)) {
            throw new \RuntimeException('No se pudo leer el logotipo institucional.');
        }
        $logoDataUri = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath));
        $dompdf = (new MatchSheetPdfService())->createDocument($match, $logoDataUri);
        $dompdf->stream("cedula_partido_{$matchId}.pdf", ['Attachment' => false]);
    } catch (\DomainException $exception) {
        $status = $exception->getMessage() === 'Partido no encontrado.' ? 404 : 409;
        sendCedulaError($status, $exception->getMessage());
    } catch (\Throwable $exception) {
        error_log((string) $exception);
        sendCedulaError(500, 'No se pudo generar la cédula arbitral.');
    }
});
