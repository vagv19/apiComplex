<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Helpers/functions.php';
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Core/HttpException.php';
require_once __DIR__ . '/../src/Core/Validator.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Router.php';
require_once __DIR__ . '/../src/Middleware/CorsMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RolePermissions.php';
require_once __DIR__ . '/../src/Middleware/AuthorizationService.php';
require_once __DIR__ . '/../src/Middleware/ModuleAccessService.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Repositories/CategoryRepository.php';
require_once __DIR__ . '/../src/Repositories/TeamRepository.php';
require_once __DIR__ . '/../src/Repositories/PlayerRepository.php';
require_once __DIR__ . '/../src/Repositories/TournamentRepository.php';
require_once __DIR__ . '/../src/Repositories/LogicalTournamentRepository.php';
require_once __DIR__ . '/../src/Repositories/PublicTournamentRepository.php';
require_once __DIR__ . '/../src/Repositories/AdminAuditRepository.php';
require_once __DIR__ . '/../src/Repositories/UserAccessRepository.php';
require_once __DIR__ . '/../src/Repositories/DashboardRepository.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/CategoryController.php';
require_once __DIR__ . '/../src/Controllers/DashboardController.php';
require_once __DIR__ . '/../src/Controllers/TeamController.php';
require_once __DIR__ . '/../src/Controllers/PlayerController.php';
require_once __DIR__ . '/../src/Controllers/TournamentController.php';
require_once __DIR__ . '/../src/Controllers/TournamentFormatController.php';
require_once __DIR__ . '/../src/Controllers/ScheduleController.php';
require_once __DIR__ . '/../src/Controllers/MatchController.php';
require_once __DIR__ . '/../src/Controllers/MatchReportController.php';
require_once __DIR__ . '/../src/Controllers/StandingsController.php';
require_once __DIR__ . '/../src/Controllers/PublicTournamentController.php';
require_once __DIR__ . '/../src/Controllers/LogicalTournamentController.php';
require_once __DIR__ . '/../src/Controllers/FacilityController.php';
require_once __DIR__ . '/../src/Controllers/AuditController.php';
require_once __DIR__ . '/../src/Controllers/UserAccessController.php';
require_once __DIR__ . '/../src/Services/TournamentService.php';
require_once __DIR__ . '/../src/Services/LogicalTournamentService.php';
require_once __DIR__ . '/../src/Services/RosterService.php';
require_once __DIR__ . '/../src/Services/ScheduleGeneratorService.php';
require_once __DIR__ . '/../src/Services/ScheduleAlgorithms.php';
require_once __DIR__ . '/../src/Services/TournamentFormatService.php';
require_once __DIR__ . '/../src/Services/StandingsService.php';
require_once __DIR__ . '/../src/Services/PublicTournamentService.php';
require_once __DIR__ . '/../src/Services/PlayerPhotoService.php';
require_once __DIR__ . '/../src/Services/TeamLogoService.php';
require_once __DIR__ . '/../src/Services/FacilityService.php';
require_once __DIR__ . '/../src/Services/QualificationService.php';
require_once __DIR__ . '/../src/Services/MatchResultService.php';
require_once __DIR__ . '/../src/Services/MatchReportService.php';
require_once __DIR__ . '/../src/Services/PlayerRegistrationPolicy.php';
require_once __DIR__ . '/../src/Services/PlayerRegistrationService.php';
require_once __DIR__ . '/../src/Services/UserAccessService.php';
require_once __DIR__ . '/../src/Routes/api.php';

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\CorsMiddleware;

loadEnvFile(__DIR__ . '/../.env');

try {
    $request = Request::fromGlobals();

    $handler = require __DIR__ . '/../src/Routes/api.php';
    $router = $handler();

    CorsMiddleware::handle(function () use ($request, $router) {
        try {
            $router->dispatch($request);
        } catch (HttpException $e) {
            Response::json($e->statusCode, false, null, $e->getMessage(), $e->errors);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::json(500, false, null, 'Error interno del servidor.');
        }
    });
} catch (Throwable $e) {
    error_log((string) $e);
    Response::json(500, false, null, 'Error interno del servidor.');
}
