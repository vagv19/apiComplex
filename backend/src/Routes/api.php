<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AuditController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\FacilityController;
use App\Controllers\LogicalTournamentController;
use App\Controllers\MatchController;
use App\Controllers\MatchReportController;
use App\Controllers\PlayerController;
use App\Controllers\PublicTournamentController;
use App\Controllers\ScheduleController;
use App\Controllers\StandingsController;
use App\Controllers\TeamController;
use App\Controllers\TournamentController;
use App\Controllers\TournamentFormatController;
use App\Controllers\UserAccessController;
use App\Core\Router;

return function (): Router {
    $router = new Router();

    $router->post('/api/v1/auth/login', [AuthController::class, 'login'], 'public');
    $router->get('/api/v1/auth/me', [AuthController::class, 'me'], 'users.session.read');

    $router->get('/api/v1/dashboard', [DashboardController::class, 'overview'], 'dashboard.read');
    $router->get('/api/v1/users', [UserAccessController::class, 'list'], 'users.read');
    $router->get('/api/v1/roles', [UserAccessController::class, 'roles'], 'users.read');
    $router->get('/api/v1/modules', [UserAccessController::class, 'modules'], 'users.read');
    $router->get('/api/v1/permissions', [UserAccessController::class, 'permissions'], 'users.read');
    $router->get('/api/v1/users/{id}/modules', [UserAccessController::class, 'userModules'], 'users.read');
    $router->get('/api/v1/users/{id}/teams', [UserAccessController::class, 'userTeams'], 'users.read');
    $router->get('/api/v1/users/{id}/audit', [UserAccessController::class, 'userAudit'], 'users.read');
    $router->post('/api/v1/users/create', [UserAccessController::class, 'create'], 'users.manage.directors');
    $router->post('/api/v1/users/update', [UserAccessController::class, 'update'], 'users.manage.directors');
    $router->post('/api/v1/users/soft-delete', [UserAccessController::class, 'softDelete'], 'users.manage.directors');
    $router->post('/api/v1/users/restore', [UserAccessController::class, 'restore'], 'users.manage.directors');
    $router->post('/api/v1/users/change-role', [UserAccessController::class, 'changeRole'], 'roles.manage');
    $router->post('/api/v1/users/change-password', [UserAccessController::class, 'changePassword'], 'users.password.self');
    $router->post('/api/v1/users/reset-password', [UserAccessController::class, 'resetPassword'], 'users.password.reset.directors');
    $router->post('/api/v1/users/assign-modules', [UserAccessController::class, 'assignModules'], 'users.manage.directors');
    $router->post('/api/v1/users/assign-teams', [UserAccessController::class, 'assignTeams'], 'users.manage.directors');
    $router->post('/api/v1/users/remove-team', [UserAccessController::class, 'removeTeam'], 'users.manage.directors');
    $router->get('/api/v1/users/{id}', [UserAccessController::class, 'get'], 'users.read');

    $router->get('/api/v1/categories', [CategoryController::class, 'list'], 'categories.read');
    $router->post('/api/v1/categories', [CategoryController::class, 'create'], 'categories.manage');
    $router->get('/api/v1/categories/{id}', [CategoryController::class, 'get'], 'categories.read');
    $router->post('/api/v1/categories/{id}', [CategoryController::class, 'update'], 'categories.manage');
    $router->post('/api/v1/categories/{id}/delete', [CategoryController::class, 'delete'], 'categories.manage');

    $router->get('/api/v1/teams', [TeamController::class, 'list'], 'teams.read');
    $router->post('/api/v1/teams', [TeamController::class, 'create'], 'teams.manage');
    $router->get('/api/v1/teams/{id}', [TeamController::class, 'get'], 'teams.read');
    $router->post('/api/v1/teams/{id}', [TeamController::class, 'update'], 'teams.manage');
    $router->post('/api/v1/teams/{id}/delete', [TeamController::class, 'delete'], 'teams.manage');
    $router->get('/api/v1/teams/{id}/history', [TeamController::class, 'history'], 'teams.read');
    $router->get('/api/v1/teams/{id}/roster', [TeamController::class, 'roster'], 'teams.read');

    $router->get('/api/v1/players', [PlayerController::class, 'list'], 'players.read');
    $router->post('/api/v1/players', [PlayerController::class, 'create'], 'players.manage');
    $router->post('/api/v1/players/registration-override', [TournamentController::class, 'registrationOverride'], 'players.registration.override_deadline');
    $router->get('/api/v1/players/{id}', [PlayerController::class, 'get'], 'players.read');
    $router->post('/api/v1/players/{id}/photo', [PlayerController::class, 'uploadPhoto'], 'players.photo.self');
    $router->post('/api/v1/players/{id}', [PlayerController::class, 'update'], 'players.manage');
    $router->post('/api/v1/players/{id}/delete', [PlayerController::class, 'delete'], 'players.manage');
    $router->get('/api/v1/players/{id}/history', [PlayerController::class, 'history'], 'players.read');

    $router->get('/api/v1/tournaments/logical', [LogicalTournamentController::class, 'list'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/logical', [LogicalTournamentController::class, 'create'], 'tournaments.manage');
    $router->get('/api/v1/tournaments/logical/available-categories', [LogicalTournamentController::class, 'availableCategories'], 'tournaments.manage');
    $router->get('/api/v1/tournaments/logical/{logical_id}', [LogicalTournamentController::class, 'get'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/logical/{logical_id}/details', [LogicalTournamentController::class, 'updateDetails'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/logical/{logical_id}/rules', [LogicalTournamentController::class, 'updateRules'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/logical/{logical_id}/categories', [LogicalTournamentController::class, 'addCategory'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/logical/{logical_id}/categories/remove', [LogicalTournamentController::class, 'removeCategory'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/logical/{logical_id}/retire', [LogicalTournamentController::class, 'retire'], 'tournaments.manage');

    $router->get('/api/v1/tournaments', [TournamentController::class, 'list'], 'tournaments.read');
    $router->post('/api/v1/tournaments', [TournamentController::class, 'create'], 'tournaments.manage');
    $router->get('/api/v1/tournaments/{id}', [TournamentController::class, 'get'], 'tournaments.read');
    $router->post('/api/v1/tournaments/{id}', [TournamentController::class, 'update'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/{id}/status', [TournamentController::class, 'status'], 'tournaments.manage');
    $router->post('/api/v1/tournaments/{id}/teams', [TournamentController::class, 'registerTeam'], 'tournaments.teams.manage');
    $router->get('/api/v1/tournaments/{id}/teams', [TournamentController::class, 'teams'], 'tournaments.teams.read');
    $router->post('/api/v1/tournaments/{id}/teams/{team_id}/director', [TournamentController::class, 'assignDirector'], 'tournaments.teams.manage');
    $router->get('/api/v1/tournaments/{id}/my-team', [TournamentController::class, 'myTeam'], 'teams.self.read');
    $router->post('/api/v1/tournaments/{id}/my-team/players', [TournamentController::class, 'registerPlayerForDirector'], 'players.self.manage');
    $router->post('/api/v1/tournaments/{id}/players', [TournamentController::class, 'registerPlayer'], 'players.registration.manage');
    $router->post('/api/v1/tournaments/{id}/players/batch', [TournamentController::class, 'registerPlayers'], 'players.registration.manage');
    $router->delete('/api/v1/tournaments/{id}/players/{registration_id}', [TournamentController::class, 'removePlayerFromRoster'], 'players.registration.manage');
    $router->get('/api/v1/tournaments/{id}/players', [TournamentController::class, 'players'], 'tournaments.players.read');
    $router->post('/api/v1/tournaments/{id}/player-registration', [TournamentController::class, 'togglePlayerRegistration'], 'tournaments.registration.manage');

    $router->post('/api/v1/tournaments/{id}/schedule/generate', [ScheduleController::class, 'generate'], 'tournament_formats.manage');
    $router->get('/api/v1/tournaments/{id}/stages', [ScheduleController::class, 'stages'], 'tournaments.read');
    $router->get('/api/v1/tournaments/{id}/rounds', [ScheduleController::class, 'rounds'], 'tournaments.read');
    $router->get('/api/v1/tournaments/{id}/groups', [TournamentFormatController::class, 'groups'], 'tournaments.read');
    $router->post('/api/v1/tournaments/{id}/groups/generate', [TournamentFormatController::class, 'createGroups'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/groups/cancel', [TournamentFormatController::class, 'cancelGroups'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/group-stage/generate', [TournamentFormatController::class, 'generateGroupSchedule'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/cup/generate', [TournamentFormatController::class, 'generateCupBracket'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/qualification/generate', [TournamentFormatController::class, 'qualifyFlash'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/league/qualification/generate', [TournamentFormatController::class, 'startLeagueKnockout'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/knockout/advance', [TournamentFormatController::class, 'advanceKnockout'], 'tournament_formats.manage');
    $router->post('/api/v1/tournaments/{id}/rounds', [ScheduleController::class, 'createRound'], 'matches.manage');
    $router->post('/api/v1/rounds/{id}', [ScheduleController::class, 'updateRound'], 'matches.manage');
    $router->get('/api/v1/rounds/{id}/matches', [ScheduleController::class, 'matchesByRound'], 'tournaments.read');
    $router->post('/api/v1/rounds/{id}/matches', [ScheduleController::class, 'createMatch'], 'matches.manage');
    $router->get('/api/v1/matches/by-date', [ScheduleController::class, 'matchesByDate'], 'matches.read');
    $router->post('/api/v1/matches/{id}/schedule', [ScheduleController::class, 'scheduleMatch'], 'matches.manage');
    $router->post('/api/v1/matches/{id}', [ScheduleController::class, 'updateMatch'], 'matches.manage');
    $router->post('/api/v1/matches/{id}/result', [MatchController::class, 'result'], 'matches.results.manage');
    $router->get('/api/v1/matches/{id}/report', [MatchReportController::class, 'getReport'], 'matches.read');
    $router->post('/api/v1/matches/{id}/report', [MatchReportController::class, 'saveReport'], 'matches.results.manage');
    $router->get('/api/v1/expulsion-reasons', [MatchReportController::class, 'listReasons'], 'discipline.manage');
    $router->post('/api/v1/expulsion-reasons', [MatchReportController::class, 'createReason'], 'discipline.manage');
    $router->get('/api/v1/expulsion-reasons/{id}', [MatchReportController::class, 'getReason'], 'discipline.manage');
    $router->post('/api/v1/expulsion-reasons/{id}', [MatchReportController::class, 'updateReason'], 'discipline.manage');
    $router->post('/api/v1/expulsion-reasons/{id}/delete', [MatchReportController::class, 'deleteReason'], 'discipline.manage');
    $router->get('/api/v1/players/{id}/suspensions', [MatchReportController::class, 'playerSuspensions'], 'discipline.manage');

    $router->get('/api/v1/tournaments/{id}/standings', [StandingsController::class, 'tournament'], 'tournaments.read');
    $router->get('/api/v1/groups/{id}/standings', [StandingsController::class, 'group'], 'tournaments.read');
    $router->get('/api/v1/audit', [AuditController::class, 'list'], 'system.audit.read');

    $router->get('/api/v1/public/tournaments', [PublicTournamentController::class, 'list'], 'public');
    $router->get('/api/v1/public/matches/first', [PublicTournamentController::class, 'firstMatchOfDay'], 'public');
    $router->get('/api/v1/public/facilities/images', [FacilityController::class, 'images'], 'public');
    $router->get('/api/v1/public/facilities/images/{filename}', [FacilityController::class, 'image'], 'public');
    $router->get('/api/v1/public/tournaments/{id}/top-scorers', [PublicTournamentController::class, 'topScorers'], 'public');
    $router->get('/api/v1/public/tournaments/{id}/images', [PublicTournamentController::class, 'images'], 'public');
    $router->get('/api/v1/public/tournaments/{id}/images/{filename}', [PublicTournamentController::class, 'image'], 'public');

    return $router;
};
