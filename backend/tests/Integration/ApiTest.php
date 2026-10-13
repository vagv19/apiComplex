<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Core\HttpException;
use App\Controllers\MatchController;
use App\Controllers\MatchReportController;
use App\Controllers\FacilityController;
use App\Controllers\PublicTournamentController;
use App\Controllers\TournamentController;
use App\Core\Request;
use App\Repositories\CategoryRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\PublicTournamentRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TournamentRepository;
use App\Services\ScheduleGeneratorService;
use App\Services\MatchResultService;
use App\Services\MatchSheetPdfService;
use App\Services\MatchSheetService;
use App\Services\PublicTournamentService;
use App\Services\FacilityService;
use App\Services\TournamentFormatService;
use PHPUnit\Framework\TestCase;
use PDO;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec('DELETE FROM match_result_history');
        $pdo->exec('DELETE FROM player_suspensions');
        $pdo->exec('DELETE FROM match_disciplinary_events');
        $pdo->exec('DELETE FROM match_goals');
        $pdo->exec('DELETE FROM match_player_attendance');
        $pdo->exec('DELETE FROM matches');
        $pdo->exec('DELETE FROM rounds');
        $pdo->exec('DELETE FROM group_teams');
        $pdo->exec('DELETE FROM tournament_groups');
        $pdo->exec('DELETE FROM tournament_stages');
        $pdo->exec('DELETE FROM tournament_players');
        $pdo->exec('DELETE FROM tournament_teams');
        $pdo->exec('DELETE FROM tournaments');
        $pdo->exec('DELETE FROM players');
        $pdo->exec('DELETE FROM teams');
        $pdo->exec('DELETE FROM categories');
        $pdo->exec('DELETE FROM expulsion_reasons');
        $pdo->exec('DELETE FROM users');

        $pdo->exec("INSERT INTO categories (id, name, description, is_active) VALUES (1, 'Senior', 'Senior category', 1)");
        $pdo->exec("INSERT INTO teams (id, name, short_name, is_active) VALUES (1, 'Team A', 'A', 1)");
        $pdo->exec("INSERT INTO teams (id, name, short_name, is_active) VALUES (2, 'Team B', 'B', 1)");
        $pdo->exec("INSERT INTO teams (id, name, short_name, is_active) VALUES (3, 'Team C', 'C', 1)");
        $pdo->exec("INSERT INTO teams (id, name, short_name, is_active) VALUES (4, 'Team D', 'D', 1)");
        $pdo->exec("INSERT INTO players (id, first_name, last_name, is_active) VALUES (1, 'Player', 'One', 1)");
        $pdo->exec("INSERT INTO players (id, first_name, last_name, is_active) VALUES (2, 'Player', 'Two', 1)");
        $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, is_active) VALUES (1, 'Admin', 'admin@example.com', '\$2y\$10\$ee8N2z6dE1IiFhQ8d7Gv0eCgg3i9ezVYk0rU7w2wZq3DgG1kP4lAq', 'admin', 1)");
    }

    public function testCatalogAndTournamentCrud(): void
    {
        $categoryRepo = new CategoryRepository();
        $createdCategory = $categoryRepo->create(['name' => 'Junior', 'description' => 'Juvenil']);
        $this->assertNotNull($createdCategory);
        $this->assertSame('Junior', $createdCategory['name']);

        $teamRepo = new TeamRepository();
        $createdTeam = $teamRepo->create(['name' => 'Real Team', 'short_name' => 'RT', 'contact_name' => 'Coach']);
        $this->assertNotNull($createdTeam);
        $this->assertSame('Real Team', $createdTeam['name']);
        $this->assertSame('Coach', $createdTeam['contact_name']);

        $playerRepo = new PlayerRepository();
        $createdPlayer = $playerRepo->create(['first_name' => 'Alex', 'last_name' => 'Smith', 'birth_date' => '2000-01-02', 'email' => 'alex@example.com']);
        $this->assertSame('2000-01-02', $createdPlayer['birth_date']);
        $this->assertSame('alex@example.com', $createdPlayer['email']);

        $tournamentRepo = new TournamentRepository();
        $tournament = $tournamentRepo->create(['name' => 'Spring League', 'category_id' => $createdCategory['id'], 'format' => 'LEAGUE', 'status' => 'REGISTRATION']);
        $this->assertSame('Spring League', $tournament['name']);
        $this->assertSame('LEAGUE', $tournament['format']);
        $this->assertSame('REGISTRATION', $tournament['status']);
        $this->assertNull($tournament['start_date']);
        $this->assertSame(3, (int) $tournament['points_for_win']);

        $updatedTournament = $tournamentRepo->update((int) $tournament['id'], [
            'name' => 'Spring League',
            'allow_player_registration' => false,
        ]);
        $this->assertSame((int) $createdCategory['id'], (int) $updatedTournament['category_id']);
        $this->assertSame('LEAGUE', $updatedTournament['format']);
        $this->assertSame(0, (int) $updatedTournament['allow_player_registration']);

        $this->assertTrue($tournamentRepo->addTeamToTournament((int) $tournament['id'], (int) $createdTeam['id']));
        $this->assertCount(1, $tournamentRepo->getTeamsForTournament((int) $tournament['id']));
    }

    public function testTournamentCreateRequiresAnExistingCategory(): void
    {
        ob_start();
        (new TournamentController())->create(
            new Request('POST', '/api/v1/tournaments', [], [], ['name' => 'Missing category']),
            []
        );
        $missingCategoryResponse = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($missingCategoryResponse['success']);
        $this->assertArrayHasKey('category_id', $missingCategoryResponse['errors']);

        ob_start();
        (new TournamentController())->create(
            new Request('POST', '/api/v1/tournaments', [], [], ['name' => 'Unknown category', 'category_id' => 999]),
            []
        );
        $unknownCategoryResponse = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($unknownCategoryResponse['success']);
        $this->assertSame('Categoría no encontrada.', $unknownCategoryResponse['message']);
    }

    public function testPlayerTournamentRegistrationDuplicatesArePrevented(): void
    {
        $tournamentRepo = new TournamentRepository();
        $tournament = $tournamentRepo->create([
            'name' => 'Player Test League',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
            'rounds_count' => 1,
            'player_registration_deadline_round' => 1,
        ]);

        $teamRepo = new TeamRepository();
        $team = $teamRepo->create(['name' => 'Alpha FC', 'short_name' => 'ALP']);
        $secondTeam = $teamRepo->create(['name' => 'Bravo FC', 'short_name' => 'BRV']);
        $playerRepo = new PlayerRepository();
        $player = $playerRepo->create(['first_name' => 'Alex', 'last_name' => 'Smith']);

        $tournamentRepo->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        $tournamentRepo->addTeamToTournament((int) $tournament['id'], (int) $secondTeam['id']);
        $this->assertTrue($tournamentRepo->addPlayerToTournament((int) $tournament['id'], (int) $team['id'], (int) $player['id']));
        $this->assertFalse($tournamentRepo->addPlayerToTournament((int) $tournament['id'], (int) $team['id'], (int) $player['id']));
        $roster = $tournamentRepo->getPlayersForTournament((int) $tournament['id']);
        $this->assertSame((int) $team['id'], (int) $roster[0]['team_id']);
        $this->assertGreaterThan(0, (int) $roster[0]['tournament_team_id']);

        (new ScheduleGeneratorService())->generateLeagueSchedule((int) $tournament['id']);
        Database::getConnection()->exec("UPDATE matches SET status = 'COMPLETED', home_score = 0, away_score = 0");
        Database::getConnection()->exec("UPDATE rounds SET status = 'COMPLETED'");
        $this->expectException(\DomainException::class);
        $tournamentRepo->addPlayerToTournament((int) $tournament['id'], (int) $secondTeam['id'], 2);
    }

    public function testScheduleGeneratorCreatesLeagueRounds(): void
    {
        $tournamentRepo = new TournamentRepository();
        $tournament = $tournamentRepo->create(['name' => 'League Generator', 'category_id' => 1, 'format' => 'LEAGUE', 'status' => 'REGISTRATION', 'rounds_count' => 3]);

        $teamRepo = new TeamRepository();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $teamName) {
            $team = $teamRepo->create(['name' => $teamName, 'short_name' => substr($teamName, 0, 3)]);
            $tournamentRepo->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $service = new ScheduleGeneratorService();
        $result = $service->generateLeagueSchedule((int) $tournament['id']);
        $this->assertSame(3, $result['rounds']);
        $this->assertSame(6, $result['matches']);
        $rounds = Database::getConnection()->query('SELECT id FROM rounds ORDER BY round_number')->fetchAll();
        foreach ($rounds as $round) {
            $matches = Database::getConnection()->prepare('SELECT home_tournament_team_id, away_tournament_team_id FROM matches WHERE round_id = :round_id');
            $matches->execute([':round_id' => $round['id']]);
            $appearances = [];
            foreach ($matches->fetchAll() as $match) {
                $appearances[] = (int) $match['home_tournament_team_id'];
                $appearances[] = (int) $match['away_tournament_team_id'];
            }
            $this->assertCount(4, array_unique($appearances));
        }
        $this->expectException(\DomainException::class);
        $service->generateLeagueSchedule((int) $tournament['id']);
    }

    public function testOddTeamScheduleBalancesByesAndResultFeedsStandings(): void
    {
        $repository = new TournamentRepository();
        $tournament = $repository->create([
            'name' => 'Odd League',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
            'rounds_count' => 6,
        ]);
        foreach (['Alpha', 'Bravo', 'Charlie'] as $teamName) {
            $team = (new TeamRepository())->create(['name' => $teamName]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $result = (new ScheduleGeneratorService())->generateLeagueSchedule((int) $tournament['id']);
        $this->assertSame(6, $result['rounds']);
        $this->assertSame(6, $result['matches']);
        $this->assertSame(6, $result['byes']);
        $byeCounts = Database::getConnection()->query('SELECT home_tournament_team_id AS team_id, COUNT(*) AS bye_count FROM matches WHERE is_bye = 1 GROUP BY home_tournament_team_id')->fetchAll();
        $counts = array_column($byeCounts, 'bye_count');
        $this->assertSame(2, min($counts));
        $this->assertSame(2, max($counts));

        $match = Database::getConnection()->query("SELECT id, home_tournament_team_id, away_tournament_team_id FROM matches WHERE is_bye = 0 ORDER BY id LIMIT 1")->fetch();
        ob_start();
        (new MatchController())->result(new Request('POST', '/api/v1/matches/' . $match['id'] . '/result', [], [], ['home_score' => 3, 'away_score' => 1]), ['id' => $match['id']]);
        $response = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($response['success']);

        $standings = (new \App\Services\StandingsService())->calculate((int) $tournament['id']);
        $this->assertSame(3, (int) $standings[0]['points']);
        $this->assertSame(2, (int) $standings[0]['goal_difference']);
        $teamLookup = Database::getConnection()->prepare('SELECT team_id FROM tournament_teams WHERE id = :id');
        $teamLookup->execute([':id' => $match['away_tournament_team_id']]);
        $awayTeamId = (int) $teamLookup->fetchColumn();
        $awayStanding = array_values(array_filter($standings, static fn(array $row): bool => $row['team_id'] === $awayTeamId));
        $this->assertSame(1, (int) $awayStanding[0]['played']);
    }

    public function testLeagueStartsKnockoutAfterAllRegularMatchesAndPreservesRegularStandings(): void
    {
        $repository = new TournamentRepository();
        $tournament = $repository->create([
            'name' => 'League Playoff',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
            'rounds_count' => 1,
            'qualified_teams' => 4,
        ]);
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $teamName) {
            $team = (new TeamRepository())->create(['name' => $teamName]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        (new ScheduleGeneratorService())->generateLeagueSchedule((int) $tournament['id']);
        $matches = Database::getConnection()->query("SELECT id FROM matches WHERE is_bye = 0 ORDER BY id")->fetchAll();
        $this->assertCount(2, $matches);

        $formats = new TournamentFormatService();
        $results = new MatchResultService();
        $firstResult = $results->recordResult((int) $matches[0]['id'], 2, 0, null, 'Jornada regular');
        $this->assertArrayNotHasKey('knockout', $firstResult);
        $this->assertSame(0, (int) Database::getConnection()->query("SELECT COUNT(*) FROM tournament_stages WHERE stage_type = 'KNOCKOUT'")->fetchColumn());

        $routerFactory = require dirname(__DIR__, 2) . '/src/Routes/api.php';
        $router = $routerFactory();
        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/league/qualification/generate',
            [],
            [],
            [],
        ));
        $pendingResponse = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($pendingResponse['success']);
        $this->assertSame(409, http_response_code());
        $this->assertSame(0, (int) Database::getConnection()->query("SELECT COUNT(*) FROM tournament_stages WHERE stage_type = 'KNOCKOUT'")->fetchColumn());

        ob_start();
        (new MatchController())->result(
            new Request(
                'POST',
                '/api/v1/matches/' . $matches[1]['id'] . '/result',
                [],
                [],
                ['home_score' => 1, 'away_score' => 0, 'reason' => 'Último resultado regular'],
            ),
            ['id' => $matches[1]['id']],
        );
        $response = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($response['success']);
        $this->assertSame('Resultado registrado.', $response['message']);
        $finalResult = $response['data'];
        $this->assertArrayNotHasKey('knockout', $finalResult);
        $this->assertSame(0, (int) Database::getConnection()->query("SELECT COUNT(*) FROM tournament_stages WHERE stage_type = 'KNOCKOUT'")->fetchColumn());

        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/league/qualification/generate',
            [],
            [],
            [],
        ));
        $startedResponse = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($startedResponse['success']);
        $this->assertSame(201, http_response_code());
        $this->assertTrue($startedResponse['data']['started']);
        $this->assertSame(4, $startedResponse['data']['qualified_teams']);
        $this->assertSame('Semifinales', $startedResponse['data']['round_name']);
        $this->assertSame('Liguilla', Database::getConnection()->query("SELECT name FROM tournament_stages WHERE stage_type = 'KNOCKOUT'")->fetchColumn());
        $this->assertSame('COMPLETED', Database::getConnection()->query("SELECT status FROM tournament_stages WHERE stage_type = 'LEAGUE'")->fetchColumn());
        $this->assertSame('IN_PROGRESS', $repository->findById((int) $tournament['id'])['status']);
        $this->assertSame(2, (int) Database::getConnection()->query('SELECT COUNT(*) FROM rounds')->fetchColumn());
        $this->assertSame(4, (int) Database::getConnection()->query('SELECT COUNT(*) FROM matches')->fetchColumn());

        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/league/qualification/generate',
            [],
            [],
            [],
        ));
        $duplicateResponse = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($duplicateResponse['success']);
        $this->assertSame(409, http_response_code());
        $this->assertSame(4, (int) Database::getConnection()->query('SELECT COUNT(*) FROM matches')->fetchColumn());

        $standings = (new \App\Services\StandingsService())->calculate((int) $tournament['id']);
        foreach ($standings as $standing) {
            $this->assertSame(1, $standing['played']);
        }

        $this->assertArrayNotHasKey('knockout', $results->recordResult((int) $matches[1]['id'], 1, 0, null, 'Resultado repetido'));
        $this->assertSame(4, (int) Database::getConnection()->query('SELECT COUNT(*) FROM matches')->fetchColumn());

        $this->expectException(\DomainException::class);
        $results->recordResult((int) $matches[0]['id'], 0, 1, null, 'Corrección posterior a la liguilla');
    }

    public function testLeagueLiguillaWaitsForRealMatchesAndIgnoresByes(): void
    {
        $repository = new TournamentRepository();
        $tournament = $repository->create([
            'name' => 'Odd League Playoff',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
            'rounds_count' => 3,
            'qualified_teams' => 3,
        ]);
        foreach (['Alpha', 'Bravo', 'Charlie'] as $teamName) {
            $team = (new TeamRepository())->create(['name' => $teamName]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $schedule = (new ScheduleGeneratorService())->generateLeagueSchedule((int) $tournament['id']);
        $this->assertSame(3, $schedule['matches']);
        $this->assertSame(3, $schedule['byes']);

        $matches = Database::getConnection()->query("SELECT id FROM matches WHERE is_bye = 0 ORDER BY id")->fetchAll();
        $results = new MatchResultService();
        foreach ($matches as $index => $match) {
            $result = $results->recordResult((int) $match['id'], 1, 0, null, 'Jornada impar');
            $this->assertArrayNotHasKey('knockout', $result);
        }

        $this->assertSame(0, (int) Database::getConnection()->query("SELECT COUNT(*) FROM tournament_stages WHERE stage_type = 'KNOCKOUT'")->fetchColumn());
        $standings = (new \App\Services\StandingsService())->calculate((int) $tournament['id']);
        $bracket = (new TournamentFormatService())->startLeagueKnockout((int) $tournament['id']);
        $this->assertSame(3, $bracket['qualified_teams']);
        $this->assertTrue($bracket['started']);
        $this->assertSame(3, (int) Database::getConnection()->query("SELECT COUNT(*) FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.stage_type = 'LEAGUE' AND m.is_bye = 1")->fetchColumn());
        $byeTeam = Database::getConnection()->query("SELECT m.home_tournament_team_id FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.stage_type = 'KNOCKOUT' AND m.is_bye = 1")->fetchColumn();
        $this->assertSame($standings[0]['tournament_team_id'], (int) $byeTeam);
        $this->assertSame(1, (int) Database::getConnection()->query("SELECT COUNT(*) FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.stage_type = 'KNOCKOUT' AND m.is_bye = 0")->fetchColumn());
    }

    public function testKnockoutByesGoToHighestSeeds(): void
    {
        $algorithms = new \App\Services\ScheduleAlgorithms();

        foreach ([5 => 3, 6 => 2, 7 => 1] as $teamCount => $expectedByes) {
            $seededTeams = range(1, $teamCount);
            $pairs = $algorithms->knockoutPairs($seededTeams);
            $byeTeams = [];
            foreach ($pairs as $pair) {
                if ($pair['home'] === null || $pair['away'] === null) {
                    $byeTeams[] = $pair['home'] ?? $pair['away'];
                }
            }

            $this->assertCount($expectedByes, $byeTeams);
            $this->assertEquals(range(1, $expectedByes), $byeTeams);
        }
    }

    public function testFlashGroupScheduleIsBalancedAndNeverCrossesGroups(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Flash Groups',
            'category_id' => 1,
            'format' => 'FLASH',
        ]);
        $repository = new TournamentRepository();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf'] as $name) {
            $team = (new TeamRepository())->create(['name' => $name]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $formats = new TournamentFormatService();
        $created = $formats->createFlashGroups((int) $tournament['id'], 2);
        $this->assertSame(2, $created['groups']);
        $this->assertSame(7, $created['teams']);
        $groups = $formats->listGroups((int) $tournament['id']);
        $this->assertSame([3, 4], array_map(static fn(array $group): int => count($group['teams']), $groups));

        $schedule = $formats->generateFlashGroupSchedule((int) $tournament['id']);
        $this->assertSame(6, $schedule['rounds']);
        $this->assertSame(9, $schedule['matches']);
        $this->assertSame(3, $schedule['byes']);

        $rounds = Database::getConnection()->query('SELECT id, group_id FROM rounds ORDER BY group_id, round_number')->fetchAll();
        foreach ($rounds as $round) {
            $appearanceQuery = Database::getConnection()->prepare('SELECT home_tournament_team_id, away_tournament_team_id FROM matches WHERE round_id = :round_id');
            $appearanceQuery->execute([':round_id' => $round['id']]);
            $appearances = [];
            foreach ($appearanceQuery->fetchAll() as $match) {
                foreach ([$match['home_tournament_team_id'], $match['away_tournament_team_id']] as $teamId) {
                    if ($teamId !== null) {
                        $appearances[] = (int) $teamId;
                    }
                }
            }
            $this->assertCount(count(array_unique($appearances)), $appearances);

            $query = Database::getConnection()->prepare('SELECT gt_home.group_id AS home_group, gt_away.group_id AS away_group FROM matches m JOIN group_teams gt_home ON gt_home.tournament_team_id = m.home_tournament_team_id JOIN group_teams gt_away ON gt_away.tournament_team_id = m.away_tournament_team_id WHERE m.round_id = :round_id');
            $query->execute([':round_id' => $round['id']]);
            foreach ($query->fetchAll() as $match) {
                $this->assertSame((int) $round['group_id'], (int) $match['home_group']);
                $this->assertSame((int) $round['group_id'], (int) $match['away_group']);
            }
        }
        $groupByeCounts = Database::getConnection()->query('SELECT r.group_id, COUNT(*) AS byes FROM matches m JOIN rounds r ON r.id = m.round_id WHERE m.is_bye = 1 GROUP BY r.group_id ORDER BY r.group_id')->fetchAll();
        $this->assertSame(3, (int) $groupByeCounts[0]['byes']);

        $this->expectException(\DomainException::class);
        $formats->generateFlashGroupSchedule((int) $tournament['id']);
    }

    public function testFlashQualificationPrioritizesGroupWinnersAndBestRunnerUp(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Flash Qualification',
            'category_id' => 1,
            'format' => 'FLASH',
            'qualified_teams' => 3,
        ]);
        $repository = new TournamentRepository();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $team = (new TeamRepository())->create(['name' => $name]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $formats = new TournamentFormatService();
        $formats->createFlashGroups((int) $tournament['id'], 2);
        $formats->generateFlashGroupSchedule((int) $tournament['id']);
        $matches = Database::getConnection()->query('SELECT m.id, g.group_order FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_groups g ON g.id = r.group_id WHERE m.is_bye = 0 ORDER BY g.group_order')->fetchAll();
        foreach ($matches as $match) {
            $score = (int) $match['group_order'] === 1 ? 1 : 3;
            (new MatchResultService())->recordResult((int) $match['id'], $score, 0, null, 'Prueba de clasificación');
        }

        $bracket = $formats->qualifyFlash((int) $tournament['id'], 2, 3);
        $this->assertSame(3, $bracket['qualified_teams']);
        $this->assertSame(1, $bracket['byes']);
        $this->assertSame([1, 1, 2], array_column($bracket['qualified'], 'group_position'));
        $this->assertSame('Grupo 1', $bracket['qualified'][2]['group_name']);
        $this->assertCount(2, $bracket['group_standings']);

        $groupMatchId = (int) $matches[0]['id'];
        try {
            (new MatchResultService())->recordResult($groupMatchId, 0, 2, null, 'Corrección posterior');
            $this->fail('No se debe permitir cambiar una fase de grupos ya usada para clasificar.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('dependen', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $formats->qualifyFlash((int) $tournament['id'], 2, 3);
    }

    public function testCupAutomaticAdvancesAndTiedKnockoutRequiresDecisivePenalties(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Cup Bracket',
            'category_id' => 1,
            'format' => 'CUP',
        ]);
        $repository = new TournamentRepository();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $team = (new TeamRepository())->create(['name' => $name]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $formats = new TournamentFormatService();
        $bracket = $formats->generateCupBracket((int) $tournament['id']);
        $this->assertSame(1, $bracket['matches']);
        $this->assertSame(3, $bracket['byes']);
        try {
            $formats->generateCupBracket((int) $tournament['id']);
            $this->fail('No se debe crear un segundo cuadro de Copa.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('fases', $exception->getMessage());
        }
        $match = Database::getConnection()->query('SELECT id FROM matches WHERE is_bye = 0 ORDER BY id LIMIT 1')->fetch();

        try {
            (new MatchResultService())->recordResult((int) $match['id'], 1, 1, null, 'Empate sin penales');
            $this->fail('Un partido eliminatorio empatado no debe quedar sin ganador.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('penales', $exception->getMessage());
        }
        (new MatchResultService())->recordResult((int) $match['id'], 1, 1, null, 'Empate resuelto', 3, 2);
        $advanced = $formats->advanceKnockout((int) $tournament['id']);
        $this->assertSame(2, $advanced['round_number']);
        $this->assertSame(2, $advanced['matches']);
        try {
            (new MatchResultService())->recordResult((int) $match['id'], 2, 1, null, 'Corrección que cambiaría el ganador');
            $this->fail('No se debe cambiar un ganador cuando ya existe la siguiente ronda.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('dependen', $exception->getMessage());
        }

        $semiFinals = Database::getConnection()->query('SELECT id FROM matches WHERE round_id = (SELECT MAX(id) FROM rounds) ORDER BY match_number')->fetchAll();
        foreach ($semiFinals as $semiFinal) {
            (new MatchResultService())->recordResult((int) $semiFinal['id'], 2, 0, null, 'Semifinal');
        }
        $final = $formats->advanceKnockout((int) $tournament['id']);
        $this->assertSame(3, $final['round_number']);
        $finalMatch = Database::getConnection()->query('SELECT id FROM matches WHERE round_id = (SELECT MAX(id) FROM rounds)')->fetch();
        (new MatchResultService())->recordResult((int) $finalMatch['id'], 0, 0, null, 'Final con penales', 4, 3);
        $champion = $formats->advanceKnockout((int) $tournament['id']);
        $this->assertTrue($champion['completed']);
        $this->assertNotEmpty($champion['champion']['team_name']);
        $this->assertSame('COMPLETED', (new TournamentRepository())->findById((int) $tournament['id'])['status']);
    }

    public function testFlashGroupEndpointValidatesRequestAndPersistsAssignment(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Flash API',
            'category_id' => 1,
            'format' => 'FLASH',
        ]);
        $repository = new TournamentRepository();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $team = (new TeamRepository())->create(['name' => $name]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $routerFactory = require dirname(__DIR__, 2) . '/src/Routes/api.php';
        $router = $routerFactory();
        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/groups/generate',
            [],
            [],
            ['group_count' => 0],
        ));
        $invalidResponse = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($invalidResponse['success']);
        $this->assertSame(409, http_response_code());

        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/groups/generate',
            [],
            [],
            ['group_count' => 2],
        ));
        $createdResponse = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($createdResponse['success']);
        $this->assertSame(201, http_response_code());
        $this->assertSame(4, $createdResponse['data']['teams']);
        $this->assertCount(2, (new TournamentFormatService())->listGroups((int) $tournament['id']));

        ob_start();
        $router->dispatch(new Request(
            'DELETE',
            '/api/v1/tournaments/' . $tournament['id'] . '/groups',
            [],
            [],
            [],
        ));
        $cancelledResponse = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($cancelledResponse['success']);
        $this->assertSame(200, http_response_code());
        $this->assertSame(2, $cancelledResponse['data']['groups_deleted']);
        $this->assertSame(4, $cancelledResponse['data']['teams_unassigned']);
        $this->assertSame([], (new TournamentFormatService())->listGroups((int) $tournament['id']));
        $this->assertSame(0, (int) Database::getConnection()->query('SELECT COUNT(*) FROM tournament_stages')->fetchColumn());
        $this->assertSame(0, (int) Database::getConnection()->query('SELECT COUNT(*) FROM group_teams')->fetchColumn());
        $this->assertSame(4, (int) Database::getConnection()->query('SELECT COUNT(*) FROM tournament_teams')->fetchColumn());
    }

    public function testFlashGroupsCannotBeCancelledAfterScheduleGeneration(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Flash Schedule Cancellation',
            'category_id' => 1,
            'format' => 'FLASH',
        ]);
        $repository = new TournamentRepository();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $team = (new TeamRepository())->create(['name' => $name]);
            $repository->addTeamToTournament((int) $tournament['id'], (int) $team['id']);
        }

        $formats = new TournamentFormatService();
        $formats->createFlashGroups((int) $tournament['id'], 2);
        $formats->generateFlashGroupSchedule((int) $tournament['id']);

        try {
            $formats->cancelFlashGroups((int) $tournament['id']);
            self::fail('Expected cancellation to be rejected after schedule generation.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('calendario ya fue generado', $exception->getMessage());
        }

        $routerFactory = require dirname(__DIR__, 2) . '/src/Routes/api.php';
        $router = $routerFactory();
        ob_start();
        $router->dispatch(new Request(
            'DELETE',
            '/api/v1/tournaments/' . $tournament['id'] . '/groups',
            [],
            [],
            [],
        ));
        $conflictResponse = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($conflictResponse['success']);
        $this->assertSame(409, http_response_code());
        $this->assertCount(2, $formats->listGroups((int) $tournament['id']));
        $this->assertGreaterThan(0, (int) Database::getConnection()->query('SELECT COUNT(*) FROM rounds')->fetchColumn());
        $this->assertGreaterThan(0, (int) Database::getConnection()->query('SELECT COUNT(*) FROM matches')->fetchColumn());
    }

    public function testMatchReportPersistsAndUpdatesAllDetailsIdempotently(): void
    {
        [$tournament, $matchId, $homePlayer, $awayPlayer] = $this->createMatchReportFixture();
        $this->assertNotSame($tournament['home_team_id'], $tournament['home_tournament_team_id']);
        $this->assertNotSame($tournament['away_team_id'], $tournament['away_tournament_team_id']);
        $service = new \App\Services\MatchReportService();
        $reason = $service->createExpulsionReason([
            'name' => 'Roja directa',
            'description' => null,
            'suspension_matches' => 2,
            'is_active' => true,
        ]);

        $report = [
            'home_score' => 2,
            'away_score' => 1,
            'home_penalties' => null,
            'away_penalties' => null,
            'attendance' => [
                ['tournament_player_id' => $homePlayer, 'attendance_status' => 'PRESENT'],
                ['tournament_player_id' => $awayPlayer, 'attendance_status' => 'ABSENT'],
            ],
            'goals' => [
                ['client_key' => 'goal-one', 'tournament_player_id' => $homePlayer, 'credited_to_team_id' => (int) $tournament['home_tournament_team_id'], 'goal_type' => 'REGULAR', 'notes' => null],
                ['client_key' => 'goal-two', 'tournament_player_id' => $homePlayer, 'credited_to_team_id' => (int) $tournament['home_tournament_team_id'], 'goal_type' => 'PENALTY', 'notes' => 'Penal'],
                ['client_key' => 'goal-three', 'tournament_player_id' => $homePlayer, 'credited_to_team_id' => (int) $tournament['away_tournament_team_id'], 'goal_type' => 'OWN_GOAL', 'notes' => 'Autogol'],
            ],
            'disciplinary_events' => [
                ['client_key' => 'yellow-one', 'tournament_player_id' => $homePlayer, 'event_type' => 'YELLOW_CARD', 'expulsion_reason_id' => null, 'notes' => null],
                ['client_key' => 'yellow-two', 'tournament_player_id' => $homePlayer, 'event_type' => 'YELLOW_CARD', 'expulsion_reason_id' => null, 'notes' => null],
                ['client_key' => 'second-yellow', 'tournament_player_id' => $homePlayer, 'event_type' => 'SECOND_YELLOW_RED', 'expulsion_reason_id' => (int) $reason['id'], 'notes' => 'Segunda amarilla'],
            ],
        ];

        $service->saveMatchReport($matchId, $report, 1);
        $service->saveMatchReport($matchId, $report, 1);

        $pdo = Database::getConnection();
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM match_goals')->fetchColumn());
        $this->assertSame((int) $tournament['home_tournament_team_id'], (int) $pdo->query("SELECT credited_to_team_id FROM match_goals WHERE client_key = 'goal-one'")->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM match_disciplinary_events')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM match_player_attendance')->fetchColumn());
        $suspension = $pdo->query('SELECT * FROM player_suspensions')->fetch();
        $this->assertSame(2, (int) $suspension['total_matches']);
        $this->assertSame(0, (int) $suspension['served_matches']);
        $this->assertSame(2, (int) $suspension['remaining_matches']);
        $this->assertSame('ACTIVE', $suspension['status']);

        $service->updateExpulsionReason((int) $reason['id'], [
            'name' => 'Roja directa actualizada',
            'suspension_matches' => 7,
        ]);
        $this->assertSame('roja directa', strtolower($pdo->query('SELECT expulsion_reason_name FROM player_suspensions')->fetchColumn()));
        $this->assertSame(2, (int) $pdo->query('SELECT total_matches FROM player_suspensions')->fetchColumn());

        $suspensions = $service->suspensionsForPlayer((int) $pdo->query('SELECT player_id FROM tournament_players WHERE id = ' . $homePlayer)->fetchColumn());
        $this->assertCount(1, $suspensions);
        $this->assertSame('ACTIVE', $suspensions[0]['status']);
        $this->assertSame(2, (int) $suspensions[0]['remaining_matches']);

        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '1',
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        ob_start();
        (new MatchReportController())->getReport(new Request('GET', '/api/v1/matches/' . $matchId . '/report', [], $headers, []), ['id' => $matchId]);
        $loaded = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($loaded['success']);
        $this->assertSame((int) $tournament['home_tournament_team_id'], $loaded['data']['match']['home_tournament_team_id']);
        $this->assertSame((int) $tournament['away_tournament_team_id'], $loaded['data']['match']['away_tournament_team_id']);
        $this->assertCount(3, $loaded['data']['goals']);
        $this->assertSame((int) $tournament['home_tournament_team_id'], (int) $loaded['data']['goals'][0]['credited_to_team_id']);
        $this->assertCount(3, $loaded['data']['disciplinary_events']);
        $this->assertArrayNotHasKey('minute', $loaded['data']['goals'][0]);
        $this->assertArrayNotHasKey('minute', $loaded['data']['disciplinary_events'][0]);

        $deletion = $service->deleteOrDeactivateExpulsionReason((int) $reason['id']);
        $this->assertSame('deactivated', $deletion);
        $this->assertSame(0, (int) $service->findExpulsionReason((int) $reason['id'])['is_active']);
    }

    public function testMatchReportEndpointAcceptsResultsFormPayloadAndPersistsIt(): void
    {
        [$tournament, $matchId] = $this->createMatchReportFixture();
        $repository = new TournamentRepository();
        foreach ([
            [(int) $tournament['home_team_id'], 'Home', 'Player Two'],
            [(int) $tournament['home_team_id'], 'Home', 'Player Three'],
            [(int) $tournament['away_team_id'], 'Away', 'Player Two'],
            [(int) $tournament['away_team_id'], 'Away', 'Player Three'],
        ] as [$teamId, $firstName, $lastName]) {
            $player = (new PlayerRepository())->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
            $repository->addPlayerToTournament(
                (int) $tournament['id'],
                $teamId,
                (int) $player['id'],
            );
        }

        $service = new \App\Services\MatchReportService();
        $initialReport = $service->getMatchReport($matchId);
        $homePlayers = array_values(array_filter(
            $initialReport['players'],
            static fn (array $player): bool =>
                (int) $player['tournament_team_id'] === (int) $initialReport['match']['home_tournament_team_id'],
        ));
        $awayPlayers = array_values(array_filter(
            $initialReport['players'],
            static fn (array $player): bool =>
                (int) $player['tournament_team_id'] === (int) $initialReport['match']['away_tournament_team_id'],
        ));
        $this->assertCount(3, $homePlayers);
        $this->assertCount(3, $awayPlayers);

        $reason = $service->createExpulsionReason([
            'name' => 'Expulsión capturada desde resultados',
            'description' => null,
            'suspension_matches' => 1,
            'is_active' => true,
        ]);
        $scorerId = (int) $homePlayers[0]['tournament_player_id'];
        $attendance = array_map(
            static fn (array $player): array => [
                'tournament_player_id' => (int) $player['tournament_player_id'],
                'attendance_status' => 'PRESENT',
            ],
            $initialReport['players'],
        );
        $body = [
            'home_score' => 2,
            'away_score' => 0,
            'home_penalties' => null,
            'away_penalties' => null,
            'attendance' => $attendance,
            'goals' => [
                [
                    'client_key' => '50663548-df95-47ff-be32-100769f787ac',
                    'tournament_player_id' => $scorerId,
                    'credited_to_team_id' => (int) $initialReport['match']['home_tournament_team_id'],
                    'goal_type' => 'REGULAR',
                    'notes' => null,
                ],
                [
                    'client_key' => '13d3ce2c-e92c-4bec-8325-644c92167ae7',
                    'tournament_player_id' => $scorerId,
                    'credited_to_team_id' => (int) $initialReport['match']['home_tournament_team_id'],
                    'goal_type' => 'REGULAR',
                    'notes' => null,
                ],
            ],
            'disciplinary_events' => [[
                'client_key' => '778cd61d-c425-4a1d-bd14-28d78d931257',
                'tournament_player_id' => (int) $awayPlayers[0]['tournament_player_id'],
                'event_type' => 'DIRECT_RED',
                'expulsion_reason_id' => (int) $reason['id'],
                'notes' => '',
            ]],
            'reason' => 'Resultado capturado desde el panel de administración.',
        ];

        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '1',
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        $routerFactory = require dirname(__DIR__, 2) . '/src/Routes/api.php';
        ob_start();
        $routerFactory()->dispatch(new Request(
            'POST',
            '/api/v1/matches/' . $matchId . '/report',
            [],
            $headers,
            $body,
        ));
        $response = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($response['success']);
        $this->assertSame(200, http_response_code());
        $this->assertSame(2, (int) $response['data']['result']['current']['home_score']);
        $this->assertSame(0, (int) $response['data']['result']['current']['away_score']);
        $this->assertCount(6, $response['data']['report']['attendance']);
        $this->assertCount(2, $response['data']['report']['goals']);
        $this->assertCount(1, $response['data']['report']['disciplinary_events']);
        $this->assertSame(
            6,
            count(array_filter(
                $response['data']['report']['attendance'],
                static fn (array $entry): bool => $entry['attendance_status'] === 'PRESENT',
            )),
        );
        foreach ($response['data']['report']['goals'] as $goal) {
            $this->assertSame('REGULAR', $goal['goal_type']);
            $this->assertSame($scorerId, (int) $goal['tournament_player_id']);
            $this->assertSame(
                (int) $initialReport['match']['home_tournament_team_id'],
                (int) $goal['credited_to_team_id'],
            );
            $this->assertNull($goal['notes']);
        }
        $savedEvent = $response['data']['report']['disciplinary_events'][0];
        $this->assertSame('DIRECT_RED', $savedEvent['event_type']);
        $this->assertSame((int) $reason['id'], (int) $savedEvent['expulsion_reason_id']);
        $this->assertSame('', $savedEvent['notes']);

        $pdo = Database::getConnection();
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM match_goals')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM player_suspensions')->fetchColumn());
        $this->assertSame('COMPLETED', $pdo->query('SELECT status FROM matches WHERE id = ' . $matchId)->fetchColumn());
    }

    public function testMatchReportEndpointSavesScoreOnlyWithoutChangingExistingPlayerDetails(): void
    {
        [$tournament, $matchId, $homePlayer, $awayPlayer] = $this->createMatchReportFixture();
        $pdo = Database::getConnection();
        $pdo->prepare(
            'INSERT INTO match_player_attendance (match_id, tournament_player_id, attendance_status)
             VALUES (:match_id, :player_id, :status)'
        )->execute([
            ':match_id' => $matchId,
            ':player_id' => $homePlayer,
            ':status' => 'PRESENT',
        ]);
        $pdo->prepare(
            'INSERT INTO match_goals (match_id, tournament_player_id, credited_to_team_id, goal_type, notes, client_key)
             VALUES (:match_id, :player_id, :team_id, :goal_type, NULL, :client_key)'
        )->execute([
            ':match_id' => $matchId,
            ':player_id' => $homePlayer,
            ':team_id' => (int) $tournament['home_tournament_team_id'],
            ':goal_type' => 'REGULAR',
            ':client_key' => 'existing-goal',
        ]);
        try {
            (new \App\Services\MatchReportService())->saveMatchReport($matchId, [
                'home_score' => 1,
                'away_score' => 0,
                'home_penalties' => null,
                'away_penalties' => null,
            ], 1);
            self::fail('A match with active players must include the complete player report.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('jugadores registrados', $exception->getMessage());
        }
        $pdo->prepare('UPDATE tournament_players SET status = :status WHERE id IN (:home_player, :away_player)')
            ->execute([
                ':status' => 'INACTIVE',
                ':home_player' => $homePlayer,
                ':away_player' => $awayPlayer,
            ]);

        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '1',
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        $router = (require dirname(__DIR__, 2) . '/src/Routes/api.php')();
        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/matches/' . $matchId . '/report',
            [],
            $headers,
            [
                'home_score' => 1,
                'away_score' => 0,
                'home_penalties' => null,
                'away_penalties' => null,
                'reason' => 'Resultado sin plantilla activa.',
            ],
        ));
        $response = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($response['success']);
        $this->assertSame(1, (int) $response['data']['result']['current']['home_score']);
        $this->assertSame([], $response['data']['report']['players']);
        $this->assertCount(1, $response['data']['report']['attendance']);
        $this->assertCount(1, $response['data']['report']['goals']);
        $this->assertSame('existing-goal', $response['data']['report']['goals'][0]['client_key']);

        ob_start();
        $router->dispatch(new Request(
            'POST',
            '/api/v1/matches/' . $matchId . '/report',
            [],
            $headers,
            [
                'home_score' => 2,
                'away_score' => 0,
                'home_penalties' => null,
                'away_penalties' => null,
                'attendance' => [],
            ],
        ));
        $partialResponse = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($partialResponse['success']);
        $this->assertSame(422, http_response_code());
        $this->assertArrayHasKey('goals', $partialResponse['errors']);
        $this->assertSame(1, (int) $pdo->query(
            'SELECT home_score FROM matches WHERE id = ' . $matchId
        )->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM match_goals')->fetchColumn());
    }

    public function testMatchSheetReturnsDynamicMatchDetailsAndPlayerStatistics(): void
    {
        [$tournament, $matchId, $homePlayer, $awayPlayer] = $this->createMatchReportFixture('Arbitral sheet');
        $pdo = Database::getConnection();
        $pdo->prepare(
            'UPDATE matches SET match_date = :match_date, kickoff_time = :kickoff_time, venue = :venue,
                                home_score = :home_score, away_score = :away_score
             WHERE id = :match_id'
        )->execute([
            ':match_date' => '2026-10-12',
            ':kickoff_time' => '18:30:00',
            ':venue' => 'Campo principal',
            ':home_score' => 1,
            ':away_score' => 0,
            ':match_id' => $matchId,
        ]);
        $pdo->prepare('UPDATE tournament_players SET jersey_number = :number WHERE id = :id')->execute([
            ':number' => 9,
            ':id' => $homePlayer,
        ]);
        $pdo->prepare(
            'INSERT INTO match_goals (match_id, tournament_player_id, credited_to_team_id, goal_type, notes, client_key)
             VALUES (:match_id, :player_id, :team_id, :goal_type, NULL, :client_key)'
        )->execute([
            ':match_id' => $matchId,
            ':player_id' => $homePlayer,
            ':team_id' => (int) $tournament['home_tournament_team_id'],
            ':goal_type' => 'REGULAR',
            ':client_key' => 'sheet-goal',
        ]);
        $reason = (new \App\Services\MatchReportService())->createExpulsionReason([
            'name' => 'Sheet red card',
            'description' => null,
            'suspension_matches' => 0,
            'is_active' => true,
        ]);
        $eventInsert = $pdo->prepare(
            'INSERT INTO match_disciplinary_events
                (match_id, tournament_player_id, event_type, expulsion_reason_id, notes, client_key)
             VALUES (:match_id, :player_id, :event_type, :reason_id, NULL, :client_key)'
        );
        foreach ([
            [$homePlayer, 'YELLOW_CARD', null, 'sheet-yellow'],
            [$homePlayer, 'DIRECT_RED', (int) $reason['id'], 'sheet-red'],
        ] as [$playerId, $eventType, $reasonId, $clientKey]) {
            $eventInsert->execute([
                ':match_id' => $matchId,
                ':player_id' => $playerId,
                ':event_type' => $eventType,
                ':reason_id' => $reasonId,
                ':client_key' => $clientKey,
            ]);
        }

        $sheet = (new MatchSheetService())->getMatchSheet($matchId);

        $this->assertSame("Sport Complex 'La Pasion'", $sheet['league_name']);
        $this->assertSame('Arbitral sheet tournament', $sheet['tournament_name']);
        $this->assertSame('Senior', $sheet['category_name']);
        $this->assertSame('Jornada 1', $sheet['round_name']);
        $this->assertSame('Campo principal', $sheet['venue']);
        $this->assertSame('18:30', $sheet['kickoff_time']);
        $this->assertSame('Lunes', $sheet['match_day']);
        $this->assertSame('2026-10-12', $sheet['match_date']);
        $this->assertSame('Arbitral sheet home', $sheet['home_team']['name']);
        $this->assertSame('Arbitral sheet away', $sheet['away_team']['name']);
        $this->assertSame(1, $sheet['home_score']);
        $this->assertSame(0, $sheet['away_score']);
        $this->assertCount(1, $sheet['home_team']['players']);
        $this->assertCount(1, $sheet['away_team']['players']);
        $this->assertSame(9, $sheet['home_team']['players'][0]['jersey_number']);
        $this->assertSame('Home Player', $sheet['home_team']['players'][0]['name']);
        $this->assertSame(1, $sheet['home_team']['players'][0]['goals']);
        $this->assertSame(1, $sheet['home_team']['players'][0]['cautions']);
        $this->assertSame(1, $sheet['home_team']['players'][0]['expulsions']);

        $logoPath = dirname(__DIR__, 2) . '/img/application/logo.jpg';
        $logoDataUri = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath));
        $pdf = (new MatchSheetPdfService())->createDocument($sheet, $logoDataUri)->output();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));

        try {
            (new MatchSheetService())->getMatchSheet(999999);
            self::fail('Unknown matches must not return a match sheet.');
        } catch (\DomainException $exception) {
            $this->assertSame('Partido no encontrado.', $exception->getMessage());
        }
    }

    public function testMatchReportRejectsForeignPlayersAndScoreGoalMismatchAtomically(): void
    {
        [$tournament, $matchId, $homePlayer, $awayPlayer] = $this->createMatchReportFixture();
        $foreignTournament = (new TournamentRepository())->create([
            'name' => 'Other league',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
        ]);
        $foreignTeam = (new TeamRepository())->create(['name' => 'Foreign team']);
        $repository = new TournamentRepository();
        $repository->addTeamToTournament((int) $foreignTournament['id'], (int) $foreignTeam['id']);
        $foreignPlayer = (new PlayerRepository())->create(['first_name' => 'Foreign', 'last_name' => 'Player']);
        $repository->addPlayerToTournament((int) $foreignTournament['id'], (int) $foreignTeam['id'], (int) $foreignPlayer['id']);
        $foreignRegistration = (int) Database::getConnection()->query('SELECT id FROM tournament_players WHERE player_id = ' . (int) $foreignPlayer['id'])->fetchColumn();

        $base = [
            'home_score' => 1,
            'away_score' => 0,
            'home_penalties' => null,
            'away_penalties' => null,
            'attendance' => [],
            'goals' => [],
            'disciplinary_events' => [],
        ];
        $service = new \App\Services\MatchReportService();
        try {
            $service->saveMatchReport($matchId, array_replace($base, [
                'attendance' => [['tournament_player_id' => $foreignRegistration, 'attendance_status' => 'PRESENT']],
            ]), 1);
            self::fail('Foreign tournament players must not be accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('no pertenece', $exception->getMessage());
        }

        try {
            $service->saveMatchReport($matchId, array_replace($base, [
                'home_score' => 2,
                'goals' => [[
                    'client_key' => 'mismatched-goal',
                    'tournament_player_id' => $homePlayer,
                    'credited_to_team_id' => (int) $tournament['home_tournament_team_id'],
                    'goal_type' => 'REGULAR',
                    'notes' => null,
                ]],
            ]), 1);
            self::fail('Goal details must equal the saved score when present.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('coincidir', $exception->getMessage());
        }
        try {
            $service->saveMatchReport($matchId, array_replace($base, [
                'goals' => [[
                    'client_key' => 'wrong-credit',
                    'tournament_player_id' => $homePlayer,
                    'credited_to_team_id' => (int) $tournament['away_tournament_team_id'],
                    'goal_type' => 'REGULAR',
                    'notes' => null,
                ]],
            ]), 1);
            self::fail('A regular goal must be credited to the scorer team.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('equipo del jugador', $exception->getMessage());
        }
        try {
            $service->saveMatchReport($matchId, array_replace($base, [
                'goals' => [[
                    'client_key' => 'catalog-team-id',
                    'tournament_player_id' => $homePlayer,
                    'credited_to_team_id' => (int) $tournament['home_team_id'],
                    'goal_type' => 'REGULAR',
                    'notes' => null,
                ]],
            ]), 1);
            self::fail('Goal credits must use tournament-team registration IDs, not catalogue team IDs.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('equipos participantes', $exception->getMessage());
        }
        $match = Database::getConnection()->query('SELECT home_score, status FROM matches WHERE id = ' . $matchId)->fetch();
        $this->assertNull($match['home_score']);
        $this->assertSame('SCHEDULED', $match['status']);
        $this->assertSame(0, (int) Database::getConnection()->query('SELECT COUNT(*) FROM match_result_history')->fetchColumn());
        $this->assertGreaterThan(0, $awayPlayer);
    }

    public function testExpulsionReasonsAndZeroMatchSuspensions(): void
    {
        [$tournament, $matchId, $homePlayer] = $this->createMatchReportFixture();
        $service = new \App\Services\MatchReportService();
        $reason = $service->createExpulsionReason([
            'name' => 'Conducta deportiva',
            'description' => 'Motivo de prueba',
            'suspension_matches' => 0,
            'is_active' => true,
        ]);
        $report = [
            'home_score' => 0,
            'away_score' => 0,
            'home_penalties' => null,
            'away_penalties' => null,
            'attendance' => [],
            'goals' => [],
            'disciplinary_events' => [[
                'client_key' => 'zero-ban',
                'tournament_player_id' => $homePlayer,
                'event_type' => 'DIRECT_RED',
                'expulsion_reason_id' => (int) $reason['id'],
                'notes' => null,
            ]],
        ];
        $service->saveMatchReport($matchId, $report, 1);
        $suspension = Database::getConnection()->query('SELECT * FROM player_suspensions')->fetch();
        $this->assertSame(0, (int) $suspension['total_matches']);
        $this->assertSame(0, (int) $suspension['remaining_matches']);
        $this->assertSame('COMPLETED', $suspension['status']);
        $this->assertCount(1, $service->listExpulsionReasons(false));
        $this->assertSame('deactivated', $service->deleteOrDeactivateExpulsionReason((int) $reason['id']));
        $this->assertCount(0, $service->listExpulsionReasons(false));
    }

    public function testReportEndpointRejectsMinuteFieldsAndInactiveExpulsionReasons(): void
    {
        [, $matchId, $homePlayer] = $this->createMatchReportFixture();
        $service = new \App\Services\MatchReportService();
        $reason = $service->createExpulsionReason([
            'name' => 'Inactive reason',
            'description' => null,
            'suspension_matches' => 1,
            'is_active' => false,
        ]);
        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '1',
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        $body = [
            'home_score' => 0,
            'away_score' => 0,
            'home_penalties' => null,
            'away_penalties' => null,
            'attendance' => [],
            'goals' => [],
            'disciplinary_events' => [[
                'client_key' => 'minute-check',
                'tournament_player_id' => $homePlayer,
                'event_type' => 'DIRECT_RED',
                'expulsion_reason_id' => (int) $reason['id'],
                'notes' => null,
                'minute' => 12,
            ]],
        ];
        ob_start();
        (new MatchReportController())->saveReport(
            new Request('POST', '/api/v1/matches/' . $matchId . '/report', [], $headers, $body),
            ['id' => $matchId],
        );
        $minuteResponse = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($minuteResponse['success']);
        $this->assertSame(422, http_response_code());
        $this->assertArrayHasKey('minute', $minuteResponse['errors']['disciplinary_events'][0]);

        unset($body['disciplinary_events'][0]['minute']);
        try {
            $service->saveMatchReport($matchId, $body, 1);
            self::fail('Inactive reasons must not be used for new expulsions.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('inactivo', $exception->getMessage());
        }
        $this->assertNull(Database::getConnection()->query('SELECT home_score FROM matches WHERE id = ' . $matchId)->fetchColumn());
    }

    public function testMatchSchedulingAndGlobalMatchDayFeed(): void
    {
        [$firstTournament, $firstMatchId] = $this->createMatchReportFixture('North');
        [$secondTournament, $secondMatchId] = $this->createMatchReportFixture('South');
        $secondCategory = (new CategoryRepository())->create([
            'name' => 'Youth',
            'description' => 'Youth category',
        ]);
        (new TournamentRepository())->update((int) $secondTournament['id'], [
            'category_id' => (int) $secondCategory['id'],
        ]);

        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '1',
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        $routerFactory = require dirname(__DIR__, 2) . '/src/Routes/api.php';
        $router = $routerFactory();
        $schedule = static function (int $matchId, string $date, string $time) use ($headers, $router): array {
            ob_start();
            $router->dispatch(new Request(
                'POST',
                '/api/v1/matches/' . $matchId . '/schedule',
                [],
                $headers,
                ['match_date' => $date, 'kickoff_time' => $time],
            ));
            return json_decode((string) ob_get_clean(), true);
        };

        $before = Database::getConnection()->prepare(
            'SELECT home_tournament_team_id, away_tournament_team_id, home_score, away_score
             FROM matches WHERE id = :id'
        );
        $before->execute([':id' => $firstMatchId]);
        $beforeState = $before->fetch();

        $firstScheduled = $schedule($firstMatchId, '2026-11-05', '10:30');
        $this->assertTrue($firstScheduled['success']);
        $this->assertSame('10:30:00', $firstScheduled['data']['kickoff_time']);
        $this->assertSame(200, http_response_code());

        $sameStart = $schedule($secondMatchId, '2026-11-05', '10:30');
        $this->assertFalse($sameStart['success']);
        $this->assertSame(409, http_response_code());
        $this->assertSame(
            'Ese horario ya no está disponible. Deben transcurrir al menos 50 minutos entre el inicio de cada partido.',
            $sameStart['message'],
        );

        $tooSoon = $schedule($secondMatchId, '2026-11-05', '11:19');
        $this->assertFalse($tooSoon['success']);
        $this->assertSame(409, http_response_code());

        $exactlyFiftyMinutes = $schedule($secondMatchId, '2026-11-05', '11:20');
        $this->assertTrue($exactlyFiftyMinutes['success']);
        $this->assertSame(200, http_response_code());

        ob_start();
        $router->dispatch(new Request(
            'PUT',
            '/api/v1/matches/' . $firstMatchId . '/schedule',
            [],
            $headers,
            ['match_date' => '2026-11-05', 'kickoff_time' => '10:15'],
        ));
        $wrongMethodResponse = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($wrongMethodResponse['success']);
        $this->assertSame(405, http_response_code());

        $rescheduled = $schedule($firstMatchId, '2026-11-05', '10:00');
        $this->assertTrue($rescheduled['success']);
        $this->assertSame('10:00:00', $rescheduled['data']['kickoff_time']);

        $pdo = Database::getConnection();
        $this->assertTrue($schedule($secondMatchId, '2026-11-05', '09:00')['success']);
        $after = $pdo->prepare(
            'SELECT home_tournament_team_id, away_tournament_team_id, home_score, away_score,
                    match_date, kickoff_time
             FROM matches WHERE id = :id'
        );
        $after->execute([':id' => $firstMatchId]);
        $afterState = $after->fetch();
        $this->assertSame($beforeState['home_tournament_team_id'], $afterState['home_tournament_team_id']);
        $this->assertSame($beforeState['away_tournament_team_id'], $afterState['away_tournament_team_id']);
        $this->assertSame($beforeState['home_score'], $afterState['home_score']);
        $this->assertSame($beforeState['away_score'], $afterState['away_score']);
        $this->assertSame('2026-11-05', $afterState['match_date']);

        ob_start();
        $router->dispatch(new Request(
            'GET',
            '/api/v1/matches/by-date',
            ['date' => '2026-11-05'],
            $headers,
            [],
        ));
        $dayResponse = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($dayResponse['success']);
        $this->assertCount(2, $dayResponse['data']);
        $this->assertSame($secondMatchId, (int) $dayResponse['data'][0]['id']);
        $this->assertSame('Youth', $dayResponse['data'][0]['category_name']);
        $this->assertSame($secondTournament['name'], $dayResponse['data'][0]['tournament_name']);
        $this->assertSame($firstMatchId, (int) $dayResponse['data'][1]['id']);
        $this->assertSame('Senior', $dayResponse['data'][1]['category_name']);
        $this->assertSame($firstTournament['name'], $dayResponse['data'][1]['tournament_name']);
        $this->assertArrayHasKey('stage_name', $dayResponse['data'][0]);
        $this->assertArrayHasKey('round_name', $dayResponse['data'][0]);

        ob_start();
        $router->dispatch(new Request(
            'GET',
            '/api/v1/matches/by-date',
            ['date' => '2026-11-06'],
            $headers,
            [],
        ));
        $emptyResponse = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($emptyResponse['success']);
        $this->assertSame([], $emptyResponse['data']);

        $missing = $schedule(999999, '2026-11-05', '12:00');
        $this->assertFalse($missing['success']);
        $this->assertSame(404, http_response_code());

        $invalid = $schedule($firstMatchId, '2026-02-30', '25:00');
        $this->assertFalse($invalid['success']);
        $this->assertSame(422, http_response_code());

        (new MatchResultService())->recordResult($firstMatchId, 2, 1, null, 'Test completed result');
        $completedReschedule = $schedule($firstMatchId, '2026-11-07', '11:00');
        $this->assertFalse($completedReschedule['success']);
        $this->assertSame(409, http_response_code());
        $this->assertSame('2026-11-05', $pdo->query(
            'SELECT match_date FROM matches WHERE id = ' . $firstMatchId
        )->fetchColumn());
    }

    public function testPublicTopScorersCountOnlyCompletedIndividualGoalsAndShareTiedPositions(): void
    {
        [$tournament, $matchId, $homePlayerRegistrationId, $awayPlayerRegistrationId] = $this->createMatchReportFixture('Public scorers');
        $pdo = Database::getConnection();
        $this->insertPublicGoal($pdo, $matchId, $homePlayerRegistrationId, (int) $tournament['home_tournament_team_id'], 'REGULAR');
        $this->insertPublicGoal($pdo, $matchId, $homePlayerRegistrationId, (int) $tournament['home_tournament_team_id'], 'PENALTY');
        $this->insertPublicGoal($pdo, $matchId, $homePlayerRegistrationId, (int) $tournament['home_tournament_team_id'], 'REGULAR');
        $this->insertPublicGoal($pdo, $matchId, $awayPlayerRegistrationId, (int) $tournament['away_tournament_team_id'], 'REGULAR');
        $this->insertPublicGoal($pdo, $matchId, $awayPlayerRegistrationId, (int) $tournament['away_tournament_team_id'], 'PENALTY');
        $this->insertPublicGoal($pdo, $matchId, $awayPlayerRegistrationId, (int) $tournament['away_tournament_team_id'], 'REGULAR');
        $this->insertPublicGoal($pdo, $matchId, $awayPlayerRegistrationId, (int) $tournament['home_tournament_team_id'], 'OWN_GOAL');

        $matchQuery = $pdo->prepare('SELECT round_id FROM matches WHERE id = :id');
        $matchQuery->execute([':id' => $matchId]);
        $roundId = (int) $matchQuery->fetchColumn();
        $pendingMatch = $pdo->prepare(
            'INSERT INTO matches (round_id, home_tournament_team_id, away_tournament_team_id, status, is_bye)
             VALUES (:round_id, :home_team_id, :away_team_id, :status, 0)'
        );
        $pendingMatch->execute([
            ':round_id' => $roundId,
            ':home_team_id' => $tournament['home_tournament_team_id'],
            ':away_team_id' => $tournament['away_tournament_team_id'],
            ':status' => 'SCHEDULED',
        ]);
        $this->insertPublicGoal($pdo, (int) $pdo->lastInsertId(), $homePlayerRegistrationId, (int) $tournament['home_tournament_team_id'], 'REGULAR');

        $pdo->prepare('UPDATE matches SET status = :status WHERE id = :id')->execute([':status' => 'COMPLETED', ':id' => $matchId]);
        $playerIdQuery = $pdo->prepare('SELECT player_id FROM tournament_players WHERE id = :id');
        $playerIdQuery->execute([':id' => $homePlayerRegistrationId]);
        $homePlayerId = (int) $playerIdQuery->fetchColumn();
        $playerIdQuery->execute([':id' => $awayPlayerRegistrationId]);
        $awayPlayerId = (int) $playerIdQuery->fetchColumn();

        $routerFactory = require dirname(__DIR__, 2) . '/src/Routes/api.php';
        $router = $routerFactory();
        $response = $this->captureJsonResponse(
            fn () => $router->dispatch(
                new Request('GET', '/api/v1/public/tournaments/' . $tournament['id'] . '/top-scorers', ['limit' => '10'], [], [])
            )
        );
        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertSame([
            ['position' => 1, 'player_id' => $awayPlayerId, 'player_name' => 'Away Player', 'tournament_team_id' => (int) $tournament['away_tournament_team_id'], 'team_name' => 'Public scorers away', 'total_goals' => 3],
            ['position' => 1, 'player_id' => $homePlayerId, 'player_name' => 'Home Player', 'tournament_team_id' => (int) $tournament['home_tournament_team_id'], 'team_name' => 'Public scorers home', 'total_goals' => 3],
        ], $response['body']['data']);
    }

    public function testPublicTopScorersReturnEmptyForTournamentWithoutGoalsAndValidateLimitAndTournament(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'No public goals',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
        ]);
        $controller = new PublicTournamentController();

        $empty = $this->captureJsonResponse(
            fn () => $controller->topScorers(
                new Request('GET', '/api/v1/public/tournaments/' . $tournament['id'] . '/top-scorers', [], [], []),
                ['id' => (string) $tournament['id']]
            )
        );
        $this->assertSame(200, $empty['status']);
        $this->assertSame([], $empty['body']['data']);

        $badLimit = $this->captureJsonResponse(
            fn () => $controller->topScorers(
                new Request('GET', '/api/v1/public/tournaments/' . $tournament['id'] . '/top-scorers', ['limit' => '101'], [], []),
                ['id' => (string) $tournament['id']]
            )
        );
        $this->assertSame(422, $badLimit['status']);

        $invalidTournamentId = $this->captureJsonResponse(
            fn () => $controller->topScorers(
                new Request('GET', '/api/v1/public/tournaments/invalid/top-scorers', [], [], []),
                ['id' => 'invalid']
            )
        );
        $this->assertSame(422, $invalidTournamentId['status']);

        $missingTournament = $this->captureJsonResponse(
            fn () => $controller->topScorers(
                new Request('GET', '/api/v1/public/tournaments/99999/top-scorers', [], [], []),
                ['id' => '99999']
            )
        );
        $this->assertSame(404, $missingTournament['status']);
    }

    public function testPublicTournamentListReturnsOnlyActiveTournaments(): void
    {
        $repository = new TournamentRepository();
        $draft = $repository->create([
            'name' => 'Draft tournament',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'DRAFT',
        ]);
        $registration = $repository->create([
            'name' => 'Registration tournament',
            'category_id' => 1,
            'format' => 'FLASH',
            'status' => 'REGISTRATION',
        ]);
        $inProgress = $repository->create([
            'name' => 'In progress tournament',
            'category_id' => 1,
            'format' => 'CUP',
            'status' => 'IN_PROGRESS',
        ]);
        $completed = $repository->create([
            'name' => 'Completed tournament',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'COMPLETED',
        ]);
        $cancelled = $repository->create([
            'name' => 'Cancelled tournament',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'CANCELLED',
        ]);

        $controller = new PublicTournamentController();
        $response = $this->captureJsonResponse(
            fn () => $controller->list(
                new Request('GET', '/api/v1/public/tournaments', ['page' => '1', 'limit' => '10'], [], []),
                []
            )
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $ids = array_map(static fn (array $tournament): int => (int) $tournament['id'], $response['body']['data']['items']);
        $this->assertContains((int) $registration['id'], $ids);
        $this->assertContains((int) $inProgress['id'], $ids);
        $this->assertNotContains((int) $draft['id'], $ids);
        $this->assertNotContains((int) $completed['id'], $ids);
        $this->assertNotContains((int) $cancelled['id'], $ids);
    }

    public function testPublicFirstMatchOfDayReturnsEarliestMatchInActiveTournaments(): void
    {
        [, $lateMatchId] = $this->createMatchReportFixture('Later match');
        [, $firstMatchId] = $this->createMatchReportFixture('First match');
        [, $cancelledMatchId] = $this->createMatchReportFixture('Cancelled match');

        $pdo = Database::getConnection();
        $updateMatch = $pdo->prepare(
            'UPDATE matches
             SET match_date = :match_date, kickoff_time = :kickoff_time,
                 status = :status, home_score = :home_score, away_score = :away_score
             WHERE id = :id'
        );
        $updateMatch->execute([
            ':match_date' => '2026-10-13',
            ':kickoff_time' => '18:00:00',
            ':status' => 'SCHEDULED',
            ':home_score' => null,
            ':away_score' => null,
            ':id' => $lateMatchId,
        ]);
        $updateMatch->execute([
            ':match_date' => '2026-10-13',
            ':kickoff_time' => '15:30:00',
            ':status' => 'COMPLETED',
            ':home_score' => 2,
            ':away_score' => 1,
            ':id' => $firstMatchId,
        ]);
        $updateMatch->execute([
            ':match_date' => '2026-10-13',
            ':kickoff_time' => '09:00:00',
            ':status' => 'CANCELLED',
            ':home_score' => null,
            ':away_score' => null,
            ':id' => $cancelledMatchId,
        ]);

        $response = $this->captureJsonResponse(
            fn () => (new PublicTournamentController())->firstMatchOfDay(
                new Request('GET', '/api/v1/public/matches/first', ['date' => '2026-10-13'], [], []),
                []
            )
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertSame($firstMatchId, (int) $response['body']['data']['id']);
        $this->assertSame('15:30:00', $response['body']['data']['kickoff_time']);
        $this->assertSame('COMPLETED', $response['body']['data']['status']);
    }

    public function testPublicFirstMatchOfDayReturnsNullAndValidatesDate(): void
    {
        $controller = new PublicTournamentController();
        $noMatchResponse = $this->captureJsonResponse(
            fn () => $controller->firstMatchOfDay(
                new Request('GET', '/api/v1/public/matches/first', ['date' => '2026-10-13'], [], []),
                []
            )
        );
        $this->assertSame(200, $noMatchResponse['status']);
        $this->assertNull($noMatchResponse['body']['data']);

        $invalidDateResponse = $this->captureJsonResponse(
            fn () => $controller->firstMatchOfDay(
                new Request('GET', '/api/v1/public/matches/first', ['date' => '2026-02-30'], [], []),
                []
            )
        );
        $this->assertSame(422, $invalidDateResponse['status']);
        $this->assertFalse($invalidDateResponse['body']['success']);
        $this->assertArrayHasKey('date', $invalidDateResponse['body']['errors']);
    }

    public function testPublicTournamentGalleryListsOnlyAllowedImagesAndRejectsTraversal(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Public image gallery',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
        ]);
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'football-gallery-' . bin2hex(random_bytes(8));
        $directory = $root . DIRECTORY_SEPARATOR . $tournament['id'];
        $missingRootService = new PublicTournamentService(
            new PublicTournamentRepository(Database::getConnection()),
            $root . '-missing'
        );
        $this->assertSame([], $missingRootService->getImages((int) $tournament['id'], '/public/images'));
        mkdir($directory, 0777, true);

        try {
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'b.PNG', 'png');
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'a.jpg', 'jpg');
            file_put_contents($directory . DIRECTORY_SEPARATOR . '.hidden.jpg', 'hidden');
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'notes.txt', 'not an image');
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'pending.jpg.tmp', 'temporary');

            $service = new PublicTournamentService(
                new PublicTournamentRepository(Database::getConnection()),
                $root
            );
            $controller = new PublicTournamentController($service);
            $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
            $_SERVER['REQUEST_URI'] = '/v2/backend/public/api/v1/public/tournaments/' . $tournament['id'] . '/images';
            $galleryResponse = $this->captureJsonResponse(
                fn () => $controller->images(
                    new Request('GET', '/api/v1/public/tournaments/' . $tournament['id'] . '/images', [], [], []),
                    ['id' => (string) $tournament['id']]
                )
            );
            if ($previousRequestUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousRequestUri;
            }

            $images = $galleryResponse['body']['data'];

            $this->assertSame(200, $galleryResponse['status']);
            $this->assertSame(['a.jpg', 'b.PNG'], array_column($images, 'filename'));
            $this->assertSame(
                '/v2/backend/public/api/v1/public/tournaments/' . $tournament['id'] . '/images/b.PNG',
                $images[1]['url']
            );
            $this->assertSame('image/png', $service->getImage((int) $tournament['id'], 'b.PNG')['mime_type']);
            ob_start();
            $controller->image(
                new Request('GET', '/api/v1/public/tournaments/' . $tournament['id'] . '/images/b.PNG', [], [], []),
                ['id' => (string) $tournament['id'], 'filename' => 'b.PNG']
            );
            $this->assertSame('png', ob_get_clean());
            $this->assertNull($service->getImage((int) $tournament['id'], '../b.PNG'));
            $this->assertNull($service->getImage((int) $tournament['id'], 'notes.txt'));
            try {
                $service->getImages(99999, '/public/images');
                $this->fail('An unknown tournament must not expose a gallery.');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
            unlink($directory . DIRECTORY_SEPARATOR . 'a.jpg');
            unlink($directory . DIRECTORY_SEPARATOR . 'b.PNG');
            unlink($directory . DIRECTORY_SEPARATOR . '.hidden.jpg');
            unlink($directory . DIRECTORY_SEPARATOR . 'notes.txt');
            unlink($directory . DIRECTORY_SEPARATOR . 'pending.jpg.tmp');
            rmdir($directory);
            $this->assertSame([], $service->getImages((int) $tournament['id'], '/public/images'));
            mkdir($directory);
            $this->assertSame([], $service->getImages((int) $tournament['id'], '/public/images'));
        } finally {
            foreach (['a.jpg', 'b.PNG', '.hidden.jpg', 'notes.txt', 'pending.jpg.tmp'] as $filename) {
                $file = $directory . DIRECTORY_SEPARATOR . $filename;
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testPublicFacilityGalleryListsAndServesOnlyAllowedImages(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'football-facilities-' . bin2hex(random_bytes(8));
        mkdir($root);

        $files = [
            '10.JPG' => 'ten',
            '2.jpg' => 'two',
            'WhatsApp Image.jpeg' => 'space',
            '.hidden.png' => 'hidden',
            'notes.txt' => 'not an image',
            'temporary.jpg.tmp' => 'temporary',
        ];
        foreach ($files as $filename => $contents) {
            file_put_contents($root . DIRECTORY_SEPARATOR . $filename, $contents);
        }

        $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        try {
            $service = new FacilityService($root);
            $prefix = '/v2/backend/public/api/v1/public/facilities/images';
            $images = $service->getImages($prefix);

            $this->assertSame(['2.jpg', '10.JPG', 'WhatsApp Image.jpeg'], array_column($images, 'filename'));
            $this->assertSame($prefix . '/WhatsApp%20Image.jpeg', $images[2]['url']);
            $this->assertSame('image/jpeg', $service->getImage('10.JPG')['mime_type']);
            $this->assertNull($service->getImage('../10.JPG'));
            $this->assertNull($service->getImage('notes.txt'));

            $controller = new FacilityController($service);
            $_SERVER['REQUEST_URI'] = $prefix;
            $listResponse = $this->captureJsonResponse(
                fn () => $controller->images(
                    new Request('GET', '/api/v1/public/facilities/images', [], [], []),
                    []
                )
            );
            $this->assertSame(200, $listResponse['status']);
            $this->assertTrue($listResponse['body']['success']);
            $this->assertSame($images, $listResponse['body']['data']);

            ob_start();
            $controller->image(
                new Request('GET', '/api/v1/public/facilities/images/WhatsApp%20Image.jpeg', [], [], []),
                ['filename' => 'WhatsApp%20Image.jpeg']
            );
            $this->assertSame('space', ob_get_clean());

            $notFoundResponse = $this->captureJsonResponse(
                fn () => $controller->image(
                    new Request('GET', '/api/v1/public/facilities/images/notes.txt', [], [], []),
                    ['filename' => 'notes.txt']
                )
            );
            $this->assertSame(404, $notFoundResponse['status']);
            $this->assertFalse($notFoundResponse['body']['success']);
        } finally {
            if ($previousRequestUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousRequestUri;
            }
            foreach (array_keys($files) as $filename) {
                $path = $root . DIRECTORY_SEPARATOR . $filename;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($root);
        }
    }

    public function testRequestPathNormalizationDoesNotTrimARequestedFilePath(): void
    {
        $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        try {
            $_SERVER['SCRIPT_NAME'] = '/api/v1/public/facilities/images/2.jpeg';

            $this->assertSame(
                '/api/v1/public/facilities/images/2.jpeg',
                Request::normalizePath('/api/v1/public/facilities/images/2.jpeg')
            );
        } finally {
            if ($previousScriptName === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $previousScriptName;
            }
        }
    }

    public function testPublicTournamentImageSupportsFlatIdJpgFile(): void
    {
        $tournament = (new TournamentRepository())->create([
            'name' => 'Public flat image',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
        ]);
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'football-flat-gallery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $filename = $tournament['id'] . '.jpg';
        file_put_contents($root . DIRECTORY_SEPARATOR . $filename, 'jpg-data');

        try {
            $service = new PublicTournamentService(
                new PublicTournamentRepository(Database::getConnection()),
                $root
            );
            $image = $service->getImage((int) $tournament['id'], $filename);

            $this->assertNotNull($image);
            $this->assertSame('image/jpeg', $image['mime_type']);
            $this->assertSame('jpg-data', file_get_contents($image['path']));
        } finally {
            unlink($root . DIRECTORY_SEPARATOR . $filename);
            rmdir($root);
        }
    }

    public function testNewAdministrativeEndpointsRequireAdminRole(): void
    {
        try {
            (new \App\Controllers\ScheduleController())->matchesByDate(
                new Request('GET', '/api/v1/matches/by-date', ['date' => '2026-11-05'], [], []),
                [],
            );
            self::fail('Requests without a token must not access the global match day feed.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->statusCode);
        }

        try {
            (new MatchReportController())->listReasons(new Request('GET', '/api/v1/expulsion-reasons', [], [], []), []);
            self::fail('Requests without a token must not access administrative data.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->statusCode);
        }

        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '2',
            'role' => 'operator',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        try {
            (new MatchReportController())->listReasons(new Request('GET', '/api/v1/expulsion-reasons', [], $headers, []), []);
            self::fail('Non-admin accounts must not access administrative data.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->statusCode);
        }
    }

    public function testFormerPutAndPatchEndpointsAreHandledThroughPostRoutes(): void
    {
        [$tournament, $matchId] = $this->createMatchReportFixture('POST update methods');
        $reasonService = new \App\Services\MatchReportService();
        $reason = $reasonService->createExpulsionReason([
            'name' => 'Reason before POST update',
            'description' => null,
            'suspension_matches' => 1,
            'is_active' => true,
        ]);
        $headers = ['Authorization' => 'Bearer ' . signToken([
            'sub' => '1',
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + 60,
            'iss' => 'football-tournament-api',
        ])];
        $router = (require dirname(__DIR__, 2) . '/src/Routes/api.php')();
        $dispatch = static function (string $method, string $path, array $body) use ($router, $headers): array {
            ob_start();
            $router->dispatch(new Request($method, $path, [], $headers, $body));
            $response = json_decode((string) ob_get_clean(), true);
            return [$response, http_response_code()];
        };

        [$statusResponse, $statusCode] = $dispatch(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/status',
            ['status' => 'IN_PROGRESS'],
        );
        $this->assertTrue($statusResponse['success']);
        $this->assertSame(200, $statusCode);
        $this->assertSame('IN_PROGRESS', $statusResponse['data']['status']);

        [$registrationResponse, $registrationStatus] = $dispatch(
            'POST',
            '/api/v1/tournaments/' . $tournament['id'] . '/player-registration',
            ['allow_player_registration' => false],
        );
        $this->assertTrue($registrationResponse['success']);
        $this->assertSame(200, $registrationStatus);
        $this->assertSame(0, (int) $registrationResponse['data']['allow_player_registration']);

        [$reasonResponse, $reasonStatus] = $dispatch(
            'POST',
            '/api/v1/expulsion-reasons/' . $reason['id'],
            ['description' => 'Updated through POST'],
        );
        $this->assertTrue($reasonResponse['success']);
        $this->assertSame(200, $reasonStatus);
        $this->assertSame('Updated through POST', $reasonResponse['data']['description']);

        [, $legacyReportStatus] = $dispatch(
            'PUT',
            '/api/v1/matches/' . $matchId . '/report',
            [],
        );
        $this->assertSame(405, $legacyReportStatus);

        [, $legacyReasonStatus] = $dispatch(
            'PATCH',
            '/api/v1/expulsion-reasons/' . $reason['id'],
            ['description' => 'Should not be accepted'],
        );
        $this->assertSame(405, $legacyReasonStatus);
    }

    private function createMatchReportFixture(string $prefix = 'Match report'): array
    {
        $repository = new TournamentRepository();
        $tournament = $repository->create([
            'name' => $prefix . ' tournament',
            'category_id' => 1,
            'format' => 'LEAGUE',
            'status' => 'REGISTRATION',
            'rounds_count' => 1,
        ]);
        $homeTeam = (new TeamRepository())->create(['name' => $prefix . ' home']);
        $awayTeam = (new TeamRepository())->create(['name' => $prefix . ' away']);
        $repository->addTeamToTournament((int) $tournament['id'], (int) $homeTeam['id']);
        $repository->addTeamToTournament((int) $tournament['id'], (int) $awayTeam['id']);

        $homePlayer = (new PlayerRepository())->create(['first_name' => 'Home', 'last_name' => 'Player']);
        $awayPlayer = (new PlayerRepository())->create(['first_name' => 'Away', 'last_name' => 'Player']);
        $repository->addPlayerToTournament((int) $tournament['id'], (int) $homeTeam['id'], (int) $homePlayer['id']);
        $repository->addPlayerToTournament((int) $tournament['id'], (int) $awayTeam['id'], (int) $awayPlayer['id']);
        (new ScheduleGeneratorService())->generateLeagueSchedule((int) $tournament['id']);

        $pdo = Database::getConnection();
        $matchQuery = $pdo->prepare(
            'SELECT m.id, m.home_tournament_team_id, m.away_tournament_team_id,
                    home_registration.team_id AS home_team_id,
                    away_registration.team_id AS away_team_id
             FROM matches m
             JOIN rounds r ON r.id = m.round_id
             JOIN tournament_stages s ON s.id = r.stage_id
             JOIN tournament_teams home_registration ON home_registration.id = m.home_tournament_team_id
             JOIN tournament_teams away_registration ON away_registration.id = m.away_tournament_team_id
             WHERE m.is_bye = 0 AND s.tournament_id = :tournament_id
             ORDER BY m.id LIMIT 1'
        );
        $matchQuery->execute([':tournament_id' => (int) $tournament['id']]);
        $match = $matchQuery->fetch();
        $players = $pdo->prepare('SELECT tp.id, tt.team_id FROM tournament_players tp JOIN tournament_teams tt ON tt.id = tp.tournament_team_id WHERE tp.tournament_id = :tournament_id ORDER BY tt.team_id');
        $players->execute([':tournament_id' => $tournament['id']]);
        $registrations = $players->fetchAll();
        $registrationByTeam = [];
        foreach ($registrations as $registration) {
            $registrationByTeam[(int) $registration['team_id']] = (int) $registration['id'];
        }

        return [
            array_merge($tournament, [
                'home_team_id' => (int) $match['home_team_id'],
                'away_team_id' => (int) $match['away_team_id'],
                'home_tournament_team_id' => (int) $match['home_tournament_team_id'],
                'away_tournament_team_id' => (int) $match['away_tournament_team_id'],
            ]),
            (int) $match['id'],
            $registrationByTeam[(int) $homeTeam['id']],
            $registrationByTeam[(int) $awayTeam['id']],
        ];
    }

    private function insertPublicGoal(PDO $pdo, int $matchId, int $tournamentPlayerId, int $creditedTeamId, string $goalType): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO match_goals (match_id, tournament_player_id, credited_to_team_id, goal_type, client_key)
             VALUES (:match_id, :player_id, :team_id, :goal_type, :client_key)'
        );
        $statement->execute([
            ':match_id' => $matchId,
            ':player_id' => $tournamentPlayerId,
            ':team_id' => $creditedTeamId,
            ':goal_type' => $goalType,
            ':client_key' => sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff)),
        ]);
    }

    private function captureJsonResponse(callable $callback): array
    {
        ob_start();
        $callback();
        $body = (string) ob_get_clean();

        return ['status' => http_response_code(), 'body' => json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
}
