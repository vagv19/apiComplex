<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Middleware\RolePermissions;
use App\Repositories\LogicalTournamentRepository;
use App\Services\LogicalTournamentService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Core/HttpException.php';
require_once dirname(__DIR__, 2) . '/src/Middleware/RolePermissions.php';
require_once dirname(__DIR__, 2) . '/src/Repositories/LogicalTournamentRepository.php';
require_once dirname(__DIR__, 2) . '/src/Services/LogicalTournamentService.php';

final class LogicalTournamentServiceTest extends TestCase
{
    public function testCreateRejectsMissingCategoriesBeforePersistence(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(422);

        (new LogicalTournamentService(new LogicalTournamentRepository()))->create([
            'name' => 'Torneo de prueba',
            'category_ids' => [],
        ], []);
    }

    public function testCreateRejectsDuplicateCategoriesBeforePersistence(): void
    {
        try {
            (new LogicalTournamentService(new LogicalTournamentRepository()))->create([
                'name' => 'Torneo de prueba',
                'category_ids' => [1, 1],
            ], []);
            self::fail('Duplicate category IDs must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertArrayHasKey('category_ids', $exception->errors);
        }
    }

    public function testCreateRejectsReversedDatesBeforePersistence(): void
    {
        try {
            (new LogicalTournamentService(new LogicalTournamentRepository()))->create([
                'name' => 'Torneo de prueba',
                'category_ids' => [1],
                'start_date' => '2026-12-15',
                'end_date' => '2026-10-20',
            ], []);
            self::fail('A closing date before the start date must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertArrayHasKey('end_date', $exception->errors);
        }
    }

    public function testLogicalIdsMustUseAnExplicitTypePrefix(): void
    {
        try {
            (new LogicalTournamentService(new LogicalTournamentRepository()))->get('12');
            self::fail('Numeric IDs must not be accepted by the logical API.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->statusCode);
        }
    }

    public function testCreateRejectsOutOfRangeRuleValuesBeforePersistence(): void
    {
        try {
            (new LogicalTournamentService(new LogicalTournamentRepository()))->create([
                'name' => 'Torneo de prueba',
                'category_ids' => [1],
                'rules' => ['points_for_win' => 256],
            ], []);
            self::fail('Rule values outside the database range must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertArrayHasKey('points_for_win', $exception->errors);
        }
    }

    public function testLogicalTournamentManagementIsNotAvailableToDirectors(): void
    {
        self::assertTrue(RolePermissions::hasCapability(['role' => 'admin'], 'tournaments.manage'));
        self::assertTrue(RolePermissions::hasCapability(['role' => 'superadmin'], 'tournaments.manage'));
        self::assertFalse(RolePermissions::hasCapability(['role' => 'director_tecnico'], 'tournaments.manage'));
    }
}
