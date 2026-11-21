<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PublicTournamentService;
use DomainException;
use RuntimeException;

final class PublicTournamentController
{
    public function __construct(private readonly PublicTournamentService $service = new PublicTournamentService())
    {
    }

    public function list(Request $request, array $params): void
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(50, max(1, (int) ($request->query['limit'] ?? 12)));

        Response::json(200, true, $this->service->list($page, $limit), 'Torneos públicos consultados.');
    }

    public function firstMatchOfDay(Request $request, array $params): void
    {
        $date = $this->parseDate($request->query['date'] ?? null);
        if ($date === null) {
            Response::json(422, false, null, 'La fecha debe tener el formato AAAA-MM-DD y ser válida.', [
                'date' => ['Indica una fecha válida con el formato AAAA-MM-DD.'],
            ]);
            return;
        }

        Response::json(
            200,
            true,
            $this->service->getFirstMatchOfDay($date),
            'Primer partido de la fecha consultado.'
        );
    }

    public function topScorers(Request $request, array $params): void
    {
        $tournamentId = $this->parseTournamentId($params['id'] ?? null);
        if ($tournamentId === null) {
            Response::json(422, false, null, 'Identificador de torneo inválido.');
            return;
        }

        $limit = $this->parseLimit($request->query['limit'] ?? null);
        if ($limit === null) {
            Response::json(422, false, null, 'El parámetro limit debe ser un entero entre 1 y 100.');
            return;
        }

        try {
            Response::json(200, true, $this->service->getTopScorers($tournamentId, $limit), 'Tabla de goleadores consultada.');
        } catch (DomainException) {
            Response::json(404, false, null, 'Torneo no encontrado.');
        }
    }

    public function standings(Request $request, array $params): void
    {
        $tournamentId = $this->parseTournamentId($params['id'] ?? null);
        if ($tournamentId === null) {
            Response::json(422, false, null, 'Identificador de torneo inválido.');
            return;
        }

        $groupId = null;
        if (array_key_exists('group_id', $request->query)) {
            $groupId = $this->parseTournamentId($request->query['group_id']);
            if ($groupId === null) {
                Response::json(422, false, null, 'El parámetro group_id debe ser un entero positivo.');
                return;
            }
        }

        try {
            Response::json(
                200,
                true,
                $this->service->getStandings($tournamentId, $groupId),
                'Tabla general consultada.'
            );
        } catch (DomainException) {
            Response::json(404, false, null, 'Torneo o grupo no encontrado.');
        }
    }

    public function groups(Request $request, array $params): void
    {
        $tournamentId = $this->parseTournamentId($params['id'] ?? null);
        if ($tournamentId === null) {
            Response::json(422, false, null, 'Identificador de torneo inválido.');
            return;
        }

        try {
            Response::json(200, true, $this->service->getGroups($tournamentId), 'Grupos públicos consultados.');
        } catch (DomainException) {
            Response::json(404, false, null, 'Torneo no encontrado.');
        }
    }

    public function upcomingMatches(Request $request, array $params): void
    {
        $tournamentId = $this->parseTournamentId($params['id'] ?? null);
        if ($tournamentId === null) {
            Response::json(422, false, null, 'Identificador de torneo inválido.');
            return;
        }

        $limit = $this->parseUpcomingLimit($request->query['limit'] ?? null);
        if ($limit === null) {
            Response::json(422, false, null, 'El parámetro limit debe ser un entero entre 1 y 5.');
            return;
        }

        try {
            Response::json(
                200,
                true,
                $this->service->getUpcomingMatches($tournamentId, $limit),
                'Próximos partidos consultados.'
            );
        } catch (DomainException) {
            Response::json(404, false, null, 'Torneo no encontrado.');
        }
    }

    public function images(Request $request, array $params): void
    {
        $tournamentId = $this->parseTournamentId($params['id'] ?? null);
        if ($tournamentId === null) {
            Response::json(422, false, null, 'Identificador de torneo inválido.');
            return;
        }

        try {
            $suffix = '/api/v1/public/tournaments/' . $tournamentId . '/images';
            $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            if (!is_string($requestPath) || !str_ends_with($requestPath, $suffix)) {
                throw new RuntimeException('No se pudo resolver la ruta pública de la galería.');
            }
            $prefix = substr($requestPath, 0, -strlen($suffix));
            $urlPrefix = rtrim($prefix, '/') . $suffix;
            Response::json(200, true, $this->service->getImages($tournamentId, $urlPrefix), 'Galería consultada.');
        } catch (DomainException) {
            Response::json(404, false, null, 'Torneo no encontrado.');
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::json(500, false, null, 'No fue posible consultar la galería.');
        }
    }

    public function image(Request $request, array $params): void
    {
        $tournamentId = $this->parseTournamentId($params['id'] ?? null);
        if ($tournamentId === null) {
            Response::json(422, false, null, 'Identificador de torneo inválido.');
            return;
        }

        $filename = $params['filename'] ?? '';
        if (!is_string($filename)) {
            Response::json(404, false, null, 'Imagen no encontrada.');
            return;
        }

        try {
            $image = $this->service->getImage($tournamentId, rawurldecode($filename));
            if ($image === null) {
                Response::json(404, false, null, 'Imagen no encontrada.');
                return;
            }

            $stream = fopen($image['path'], 'rb');
            if ($stream === false) {
                throw new RuntimeException('No se pudo abrir un archivo de la galería.');
            }

            header('Content-Type: ' . $image['mime_type']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=3600');
            if (fpassthru($stream) === false) {
                error_log('No se pudo completar la lectura de un archivo de la galería.');
            }
            fclose($stream);
        } catch (DomainException) {
            Response::json(404, false, null, 'Torneo no encontrado.');
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::json(500, false, null, 'No fue posible leer la imagen.');
        }
    }

    private function parseTournamentId(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    private function parseLimit(mixed $value): ?int
    {
        if ($value === null) {
            return 20;
        }
        if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)) {
            return null;
        }

        $limit = filter_var($value, FILTER_VALIDATE_INT);

        return $limit !== false && $limit >= 1 && $limit <= 100 ? $limit : null;
    }

    private function parseUpcomingLimit(mixed $value): ?int
    {
        if ($value === null) {
            return 5;
        }
        if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)) {
            return null;
        }

        $limit = filter_var($value, FILTER_VALIDATE_INT);

        return $limit !== false && $limit >= 1 && $limit <= 5 ? $limit : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $value;
    }
}
