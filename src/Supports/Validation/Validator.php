<?php

/*
|--------------------------------------------------------------------------
| Classe Validator
|--------------------------------------------------------------------------
|
| Motor de validação do Slenix. Suporta regras encadeadas com pipe (|),
| mensagens de erro customizadas, e integração direta com Request.
|
| Regras disponíveis:
|   required, string, integer, float, boolean, array, email,
|   min, max, between, in, not_in, regex, unique, confirmed,
|   date, url, ip, uuid, nullable
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

use Slenix\Database\Connection;

class Validator
{
    protected array $data;
    protected array $rules;
    protected array $messages;
    protected array $errors = [];
    protected array $validated = [];

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
    }

    // =========================================================
    // FACTORY
    // =========================================================

    public static function make(array $data, array $rules, array $messages = []): static
    {
        return new static($data, $rules, $messages);
    }

    // =========================================================
    // EXECUTAR
    // =========================================================

    public function validate(): array
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $nullable = in_array('nullable', $rules);
            $value = $this->getValue($field);

            // Se nullable e vazio, pula as outras regras
            if ($nullable && ($value === null || $value === '')) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable')
                    continue;

                [$ruleName, $params] = $this->parseRule($rule);
                $passed = $this->applyRule($field, $value, $ruleName, $params);

                if (!$passed)
                    break; // Para no primeiro erro do campo
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    public function fails(): bool
    {
        try {
            $this->validate();
            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function safe(): array
    {
        return $this->validated;
    }

    // =========================================================
    // APLICAR REGRA
    // =========================================================

    protected function applyRule(string $field, mixed $value, string $rule, array $params): bool
    {
        $passed = match ($rule) {
            'required' => $this->ruleRequired($value),
            'string' => $this->ruleString($value),
            'integer' => $this->ruleInteger($value),
            'float' => $this->ruleFloat($value),
            'boolean' => $this->ruleBoolean($value),
            'array' => $this->ruleArray($value),
            'email' => $this->ruleEmail($value),
            'url' => $this->ruleUrl($value),
            'ip' => $this->ruleIp($value),
            'uuid' => $this->ruleUuid($value),
            'date' => $this->ruleDate($value),
            'confirmed' => $this->ruleConfirmed($field, $value),
            'min' => $this->ruleMin($value, (int) ($params[0] ?? 0)),
            'max' => $this->ruleMax($value, (int) ($params[0] ?? 0)),
            'between' => $this->ruleBetween($value, (float) ($params[0] ?? 0), (float) ($params[1] ?? 0)),
            'in' => $this->ruleIn($value, $params),
            'not_in' => $this->ruleNotIn($value, $params),
            'regex' => $this->ruleRegex($value, $params[0] ?? ''),
            'unique' => $this->ruleUnique($value, $params[0] ?? '', $params[1] ?? 'id', $params[2] ?? null),
            'exists' => $this->ruleExists($value, $params[0] ?? '', $params[1] ?? 'id'),
            'size' => $this->ruleSize($value, (int) ($params[0] ?? 0)),
            'alpha' => $this->ruleAlpha($value),
            'alpha_num' => $this->ruleAlphaNum($value),
            'numeric' => $this->ruleNumeric($value),
            default => true,
        };

        if (!$passed) {
            $this->addError($field, $rule, $params);
        }

        return $passed;
    }

    // =========================================================
    // REGRAS
    // =========================================================

    protected function ruleRequired(mixed $v): bool
    {
        if (is_string($v))
            return trim($v) !== '';
        if (is_array($v))
            return !empty($v);
        return $v !== null;
    }

    protected function ruleString(mixed $v): bool
    {
        return is_string($v);
    }
    protected function ruleInteger(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_INT) !== false;
    }
    protected function ruleFloat(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_FLOAT) !== false;
    }
    protected function ruleBoolean(mixed $v): bool
    {
        return in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }
    protected function ruleArray(mixed $v): bool
    {
        return is_array($v);
    }
    protected function ruleEmail(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }
    protected function ruleUrl(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_URL) !== false;
    }
    protected function ruleIp(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_IP) !== false;
    }
    protected function ruleAlpha(mixed $v): bool
    {
        return ctype_alpha((string) $v);
    }
    protected function ruleAlphaNum(mixed $v): bool
    {
        return ctype_alnum((string) $v);
    }
    protected function ruleNumeric(mixed $v): bool
    {
        return is_numeric($v);
    }

    protected function ruleUuid(mixed $v): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $v);
    }

    protected function ruleDate(mixed $v): bool
    {
        return strtotime((string) $v) !== false;
    }

    protected function ruleConfirmed(string $field, mixed $v): bool
    {
        return $v === $this->getValue($field . '_confirmation');
    }

    protected function ruleMin(mixed $v, int $min): bool
    {
        if (is_string($v))
            return mb_strlen($v) >= $min;
        if (is_array($v))
            return count($v) >= $min;
        return (float) $v >= $min;
    }

    protected function ruleMax(mixed $v, int $max): bool
    {
        if (is_string($v))
            return mb_strlen($v) <= $max;
        if (is_array($v))
            return count($v) <= $max;
        return (float) $v <= $max;
    }

    protected function ruleBetween(mixed $v, float $min, float $max): bool
    {
        $val = is_string($v) ? mb_strlen($v) : (float) $v;
        return $val >= $min && $val <= $max;
    }

    protected function ruleIn(mixed $v, array $params): bool
    {
        return in_array((string) $v, $params);
    }
    protected function ruleNotIn(mixed $v, array $params): bool
    {
        return !in_array((string) $v, $params);
    }

    protected function ruleSize(mixed $v, int $size): bool
    {
        if (is_string($v))
            return mb_strlen($v) === $size;
        if (is_array($v))
            return count($v) === $size;
        return (int) $v === $size;
    }

    protected function ruleRegex(mixed $v, string $pattern): bool
    {
        return (bool) preg_match($pattern, (string) $v);
    }

    protected function ruleUnique(mixed $v, string $table, string $column, ?string $ignoreId): bool
    {
        if (empty($table))
            return true;
        try {
            $pdo = Connection::getInstance();
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value";
            $bind = ['value' => $v];
            if ($ignoreId !== null) {
                $sql .= " AND `id` != :ignore";
                $bind['ignore'] = $ignoreId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            return (int) $stmt->fetchColumn() === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    protected function ruleExists(mixed $v, string $table, string $column): bool
    {
        if (empty($table))
            return true;
        try {
            $pdo = Connection::getInstance();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value");
            $stmt->execute(['value' => $v]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    // =========================================================
    // ERROS
    // =========================================================

    protected function addError(string $field, string $rule, array $params): void
    {
        $customKey = "{$field}.{$rule}";
        if (isset($this->messages[$customKey])) {
            $this->errors[$field] = $this->messages[$customKey];
            return;
        }
        $this->errors[$field] = $this->defaultMessage($field, $rule, $params);
    }

    protected function defaultMessage(string $field, string $rule, array $params): string
    {
        $label = ucfirst(str_replace('_', ' ', $field));
        return match ($rule) {
            'required' => "O campo {$label} é obrigatório.",
            'string' => "O campo {$label} deve ser texto.",
            'integer' => "O campo {$label} deve ser um número inteiro.",
            'float' => "O campo {$label} deve ser um número decimal.",
            'boolean' => "O campo {$label} deve ser verdadeiro ou falso.",
            'array' => "O campo {$label} deve ser uma lista.",
            'email' => "O campo {$label} deve ser um e-mail válido.",
            'url' => "O campo {$label} deve ser uma URL válida.",
            'ip' => "O campo {$label} deve ser um IP válido.",
            'uuid' => "O campo {$label} deve ser um UUID válido.",
            'date' => "O campo {$label} deve ser uma data válida.",
            'alpha' => "O campo {$label} deve conter apenas letras.",
            'alpha_num' => "O campo {$label} deve conter apenas letras e números.",
            'numeric' => "O campo {$label} deve ser numérico.",
            'confirmed' => "A confirmação do campo {$label} não coincide.",
            'min' => "O campo {$label} deve ter no mínimo {$params[0]} caracteres/valor.",
            'max' => "O campo {$label} deve ter no máximo {$params[0]} caracteres/valor.",
            'between' => "O campo {$label} deve estar entre {$params[0]} e {$params[1]}.",
            'in' => "O valor do campo {$label} é inválido.",
            'not_in' => "O valor do campo {$label} não é permitido.",
            'unique' => "O {$label} já está em uso.",
            'exists' => "O {$label} selecionado não existe.",
            'size' => "O campo {$label} deve ter exatamente {$params[0]} caracteres.",
            'regex' => "O formato do campo {$label} é inválido.",
            default => "O campo {$label} é inválido.",
        };
    }

    // =========================================================
    // HELPERS
    // =========================================================

    protected function getValue(string $field): mixed
    {
        // Suporte a dot-notation: 'address.city'
        $keys = explode('.', $field);
        $value = $this->data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value))
                return null;
            $value = $value[$key];
        }
        return $value;
    }

    protected function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':'))
            return [$rule, []];
        [$name, $paramStr] = explode(':', $rule, 2);
        return [$name, explode(',', $paramStr)];
    }
}