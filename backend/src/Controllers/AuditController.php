<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AdminAuditRepository;

final class AuditController
{
    public function __construct(private readonly AdminAuditRepository $repository = new AdminAuditRepository())
    {
    }

    public function list(Request $request, array $params): void
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($request->query['limit'] ?? 50, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $limit === false || $limit < 1 || $limit > 100) {
            Response::json(422, false, null, 'La paginación no es válida.', [
                'pagination' => ['page debe ser mayor que cero y limit debe estar entre 1 y 100.'],
            ]);
            return;
        }

        Response::json(200, true, $this->repository->paginate($page, $limit), 'Historial administrativo consultado.');
    }
}
