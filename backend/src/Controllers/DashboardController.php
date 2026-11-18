<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthorizationService;
use App\Middleware\ModuleAccessService;
use App\Repositories\DashboardRepository;

final class DashboardController
{
    public function __construct(
        private readonly DashboardRepository $repository = new DashboardRepository(),
    ) {
    }

    public function overview(Request $request, array $params): void
    {
        $user = AuthorizationService::requireCapability($request->headers, 'dashboard.read');
        $data = $this->repository->overview();

        foreach ([
            'tournaments' => 'tournaments',
            'categories' => 'categories',
            'teams' => 'teams',
            'players' => 'players',
        ] as $count => $module) {
            if (!ModuleAccessService::canAccess($user, $module)) {
                $data['counts'][$count] = null;
            }
        }
        if (!ModuleAccessService::canAccess($user, 'tournaments')) {
            $data['recent_tournaments'] = null;
        }

        Response::json(200, true, $data, 'Resumen consultado.');
    }
}
