<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\LogicalTournamentService;

final class LogicalTournamentController
{
    public function __construct(private readonly LogicalTournamentService $service = new LogicalTournamentService())
    {
    }

    public function list(Request $request, array $params): void
    {
        [$page, $limit] = $this->pagination($request->query);
        Response::json(200, true, $this->service->list($page, $limit), 'Listado de torneos lógicos.');
    }

    public function get(Request $request, array $params): void
    {
        Response::json(200, true, $this->service->get((string) $params['logical_id']), 'Torneo lógico consultado.');
    }

    public function availableCategories(Request $request, array $params): void
    {
        [$page, $limit] = $this->pagination($request->query);
        $logicalId = $request->query['logical_id'] ?? null;
        if ($logicalId !== null && !is_string($logicalId)) {
            throw new HttpException(422, 'logical_id debe ser texto.');
        }
        Response::json(
            200,
            true,
            $this->service->availableCategories($page, $limit, $logicalId),
            'Categorías disponibles consultadas.',
        );
    }

    public function create(Request $request, array $params): void
    {
        $entity = $this->service->create($request->body, $this->actor($request));
        Response::json(201, true, $entity, 'Torneo lógico creado.');
    }

    public function updateDetails(Request $request, array $params): void
    {
        $entity = $this->service->updateDetails(
            (string) $params['logical_id'],
            $request->body,
            $this->actor($request),
        );
        Response::json(200, true, $entity, 'Datos generales actualizados.');
    }

    public function updateRules(Request $request, array $params): void
    {
        $entity = $this->service->updateRules(
            (string) $params['logical_id'],
            $request->body,
            $this->actor($request),
        );
        Response::json(200, true, $entity, 'Reglas actualizadas en todas las categorías.');
    }

    public function addCategory(Request $request, array $params): void
    {
        $entity = $this->service->addCategory(
            (string) $params['logical_id'],
            $request->body,
            $this->actor($request),
        );
        Response::json(201, true, $entity, 'Categoría agregada al torneo lógico.');
    }

    public function removeCategory(Request $request, array $params): void
    {
        $entity = $this->service->removeCategory(
            (string) $params['logical_id'],
            $request->body,
            $this->actor($request),
        );
        Response::json(200, true, $entity, 'Categoría retirada sin eliminar sus registros.');
    }

    public function retire(Request $request, array $params): void
    {
        $entity = $this->service->retire(
            (string) $params['logical_id'],
            $request->body,
            $this->actor($request),
        );
        Response::json(200, true, $entity, 'Torneo retirado sin eliminar sus registros.');
    }

    private function actor(Request $request): array
    {
        $user = AuthMiddleware::requireAuth($request->headers);
        $ipAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP);

        return [
            'actor_user_id' => (int) $user['sub'],
            'actor_role' => (string) $user['role'],
            'ip_address' => $ipAddress === false ? null : $ipAddress,
        ];
    }

    private function pagination(array $query): array
    {
        $page = $this->queryInteger($query['page'] ?? 1, 'page');
        $limit = $this->queryInteger($query['limit'] ?? 15, 'limit');
        if (
            $page < 1
            || $limit < 1
            || $limit > 100
            || $page - 1 > intdiv(PHP_INT_MAX, max($limit, 1))
        ) {
            throw new HttpException(422, 'page debe ser positivo y limit debe estar entre 1 y 100.');
        }

        return [$page, $limit];
    }

    private function queryInteger(mixed $value, string $field): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new HttpException(422, $field . ' debe ser un entero.');
        }

        return (int) $value;
    }
}
