<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\LogicalTournamentRepository;

final class LogicalTournamentService
{
    private const MASTER_STATUSES = ['DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED'];
    private const COMPETITION_STATUSES = ['DRAFT', 'REGISTRATION', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
    private const FORMATS = ['LEAGUE', 'CUP', 'FLASH'];
    private const RULE_FIELDS = [
        'format',
        'status',
        'points_for_win',
        'points_for_draw',
        'points_for_loss',
        'rounds_count',
        'qualified_teams',
        'player_registration_deadline_round',
        'allow_player_registration',
        'player_registration_start_at',
        'player_registration_end_at',
        'tie_breakers',
    ];

    public function __construct(private readonly LogicalTournamentRepository $repository = new LogicalTournamentRepository())
    {
    }

    public function list(int $page, int $limit): array
    {
        return $this->repository->list($page, $limit);
    }

    public function get(string $logicalId): array
    {
        [$type, $id] = $this->parseId($logicalId);
        $entity = $this->repository->find($type, $id);
        if ($entity === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }

        return $entity;
    }

    public function availableCategories(int $page, int $limit, ?string $logicalId): array
    {
        $masterId = null;
        if ($logicalId !== null && $logicalId !== '') {
            [$type, $masterId] = $this->parseId($logicalId);
            if ($type !== 'group') {
                throw new HttpException(409, 'No se agregan categorías a una competencia anterior de categoría única.');
            }
            if ($this->repository->find($type, $masterId) === null) {
                throw new HttpException(404, 'Torneo lógico no encontrado.');
            }
        }

        return $this->repository->availableCategories($page, $limit, $masterId);
    }

    public function create(array $payload, array $actor): array
    {
        $this->rejectUnknown($payload, [
            'name', 'description', 'season', 'start_date', 'end_date', 'status', 'category_ids', 'rules',
        ]);
        $details = $this->normalizeDetails($payload, true);
        $categoryIds = $this->normalizeCategoryIds($payload['category_ids'] ?? null);
        $rulesInput = array_key_exists('rules', $payload) ? $payload['rules'] : [];
        if (!is_array($rulesInput)) {
            $this->invalid('rules', 'Las reglas deben enviarse como un objeto JSON.');
        }
        if (
            !array_key_exists('player_registration_start_at', $rulesInput)
            && !array_key_exists('player_registration_end_at', $rulesInput)
        ) {
            $rulesInput = [...$rulesInput, ...PlayerRegistrationPolicy::defaultWindow()];
        }
        $rules = $this->normalizeRules($rulesInput);

        try {
            $created = $this->repository->create($details, $rules, $categoryIds, $actor);
        } catch (\DomainException $exception) {
            $status = str_contains($exception->getMessage(), 'no existen o están inactivas') ? 404 : 409;
            throw new HttpException($status, $exception->getMessage(), [], $exception);
        }
        if ($created === null) {
            throw new HttpException(500, 'No se pudo consultar el torneo lógico recién creado.');
        }

        return $created;
    }

    public function updateDetails(string $logicalId, array $payload, array $actor): array
    {
        [$type, $id] = $this->parseId($logicalId);
        $this->rejectUnknown($payload, ['name', 'description', 'season', 'start_date', 'end_date']);
        $current = $this->repository->find($type, $id);
        if ($current === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }
        $changes = $this->normalizeDetails($payload, false, $current);
        if ($changes === []) {
            $this->invalid('body', 'Indica al menos un campo para actualizar.');
        }
        try {
            $updated = $this->repository->updateDetails($type, $id, $changes, $actor);
        } catch (\DomainException $exception) {
            throw new HttpException(409, $exception->getMessage(), [], $exception);
        }
        if ($updated === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }

        return $updated;
    }

    public function updateRules(string $logicalId, array $payload, array $actor): array
    {
        [$type, $id] = $this->parseId($logicalId);
        $this->rejectUnknown($payload, ['rules']);
        if (!isset($payload['rules']) || !is_array($payload['rules']) || $payload['rules'] === []) {
            $this->invalid('rules', 'Indica al menos una regla dentro de un objeto JSON.');
        }
        $registrationWindowProvided =
            array_key_exists('player_registration_start_at', $payload['rules'])
            || array_key_exists('player_registration_end_at', $payload['rules']);
        $current = $this->repository->find($type, $id);
        if ($current === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }
        $rules = $this->normalizeRules($payload['rules'], $current['rules'] ?? null);
        try {
            $updated = $this->repository->updateRules(
                $type,
                $id,
                $rules,
                $current['rules'] ?? [],
                $actor,
                $registrationWindowProvided,
            );
        } catch (\DomainException $exception) {
            throw new HttpException(409, $exception->getMessage(), [], $exception);
        }
        if ($updated === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }

        return $updated;
    }

    public function addCategory(string $logicalId, array $payload, array $actor): array
    {
        [$type, $id] = $this->parseId($logicalId);
        $this->rejectUnknown($payload, ['category_id']);
        $categoryId = $this->positiveInteger($payload['category_id'] ?? null, 'category_id');
        try {
            $updated = $this->repository->addCategory($type, $id, $categoryId, $actor);
        } catch (\DomainException $exception) {
            $status = str_contains($exception->getMessage(), 'no existen o están inactivas') ? 404 : 409;
            throw new HttpException($status, $exception->getMessage(), [], $exception);
        }
        if ($updated === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }

        return $updated;
    }

    public function removeCategory(string $logicalId, array $payload, array $actor): array
    {
        [$type, $id] = $this->parseId($logicalId);
        $this->rejectUnknown($payload, ['category_id']);
        $categoryId = $this->positiveInteger($payload['category_id'] ?? null, 'category_id');
        try {
            $updated = $this->repository->removeCategory($type, $id, $categoryId, $actor);
        } catch (\DomainException $exception) {
            throw new HttpException(409, $exception->getMessage(), [], $exception);
        }
        if ($updated === null) {
            throw new HttpException(404, 'Torneo o categoría no encontrados.');
        }

        return $updated;
    }

    public function retire(string $logicalId, array $payload, array $actor): array
    {
        [$type, $id] = $this->parseId($logicalId);
        $this->rejectUnknown($payload, ['reason']);
        if (isset($payload['reason']) && (!is_string($payload['reason']) || mb_strlen($payload['reason']) > 500)) {
            $this->invalid('reason', 'El motivo debe ser texto de hasta 500 caracteres.');
        }
        if (isset($payload['reason']) && trim($payload['reason']) !== '') {
            $actor['reason'] = trim($payload['reason']);
        }
        try {
            $retired = $this->repository->retire($type, $id, $actor);
        } catch (\DomainException $exception) {
            throw new HttpException(409, $exception->getMessage(), [], $exception);
        }
        if ($retired === null) {
            throw new HttpException(404, 'Torneo lógico no encontrado.');
        }

        return $retired;
    }

    private function normalizeDetails(array $payload, bool $creating, ?array $current = null): array
    {
        $errors = [];
        $result = [];
        if ($creating || array_key_exists('name', $payload)) {
            if (!isset($payload['name']) || !is_string($payload['name']) || trim($payload['name']) === '') {
                $errors['name'][] = 'El nombre es obligatorio y debe ser texto.';
            } elseif (mb_strlen(trim($payload['name'])) > 150) {
                $errors['name'][] = 'El nombre no puede exceder 150 caracteres.';
            } else {
                $result['name'] = trim($payload['name']);
            }
        }
        foreach (['description' => 5000, 'season' => 50] as $field => $maxLength) {
            if (!$creating && !array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field] ?? null;
            if ($value !== null && (!is_string($value) || mb_strlen($value) > $maxLength)) {
                $errors[$field][] = 'El valor debe ser texto de hasta ' . $maxLength . ' caracteres.';
            } else {
                $result[$field] = $value;
            }
        }
        foreach (['start_date', 'end_date'] as $field) {
            if (!$creating && !array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field] ?? null;
            if ($value !== null && !$this->isDate($value)) {
                $errors[$field][] = 'La fecha debe tener el formato YYYY-MM-DD.';
            } else {
                $result[$field] = $value;
            }
        }
        if ($creating) {
            $status = array_key_exists('status', $payload) ? $payload['status'] : 'DRAFT';
            if (!is_string($status) || !in_array(strtoupper($status), self::MASTER_STATUSES, true)) {
                $errors['status'][] = 'El estado del torneo lógico no es válido.';
            } else {
                $result['status'] = strtoupper($status);
            }
        }
        $start = array_key_exists('start_date', $result)
            ? $result['start_date']
            : ($current['start_date'] ?? null);
        $end = array_key_exists('end_date', $result)
            ? $result['end_date']
            : ($current['end_date'] ?? null);
        if (is_string($start) && is_string($end) && $start > $end) {
            $errors['end_date'][] = 'La fecha de cierre no puede ser anterior a la fecha de inicio.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validación fallida.', $errors);
        }

        if ($creating) {
            return [
                'name' => $result['name'],
                'description' => $result['description'],
                'season' => $result['season'],
                'start_date' => $result['start_date'],
                'end_date' => $result['end_date'],
                'status' => $result['status'],
            ];
        }

        return $result;
    }

