<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\MatchResultService;

class MatchController
{
    public function __construct(private readonly MatchResultService $service = new MatchResultService())
    {
    }

    public function result(Request $request, array $params): void
    {
        $actor = AuthMiddleware::requireCapability(
            $request->headers,
            'matches.results.manage',
        );
        $homeScore = filter_var($request->body['home_score'] ?? null, FILTER_VALIDATE_INT);
        $awayScore = filter_var($request->body['away_score'] ?? null, FILTER_VALIDATE_INT);
        if ($homeScore === false || $awayScore === false || $homeScore < 0 || $awayScore < 0 || $homeScore > 65535 || $awayScore > 65535) {
            Response::json(422, false, null, 'Los marcadores deben ser enteros entre 0 y 65535.');
            return;
        }
        $homePenalties = $this->optionalScore($request->body, 'home_penalties');
        $awayPenalties = $this->optionalScore($request->body, 'away_penalties');
        if ($homePenalties === false || $awayPenalties === false || (($homePenalties === null) !== ($awayPenalties === null))) {
            Response::json(422, false, null, 'Los penales deben enviarse juntos como enteros entre 0 y 65535.');
            return;
        }
        $reason = $request->body['reason'] ?? 'Resultado actualizado';
        if (!is_string($reason) || strlen($reason) > 255) {
            Response::json(422, false, null, 'El motivo no puede exceder 255 caracteres.');
            return;
        }

        try {
            $result = $this->service->recordResult(
                (int) $params['id'],
                $homeScore,
                $awayScore,
                (int) $actor['sub'],
                $reason,
                $homePenalties,
                $awayPenalties,
                (string) $actor['role'],
                filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null,
            );
            Response::json(200, true, $result, 'Resultado registrado.');
        } catch (\DomainException $exception) {
            $notFound = str_contains($exception->getMessage(), 'no encontrado');
            Response::json($notFound ? 404 : 409, false, null, $exception->getMessage());
        }
    }

    private function optionalScore(array $body, string $key): int|false|null
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        $score = filter_var($body[$key], FILTER_VALIDATE_INT);
        return $score === false || $score < 0 || $score > 65535 ? false : $score;
    }
}
