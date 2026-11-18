<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Helpers/functions.php';
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Middleware/RolePermissions.php';
require_once __DIR__ . '/../src/Middleware/AuthorizationService.php';
require_once __DIR__ . '/../src/Middleware/ModuleAccessService.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Repositories/AdminAuditRepository.php';
require_once __DIR__ . '/../src/Repositories/DashboardRepository.php';
require_once __DIR__ . '/../src/Repositories/UserAccessRepository.php';
require_once __DIR__ . '/../src/Repositories/CategoryRepository.php';
require_once __DIR__ . '/../src/Repositories/TeamRepository.php';
require_once __DIR__ . '/../src/Repositories/PlayerRepository.php';
require_once __DIR__ . '/../src/Repositories/TournamentRepository.php';
require_once __DIR__ . '/../src/Repositories/LogicalTournamentRepository.php';
require_once __DIR__ . '/../src/Repositories/PublicTournamentRepository.php';
require_once __DIR__ . '/../src/Services/ScheduleGeneratorService.php';
require_once __DIR__ . '/../src/Services/ScheduleAlgorithms.php';
require_once __DIR__ . '/../src/Services/TournamentFormatService.php';
require_once __DIR__ . '/../src/Services/StandingsService.php';
require_once __DIR__ . '/../src/Services/PublicTournamentService.php';
require_once __DIR__ . '/../src/Services/LogicalTournamentService.php';
require_once __DIR__ . '/../src/Services/PlayerPhotoService.php';
require_once __DIR__ . '/../src/Services/FacilityService.php';
require_once __DIR__ . '/../src/Services/MatchResultService.php';
require_once __DIR__ . '/../src/Services/MatchReportService.php';
require_once __DIR__ . '/../src/Services/PlayerRegistrationPolicy.php';
require_once __DIR__ . '/../src/Services/PlayerRegistrationService.php';
require_once __DIR__ . '/../src/Services/UserAccessService.php';
require_once __DIR__ . '/../vendor/dompdf/autoload.inc.php';
require_once __DIR__ . '/../src/Services/MatchSheetService.php';
require_once __DIR__ . '/../src/Services/MatchSheetPdfService.php';
require_once __DIR__ . '/../src/Controllers/TournamentFormatController.php';
require_once __DIR__ . '/../src/Controllers/MatchController.php';
require_once __DIR__ . '/../src/Controllers/MatchReportController.php';
require_once __DIR__ . '/../src/Controllers/PublicTournamentController.php';
require_once __DIR__ . '/../src/Controllers/LogicalTournamentController.php';
require_once __DIR__ . '/../src/Controllers/PlayerController.php';
require_once __DIR__ . '/../src/Controllers/FacilityController.php';
require_once __DIR__ . '/../src/Controllers/AuditController.php';
require_once __DIR__ . '/../src/Controllers/DashboardController.php';
require_once __DIR__ . '/../src/Controllers/UserAccessController.php';
require_once __DIR__ . '/../src/Core/Router.php';
require_once __DIR__ . '/../src/Routes/api.php';

putenv('JWT_SECRET=test-only-signing-key-with-at-least-32-bytes');
putenv('JWT_ISSUER=football-tournament-api');
putenv('DB_DRIVER=sqlite');
$testDatabasePath = __DIR__ . '/test.sqlite';
putenv('DB_PATH=' . $testDatabasePath);

if (is_file($testDatabasePath) && !unlink($testDatabasePath)) {
    throw new RuntimeException('No se pudo reiniciar la base de datos de pruebas.');
}

$pdo = new PDO('sqlite:' . $testDatabasePath);
$schema = file_get_contents(__DIR__ . '/../database/schema.sqlite.sql');
foreach (explode(';', $schema) as $statement) {
    $trimmed = trim($statement);
    if ($trimmed !== '') {
        $pdo->exec($trimmed);
    }
}

$seed = file_get_contents(__DIR__ . '/../database/seeders/initial_data.sql');
foreach (explode(';', $seed) as $statement) {
    $trimmed = trim($statement);
    if ($trimmed !== '') {
        $pdo->exec($trimmed);
    }
}
