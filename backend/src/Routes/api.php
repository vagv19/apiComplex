<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\FacilityController;
use App\Controllers\MatchController;
use App\Controllers\MatchReportController;
use App\Controllers\PlayerController;
use App\Controllers\PublicTournamentController;
use App\Controllers\ScheduleController;
use App\Controllers\StandingsController;
use App\Controllers\TeamController;
use App\Controllers\TournamentController;
use App\Controllers\TournamentFormatController;
use App\Core\Router;

return function (): Router {
    $router = new Router();

    $router->post('/api/v1/auth/login', [AuthController::class, 'login']);

    $router->get('/api/v1/categories', [CategoryController::class, 'list']);
    $router->post('/api/v1/categories', [CategoryController::class, 'create']);
    $router->get('/api/v1/categories/{id}', [CategoryController::class, 'get']);
    $router->post('/api/v1/categories/{id}', [CategoryController::class, 'update']);
    $router->delete('/api/v1/categories/{id}', [CategoryController::class, 'delete']);

    $router->get('/api/v1/teams', [TeamController::class, 'list']);
    $router->post('/api/v1/teams', [TeamController::class, 'create']);
    $router->get('/api/v1/teams/{id}', [TeamController::class, 'get']);
    $router->post('/api/v1/teams/{id}', [TeamController::class, 'update']);
    $router->delete('/api/v1/teams/{id}', [TeamController::class, 'delete']);
    $router->get('/api/v1/teams/{id}/history', [TeamController::class, 'history']);
    $router->get('/api/v1/teams/{id}/roster', [TeamController::class, 'roster']);

    $router->get('/api/v1/players', [PlayerController::class, 'list']);
    $router->post('/api/v1/players', [PlayerController::class, 'create']);
    $router->get('/api/v1/players/{id}', [PlayerController::class, 'get']);
    $router->post('/api/v1/players/{id}', [PlayerController::class, 'update']);
    $router->delete('/api/v1/players/{id}', [PlayerController::class, 'delete']);
    $router->get('/api/v1/players/{id}/history', [PlayerController::class, 'history']);

    $router->get('/api/v1/tournaments', [TournamentController::class, 'list']);
    $router->post('/api/v1/tournaments', [TournamentController::class, 'create']);
    $router->get('/api/v1/tournaments/{id}', [TournamentController::class, 'get']);
    $router->post('/api/v1/tournaments/{id}', [TournamentController::class, 'update']);
    $router->post('/api/v1/tournaments/{id}/status', [TournamentController::class, 'status']);
    $router->post('/api/v1/tournaments/{id}/teams', [TournamentController::class, 'registerTeam']);
    $router->get('/api/v1/tournaments/{id}/teams', [TournamentController::class, 'teams']);
    $router->post('/api/v1/tournaments/{id}/players', [TournamentController::class, 'registerPlayer']);
    $router->get('/api/v1/tournaments/{id}/players', [TournamentController::class, 'players']);
    $router->post('/api/v1/tournaments/{id}/player-registration', [TournamentController::class, 'togglePlayerRegistration']);

    $router->post('/api/v1/tournaments/{id}/schedule/generate', [ScheduleController::class, 'generate']);
    $router->get('/api/v1/tournaments/{id}/stages', [ScheduleController::class, 'stages']);
    $router->get('/api/v1/tournaments/{id}/rounds', [ScheduleController::class, 'rounds']);
    $router->get('/api/v1/tournaments/{id}/groups', [TournamentFormatController::class, 'groups']);
    $router->post('/api/v1/tournaments/{id}/groups/generate', [TournamentFormatController::class, 'createGroups']);
    $router->delete('/api/v1/tournaments/{id}/groups', [TournamentFormatController::class, 'cancelGroups']);
    $router->post('/api/v1/tournaments/{id}/group-stage/generate', [TournamentFormatController::class, 'generateGroupSchedule']);
    $router->post('/api/v1/tournaments/{id}/cup/generate', [TournamentFormatController::class, 'generateCupBracket']);
    $router->post('/api/v1/tournaments/{id}/qualification/generate', [TournamentFormatController::class, 'qualifyFlash']);
    $router->post('/api/v1/tournaments/{id}/league/qualification/generate', [TournamentFormatController::class, 'startLeagueKnockout']);
    $router->post('/api/v1/tournaments/{id}/knockout/advance', [TournamentFormatController::class, 'advanceKnockout']);
    $router->post('/api/v1/tournaments/{id}/rounds', [ScheduleController::class, 'createRound']);
    $router->post('/api/v1/rounds/{id}', [ScheduleController::class, 'updateRound']);
    $router->get('/api/v1/rounds/{id}/matches', [ScheduleController::class, 'matchesByRound']);
    $router->post('/api/v1/rounds/{id}/matches', [ScheduleController::class, 'createMatch']);
    $router->get('/api/v1/matches/by-date', [ScheduleController::class, 'matchesByDate']);
    $router->post('/api/v1/matches/{id}/schedule', [ScheduleController::class, 'scheduleMatch']);
    $router->post('/api/v1/matches/{id}', [ScheduleController::class, 'updateMatch']);
    $router->post('/api/v1/matches/{id}/result', [MatchController::class, 'result']);
    $router->get('/api/v1/matches/{id}/report', [MatchReportController::class, 'getReport']);
    $router->post('/api/v1/matches/{id}/report', [MatchReportController::class, 'saveReport']);
    $router->get('/api/v1/expulsion-reasons', [MatchReportController::class, 'listReasons']);
    $router->post('/api/v1/expulsion-reasons', [MatchReportController::class, 'createReason']);
    $router->get('/api/v1/expulsion-reasons/{id}', [MatchReportController::class, 'getReason']);
    $router->post('/api/v1/expulsion-reasons/{id}', [MatchReportController::class, 'updateReason']);
    $router->delete('/api/v1/expulsion-reasons/{id}', [MatchReportController::class, 'deleteReason']);
    $router->get('/api/v1/players/{id}/suspensions', [MatchReportController::class, 'playerSuspensions']);

    $router->get('/api/v1/tournaments/{id}/standings', [StandingsController::class, 'tournament']);
    $router->get('/api/v1/groups/{id}/standings', [StandingsController::class, 'group']);

    $router->get('/api/v1/public/tournaments', [PublicTournamentController::class, 'list']);
    $router->get('/api/v1/public/matches/first', [PublicTournamentController::class, 'firstMatchOfDay']);
    $router->get('/api/v1/public/facilities/images', [FacilityController::class, 'images']);
    $router->get('/api/v1/public/facilities/images/{filename}', [FacilityController::class, 'image']);
    $router->get('/api/v1/public/tournaments/{id}/top-scorers', [PublicTournamentController::class, 'topScorers']);
    $router->get('/api/v1/public/tournaments/{id}/images', [PublicTournamentController::class, 'images']);
    $router->get('/api/v1/public/tournaments/{id}/images/{filename}', [PublicTournamentController::class, 'image']);

    return $router;
};