    private function normalizeRules(array $input, ?array $current = null): array
    {
        $this->rejectUnknown($input, self::RULE_FIELDS);
        $defaults = $current ?? [
            'format' => 'LEAGUE',
            'status' => 'DRAFT',
            'points_for_win' => 3,
            'points_for_draw' => 1,
            'points_for_loss' => 0,
            'rounds_count' => 10,
            'qualified_teams' => 8,
            'player_registration_deadline_round' => 4,
            'allow_player_registration' => true,
            'player_registration_start_at' => null,
            'player_registration_end_at' => null,
            'tie_breakers' => null,
        ];
        $rules = array_replace($defaults, $input);
        $errors = [];
        if (!is_string($rules['format']) || !in_array(strtoupper($rules['format']), self::FORMATS, true)) {
            $errors['format'][] = 'El formato del torneo no es válido.';
        } else {
            $rules['format'] = strtoupper($rules['format']);
        }
        if (!is_string($rules['status']) || !in_array(strtoupper($rules['status']), self::COMPETITION_STATUSES, true)) {
            $errors['status'][] = 'El estado de la competencia no es válido.';
        } else {
            $rules['status'] = strtoupper($rules['status']);
        }
        foreach ([
            'points_for_win' => 255,
            'points_for_draw' => 255,
            'points_for_loss' => 255,
            'rounds_count' => 255,
            'qualified_teams' => 255,
            'player_registration_deadline_round' => 255,
        ] as $field => $maximum) {
            $value = $rules[$field];
            if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 0 || (int) $value > $maximum) {
                $errors[$field][] = 'El valor debe ser un entero entre 0 y ' . $maximum . '.';
            } elseif ($field === 'rounds_count' && (int) $value < 1) {
                $errors[$field][] = 'El torneo debe tener al menos una jornada regular.';
            } else {
                $rules[$field] = (int) $value;
            }
        }
        $registration = $rules['allow_player_registration'];
        if (!in_array($registration, [true, false, 0, 1, '0', '1'], true)) {
            $errors['allow_player_registration'][] = 'El valor debe ser booleano.';
        } else {
            $rules['allow_player_registration'] = filter_var($registration, FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['player_registration_start_at', 'player_registration_end_at'] as $field) {
            $value = $rules[$field];
            if ($value !== null && (!is_string($value) || !$this->isDateTime($value))) {
                $errors[$field][] = 'La fecha y hora debe tener el formato YYYY-MM-DD HH:MM:SS.';
            }
        }
        if (($rules['player_registration_start_at'] === null) !== ($rules['player_registration_end_at'] === null)) {
            $errors['player_registration_end_at'][] = 'Indica ambas fechas del periodo o deja ambas vacías.';
        } elseif (
            is_string($rules['player_registration_start_at'])
            && is_string($rules['player_registration_end_at'])
            && $rules['player_registration_start_at'] > $rules['player_registration_end_at']
        ) {
            $errors['player_registration_end_at'][] = 'El cierre del periodo no puede ser anterior a su apertura.';
        }
        if (!is_string($rules['tie_breakers']) && !is_array($rules['tie_breakers']) && $rules['tie_breakers'] !== null) {
            $errors['tie_breakers'][] = 'Las reglas de desempate deben ser texto, objeto, lista o null.';
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validación fallida.', $errors);
        }

        return $rules;
    }

    private function normalizeCategoryIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            $this->invalid('category_ids', 'Indica al menos una categoría.');
        }
        $ids = [];
        foreach ($value as $index => $categoryId) {
            $ids[] = $this->positiveInteger($categoryId, 'category_ids.' . $index);
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $this->invalid('category_ids', 'No se permiten categorías duplicadas.');
        }

        return $ids;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->invalid($field, 'El identificador debe ser un entero positivo.');
        }

        return (int) $value;
    }

    private function parseId(string $logicalId): array
    {
        if (preg_match('/^(group|single)-([1-9][0-9]*)$/', $logicalId, $matches) !== 1) {
            throw new HttpException(422, 'El identificador lógico no es válido.');
        }
        $id = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new HttpException(422, 'El identificador lógico no es válido.');
        }

        return [$matches[1], (int) $id];
    }

    private function rejectUnknown(array $data, array $allowed): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $key) {
                $errors[(string) $key][] = 'El campo no está permitido.';
            }
            throw new HttpException(422, 'Validación fallida.', $errors);
        }
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    private function invalid(string $field, string $message): never
    {
        throw new HttpException(422, 'Validación fallida.', [$field => [$message]]);
    }
}
