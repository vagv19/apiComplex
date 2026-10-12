<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\TournamentFormatService;

final class TournamentFormatController
{
    public function __construct(private readonly TournamentFormatService $service = new TournamentFormatService())
    {
    }

    public function groups(Request $request, array $params): void
    {
        try {
            Response::json(200, true, $this->service->listGroups((int) $params['id']), 'Grupos del torneo.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function createGroups(Request $request, array $params): void
    {
        $groupCount = filter_var($request->body['group_count'] ?? null, FILTER_VALIDATE_INT);
        if ($groupCount === false) {
            Response::json(422, false, null, 'group_count debe ser un número entero.');
            return;
        }
        try {
            Response::json(201, true, $this->service->createFlashGroups((int) $params['id'], $groupCount), 'Grupos creados y equipos asignados.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function cancelGroups(Request $request, array $params): void
    {
        try {
            $result = $this->service->cancelFlashGroups((int) $params['id']);
            $message = $result['groups_deleted'] > 0
                ? 'Grupos cancelados y equipos liberados.'
                : 'No había grupos que cancelar.';
            Response::json(200, true, $result, $message);
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function generateGroupSchedule(Request $request, array $params): void
    {
        try {
            Response::json(201, true, $this->service->generateFlashGroupSchedule((int) $params['id']), 'Calendario de grupos generado.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function generateCupBracket(Request $request, array $params): void
    {
        try {
            Response::json(201, true, $this->service->generateCupBracket((int) $params['id']), 'Cuadro de Copa generado.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function qualifyFlash(Request $request, array $params): void
    {
        $qualifiersPerGroup = filter_var($request->body['qualifiers_per_group'] ?? 2, FILTER_VALIDATE_INT);
        $totalQualifiers = filter_var($request->body['total_qualifiers'] ?? null, FILTER_VALIDATE_INT);
        if ($qualifiersPerGroup === false || $qualifiersPerGroup < 1 || $qualifiersPerGroup > 255) {
            Response::json(422, false, null, 'qualifiers_per_group debe ser un entero entre 1 y 255.');
            return;
        }
        if ($totalQualifiers === false || $totalQualifiers < 2 || $totalQualifiers > 255) {
            Response::json(422, false, null, 'total_qualifiers debe ser un entero entre 2 y 255.');
            return;
        }
        try {
            Response::json(201, true, $this->service->qualifyFlash((int) $params['id'], $qualifiersPerGroup, $totalQualifiers), 'Clasificación calculada y cuadro final generado.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function startLeagueKnockout(Request $request, array $params): void
    {
        try {
            $result = $this->service->startLeagueKnockout((int) $params['id']);
            Response::json(201, true, $result, 'Liguilla iniciada y cuadro generado.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function advanceKnockout(Request $request, array $params): void
    {
        try {
            $result = $this->service->advanceKnockout((int) $params['id']);
            $message = $result['completed']
                ? 'Torneo finalizado. Campeón: ' . $result['champion']['team_name'] . '.'
                : 'Ganadores avanzaron a la siguiente ronda.';
            Response::json($result['completed'] ? 200 : 201, true, $result, $message);
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    private function domainError(\DomainException $exception): void
    {
        $notFound = str_contains(strtolower($exception->getMessage()), 'no encontrado');
        Response::json($notFound ? 404 : 409, false, null, $exception->getMessage());
    }
}
