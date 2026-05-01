<?php

/*
|--------------------------------------------------------------------------
| Validator Class
|--------------------------------------------------------------------------
|
| Slenix Validation Engine. Supports piped rules (|), custom messages,
| and direct request integration.
|
| Available Rules:
| required, string, integer, float, boolean, array, email, min, max,
| between, in, not_in, regex, unique, exists, confirmed, date, url,
| ip, uuid, nullable, size, alpha, alpha_num, numeric.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

use Slenix\Database\Connection;

class Validator
{
    /** @var array Data to be validated */
    protected array $data;

    /** @var array Defined validation rules */
    protected array $rules;

    /** @var array Custom error messages */
    protected array $messages;

    /** @var array Current validation errors */
    protected array $errors = [];

    /** @var array Data that passed validation */
    protected array $validated = [];

    /**
     * Validator constructor.
     *
     * @param array $data     Input data to validate
     * @param array $rules    Validation rules
     * @param array $messages Custom error messages
     */
    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data     = $data;
        $this->rules    = $rules;
        $this->messages = $messages;
    }

    /**
     * Static factory to create a Validator instance.
     *
     * @param  array  $data
     * @param  array  $rules
     * @param  array  $messages
     * @return static
     */
    public static function make(array $data, array $rules, array $messages = []): static
    {
        return new static($data, $rules, $messages);
    }

    /**
     * Runs all validation rules against the data.
     *
     * @return array Validated (safe) data
     * @throws ValidationException If any rule fails
     */
    public function validate(): array
    {
        $this->errors    = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules    = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $nullable = in_array('nullable', $rules, true);
            $value    = $this->getValue($field);

            if ($nullable && ($value === null || $value === '')) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable') continue;

                [$ruleName, $params] = $this->parseRule($rule);
                $passed = $this->applyRule($field, $value, $ruleName, $params);

                if (!$passed) break; // Stop on first failure for this field
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

    /**
     * Returns true if validation fails.
     *
     * @return bool
     */
    public function fails(): bool
    {
        try {
            $this->validate();
            return false;
        } catch (ValidationException) {
            return true;
        }
    }

    /**
     * Returns true if validation passes.
     *
     * @return bool
     */
    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * Returns the list of validation errors.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns only the first error message for each field.
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        return array_map(
            fn($error) => is_array($error) ? $error[0] : $error,
            $this->errors
        );
    }

    /**
     * Returns the first error message for a specific field.
     *
     * @param  string $field
     * @return string|null
     */
    public function first(string $field): ?string
    {
        $error = $this->errors[$field] ?? null;
        return is_array($error) ? $error[0] : $error;
    }

    /**
     * Returns the data that passed validation.
     *
     * @return array
     */
    public function safe(): array
    {
        return $this->validated;
    }

    /**
     * Returns validated data for the given keys only.
     *
     * @param  string ...$keys
     * @return array
     */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->validated, array_flip($keys));
    }

    /**
     * Returns validated data excluding the given keys.
     *
     * @param  string ...$keys
     * @return array
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    /**
     * Dispatches the value to the appropriate rule method.
     *
     * @param  string $field
     * @param  mixed  $value
     * @param  string $rule
     * @param  array  $params
     * @return bool
     */
    protected function applyRule(string $field, mixed $value, string $rule, array $params): bool
    {
        $passed = match ($rule) {
            'required'  => $this->ruleRequired($value),
            'string'    => $this->ruleString($value),
            'integer'   => $this->ruleInteger($value),
            'float'     => $this->ruleFloat($value),
            'boolean'   => $this->ruleBoolean($value),
            'array'     => $this->ruleArray($value),
            'email'     => $this->ruleEmail($value),
            'url'       => $this->ruleUrl($value),
            'ip'        => $this->ruleIp($value),
            'uuid'      => $this->ruleUuid($value),
            'date'      => $this->ruleDate($value),
            'alpha'     => $this->ruleAlpha($value),
            'alpha_num' => $this->ruleAlphaNum($value),
            'numeric'   => $this->ruleNumeric($value),
            'confirmed' => $this->ruleConfirmed($field, $value),
            'min'       => $this->ruleMin($value, (int) ($params[0] ?? 0)),
            'max'       => $this->ruleMax($value, (int) ($params[0] ?? 0)),
            'between'   => $this->ruleBetween($value, (float) ($params[0] ?? 0), (float) ($params[1] ?? 0)),
            'size'      => $this->ruleSize($value, (int) ($params[0] ?? 0)),
            'in'        => $this->ruleIn($value, $params),
            'not_in'    => $this->ruleNotIn($value, $params),
            'regex'     => $this->ruleRegex($value, $params[0] ?? ''),
            'unique'    => $this->ruleUnique($value, $params[0] ?? '', $params[1] ?? 'id', $params[2] ?? null),
            'exists'    => $this->ruleExists($value, $params[0] ?? '', $params[1] ?? 'id'),
            default     => true,
        };

        if (!$passed) {
            $this->addError($field, $rule, $params);
        }

        return $passed;
    }

    /** @return bool Rule: field must be present and non-empty */
    protected function ruleRequired(mixed $v): bool
    {
        if (is_string($v)) return trim($v) !== '';
        if (is_array($v))  return !empty($v);
        return $v !== null;
    }

    /** @return bool Rule: value must be a string */
    protected function ruleString(mixed $v): bool
    {
        return is_string($v);
    }

    /** @return bool Rule: value must be an integer */
    protected function ruleInteger(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_INT) !== false;
    }

    /** @return bool Rule: value must be a float */
    protected function ruleFloat(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_FLOAT) !== false;
    }

    /** @return bool Rule: value must be boolean-like */
    protected function ruleBoolean(mixed $v): bool
    {
        return in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }

    /** @return bool Rule: value must be an array */
    protected function ruleArray(mixed $v): bool
    {
        return is_array($v);
    }

    /** @return bool Rule: value must be a valid email address */
    protected function ruleEmail(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return bool Rule: value must be a valid URL */
    protected function ruleUrl(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_URL) !== false;
    }

    /** @return bool Rule: value must be a valid IP address */
    protected function ruleIp(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_IP) !== false;
    }

    /** @return bool Rule: value must contain only alphabetic characters */
    protected function ruleAlpha(mixed $v): bool
    {
        return ctype_alpha((string) $v);
    }

    /** @return bool Rule: value must contain only alphanumeric characters */
    protected function ruleAlphaNum(mixed $v): bool
    {
        return ctype_alnum((string) $v);
    }

    /** @return bool Rule: value must be numeric */
    protected function ruleNumeric(mixed $v): bool
    {
        return is_numeric($v);
    }

    /** @return bool Rule: value must be a valid UUID v4 */
    protected function ruleUuid(mixed $v): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $v
        );
    }

    /** @return bool Rule: value must be a parseable date */
    protected function ruleDate(mixed $v): bool
    {
        return strtotime((string) $v) !== false;
    }

    /** @return bool Rule: value must match its confirmation field */
    protected function ruleConfirmed(string $field, mixed $v): bool
    {
        return $v === $this->getValue($field . '_confirmation');
    }

    /** @return bool Rule: minimum length (strings/arrays) or minimum value (numbers) */
    protected function ruleMin(mixed $v, int $min): bool
    {
        if (is_string($v)) return mb_strlen($v) >= $min;
        if (is_array($v))  return count($v) >= $min;
        return (float) $v >= $min;
    }

    /** @return bool Rule: maximum length (strings/arrays) or maximum value (numbers) */
    protected function ruleMax(mixed $v, int $max): bool
    {
        if (is_string($v)) return mb_strlen($v) <= $max;
        if (is_array($v))  return count($v) <= $max;
        return (float) $v <= $max;
    }

    /** @return bool Rule: value/length must be within the given range */
    protected function ruleBetween(mixed $v, float $min, float $max): bool
    {
        $val = is_string($v) ? mb_strlen($v) : (float) $v;
        return $val >= $min && $val <= $max;
    }

    /** @return bool Rule: value must be in the given list */
    protected function ruleIn(mixed $v, array $params): bool
    {
        return in_array((string) $v, $params, true);
    }

    /** @return bool Rule: value must not be in the given list */
    protected function ruleNotIn(mixed $v, array $params): bool
    {
        return !in_array((string) $v, $params, true);
    }

    /** @return bool Rule: exact length (strings/arrays) or exact value (numbers) */
    protected function ruleSize(mixed $v, int $size): bool
    {
        if (is_string($v)) return mb_strlen($v) === $size;
        if (is_array($v))  return count($v) === $size;
        return (int) $v === $size;
    }

    /** @return bool Rule: value must match the given regex pattern */
    protected function ruleRegex(mixed $v, string $pattern): bool
    {
        return (bool) preg_match($pattern, (string) $v);
    }

    /**
     * @return bool Rule: value must not already exist in the given database table/column
     *
     * Usage: unique:table,column,ignoreId
     */
    protected function ruleUnique(mixed $v, string $table, string $column, ?string $ignoreId): bool
    {
        if (empty($table)) return true;
        try {
            $pdo  = Connection::getInstance();
            $sql  = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value";
            $bind = ['value' => $v];
            if ($ignoreId !== null) {
                $sql         .= " AND `id` != :ignore";
                $bind['ignore'] = $ignoreId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            return (int) $stmt->fetchColumn() === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return bool Rule: value must exist in the given database table/column
     *
     * Usage: exists:table,column
     */
    protected function ruleExists(mixed $v, string $table, string $column): bool
    {
        if (empty($table)) return true;
        try {
            $pdo  = Connection::getInstance();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value");
            $stmt->execute(['value' => $v]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Adds a validation error for the given field and rule.
     *
     * @param string $field
     * @param string $rule
     * @param array  $params
     */
    protected function addError(string $field, string $rule, array $params): void
    {
        $customKey = "{$field}.{$rule}";
        if (isset($this->messages[$customKey])) {
            $this->errors[$field] = $this->messages[$customKey];
            return;
        }
        $this->errors[$field] = $this->defaultMessage($field, $rule, $params);
    }

    /**
     * Returns the default English error message for a rule.
     *
     * @param  string $field
     * @param  string $rule
     * @param  array  $params
     * @return string
     */
    protected function defaultMessage(string $field, string $rule, array $params): string
    {
        $label = ucfirst(str_replace('_', ' ', $field));
        return match ($rule) {
            'required'  => "The {$label} field is required.",
            'string'    => "The {$label} field must be a string.",
            'integer'   => "The {$label} field must be an integer.",
            'float'     => "The {$label} field must be a decimal number.",
            'boolean'   => "The {$label} field must be true or false.",
            'array'     => "The {$label} field must be an array.",
            'email'     => "The {$label} field must be a valid email address.",
            'url'       => "The {$label} field must be a valid URL.",
            'ip'        => "The {$label} field must be a valid IP address.",
            'uuid'      => "The {$label} field must be a valid UUID.",
            'date'      => "The {$label} field must be a valid date.",
            'alpha'     => "The {$label} field must contain only letters.",
            'alpha_num' => "The {$label} field must contain only letters and numbers.",
            'numeric'   => "The {$label} field must be numeric.",
            'confirmed' => "The {$label} confirmation does not match.",
            'min'       => "The {$label} field must be at least {$params[0]}.",
            'max'       => "The {$label} field must not exceed {$params[0]}.",
            'between'   => "The {$label} field must be between {$params[0]} and {$params[1]}.",
            'in'        => "The selected {$label} is invalid.",
            'not_in'    => "The selected {$label} is not allowed.",
            'unique'    => "The {$label} has already been taken.",
            'exists'    => "The selected {$label} does not exist.",
            'size'      => "The {$label} field must be exactly {$params[0]} characters.",
            'regex'     => "The {$label} field format is invalid.",
            default     => "The {$label} field is invalid.",
        };
    }

    /**
     * Retrieves a value from the data array, supporting dot-notation for nested keys.
     *
     * @param  string $field
     * @return mixed
     */
    protected function getValue(string $field): mixed
    {
        $keys  = explode('.', $field);
        $value = $this->data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) return null;
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * Parses a rule string into its name and parameters.
     *
     * @param  string $rule  e.g. "min:3" or "between:1,10"
     * @return array{0: string, 1: array}
     */
    protected function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) return [$rule, []];
        [$name, $paramStr] = explode(':', $rule, 2);
        return [$name, explode(',', $paramStr)];
    }
}