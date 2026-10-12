<?php

declare(strict_types=1);

namespace App\Core;

class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldRuleList = is_array($fieldRules) ? $fieldRules : [$fieldRules];

            foreach ($fieldRuleList as $rule) {
                if ($rule === 'required' && (is_null($value) || trim((string) $value) === '')) {
                    $errors[$field][] = 'El campo es obligatorio.';
                }

                if ($rule === 'string' && !is_string($value) && !is_null($value)) {
                    $errors[$field][] = 'El campo debe ser texto.';
                }

                if ($rule === 'int' && !is_int($value) && !ctype_digit((string) $value)) {
                    $errors[$field][] = 'El campo debe ser numérico.';
                }

                if ($rule === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = 'El correo es inválido.';
                }
            }
        }

        return $errors;
    }
}
