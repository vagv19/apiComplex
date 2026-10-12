<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\PlayerRepository;

class PlayerController
{
    public function __construct(private readonly PlayerRepository $repository = new PlayerRepository())
    {
    }

    public function list(Request $request, array $params): void
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 15)));
        $search = $request->query['search'] ?? null;
        Response::json(200, true, $this->repository->list($page, $limit, $search), 'Listado de jugadores.');
    }

    public function create(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['first_name' => ['required', 'string'], 'last_name' => ['required', 'string']]);
        foreach (['first_name' => 100, 'last_name' => 150, 'photo_url' => 500, 'phone' => 30, 'email' => 150] as $field => $maxLength) {
            if (isset($request->body[$field]) && (!is_string($request->body[$field]) || strlen($request->body[$field]) > $maxLength)) {
                $errors[$field][] = "El campo no puede exceder {$maxLength} caracteres.";
            }
        }
        if (isset($request->body['email']) && $request->body['email'] !== '' && !filter_var($request->body['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'El correo es inválido.';
        }
        $this->validateBirthDate($request->body, $errors);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        $player = $this->repository->create($request->body);
        Response::json(201, true, $player, 'Jugador creado.');
    }

    public function get(Request $request, array $params): void
    {
        $player = $this->repository->findById((int) $params['id']);
        if ($player === null) {
            Response::json(404, false, null, 'Jugador no encontrado.');
            return;
        }

        Response::json(200, true, $player, 'Jugador consultado.');
    }

    public function update(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['first_name' => ['required', 'string'], 'last_name' => ['required', 'string']]);
        foreach (['first_name' => 100, 'last_name' => 150, 'photo_url' => 500, 'phone' => 30, 'email' => 150] as $field => $maxLength) {
            if (isset($request->body[$field]) && (!is_string($request->body[$field]) || strlen($request->body[$field]) > $maxLength)) {
                $errors[$field][] = "El campo no puede exceder {$maxLength} caracteres.";
            }
        }
        if (isset($request->body['email']) && $request->body['email'] !== '' && !filter_var($request->body['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'El correo es inválido.';
        }
        $this->validateBirthDate($request->body, $errors);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        $player = $this->repository->update((int) $params['id'], $request->body);
        if ($player === null) {
            Response::json(404, false, null, 'Jugador no encontrado.');
            return;
        }

        Response::json(200, true, $player, 'Jugador actualizado.');
    }

    public function delete(Request $request, array $params): void
    {
        $deleted = $this->repository->softDelete((int) $params['id']);
        if (!$deleted) {
            Response::json(404, false, null, 'Jugador no encontrado.');
            return;
        }

        Response::json(200, true, null, 'Jugador desactivado.');
    }

    public function history(Request $request, array $params): void
    {
        $rows = $this->repository->tournamentHistory((int) $params['id']);
        Response::json(200, true, $rows, 'Participaciones del jugador.');
    }

    private function validateBirthDate(array $data, array &$errors): void
    {
        if (!isset($data['birth_date']) || $data['birth_date'] === '') {
            return;
        }
        $date = is_string($data['birth_date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $data['birth_date']) : false;
        if ($date === false || $date->format('Y-m-d') !== $data['birth_date']) {
            $errors['birth_date'][] = 'La fecha debe tener el formato YYYY-MM-DD.';
        }
    }
}
