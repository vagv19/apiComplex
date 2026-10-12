<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CategoryRepository;

class CategoryController
{
    public function __construct(private readonly CategoryRepository $repository = new CategoryRepository())
    {
    }

    public function list(Request $request, array $params): void
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 15)));
        Response::json(200, true, $this->repository->list($page, $limit), 'Listado de categorías.');
    }

    public function create(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['name' => ['required', 'string']]);
        if (isset($request->body['name']) && is_string($request->body['name']) && strlen($request->body['name']) > 100) {
            $errors['name'][] = 'El nombre no puede exceder 100 caracteres.';
        }
        if (isset($request->body['description']) && (!is_string($request->body['description']) || strlen($request->body['description']) > 255)) {
            $errors['description'][] = 'La descripción no puede exceder 255 caracteres.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        try {
            $category = $this->repository->create($request->body);
            Response::json(201, true, $category, 'Categoría creada.');
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::json(409, false, null, 'Ya existe una categoría con ese nombre.');
                return;
            }
            throw $exception;
        }
    }

    public function get(Request $request, array $params): void
    {
        $category = $this->repository->findById((int) $params['id']);
        if ($category === null) {
            Response::json(404, false, null, 'Categoría no encontrada.');
            return;
        }

        Response::json(200, true, $category, 'Categoría consultada.');
    }

    public function update(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['name' => ['required', 'string']]);
        if (isset($request->body['name']) && is_string($request->body['name']) && strlen($request->body['name']) > 100) {
            $errors['name'][] = 'El nombre no puede exceder 100 caracteres.';
        }
        if (isset($request->body['description']) && (!is_string($request->body['description']) || strlen($request->body['description']) > 255)) {
            $errors['description'][] = 'La descripción no puede exceder 255 caracteres.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        try {
            $category = $this->repository->update((int) $params['id'], $request->body);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::json(409, false, null, 'Ya existe una categoría con ese nombre.');
                return;
            }
            throw $exception;
        }
        if ($category === null) {
            Response::json(404, false, null, 'Categoría no encontrada.');
            return;
        }

        Response::json(200, true, $category, 'Categoría actualizada.');
    }

    public function delete(Request $request, array $params): void
    {
        $deleted = $this->repository->softDelete((int) $params['id']);
        if (!$deleted) {
            Response::json(404, false, null, 'Categoría no encontrada.');
            return;
        }

        Response::json(200, true, null, 'Categoría desactivada.');
    }
}
