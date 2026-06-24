<?php

/*
|--------------------------------------------------------------------------
| Validator Class
|--------------------------------------------------------------------------
|
| Slenix Validation Engine. Supports piped rules (|), custom messages,
| MessageBag errors, and direct request integration.
|
| Available Rules:
|   Core types   : required, string, integer, float, boolean, array, numeric
|   Format       : email, url, ip, uuid, date, alpha, alpha_num, alpha_dash
|   String       : min, max, between, size, starts_with, ends_with,
|                  contains, regex, json
|   Numbers      : gt, gte, lt, lte, digits, digits_between, multiple_of
|   Arrays       : in, not_in, distinct
|   File         : mimes, max_size (KB), dimensions (WxH)
|   DB           : unique, exists
|   Conditional  : nullable, sometimes, bail,
|                  required_if, required_unless,
|                  required_with, required_with_all,
|                  required_without, required_without_all,
|                  exclude_if, exclude_unless
|   Other        : confirmed, same, different, prohibited, accepted, declined
|
| @version 2.0.0
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

use Slenix\Database\Connection;

class Validator
{
    /** @var array<string, mixed> Data to be validated */
    protected array $data;

    /** @var array<string, string|string[]> Defined validation rules */
    protected array $rules;

    /** @var array<string, string> Custom error messages */
    protected array $messages;

    /** @var array<string, string> Custom field labels */
    protected array $labels;

    /** @var MessageBag Current validation errors */
    protected MessageBag $errors;

    /** @var array<string, mixed> Data that passed validation */
    protected array $validated = [];

    /** @var bool Stop on first failure per field (bail flag) */
    protected bool $bail = false;

    /** @var array<string, bool> Fields whose rules should be skipped when absent (sometimes) */
    protected array $sometimes = [];

    /** @var array<string, mixed> Fields to exclude from $validated */
    protected array $excluded = [];

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>          $data
     * @param array<string, string|string[]> $rules
     * @param array<string, string>          $messages
     * @param array<string, string>          $labels   Custom human-readable field names.
     */
    public function __construct(
        array $data,
        array $rules,
        array $messages = [],
        array $labels   = []
    ) {
        $this->data     = $data;
        $this->rules    = $rules;
        $this->messages = $messages;
        $this->labels   = $labels;
        $this->errors   = new MessageBag();
    }

    /**
     * Static factory.
     *
     * @param array<string, mixed>          $data
     * @param array<string, string|string[]> $rules
     * @param array<string, string>          $messages
     * @param array<string, string>          $labels
     */
    public static function make(
        array $data,
        array $rules,
        array $messages = [],
        array $labels   = []
    ): static {
        return new static($data, $rules, $messages, $labels);
    }

    // -------------------------------------------------------------------------
    // Run
    // -------------------------------------------------------------------------

    /**
     * Runs all validation rules against the data.
     *
     * @throws ValidationException
     */
    public function validate(): array
    {
        $this->errors    = new MessageBag();
        $this->validated = [];
        $this->excluded  = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules    = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $nullable = in_array('nullable', $rules, true);
            $bail     = $this->bail || in_array('bail', $rules, true);
            $value    = $this->getValue($field);

            // @sometimes — skip entirely if the key is absent from data
            if (in_array('sometimes', $rules, true) && !$this->fieldPresent($field)) {
                continue;
            }

            // Nullable short-circuit
            if ($nullable && ($value === null || $value === '')) {
                $this->validated[$field] = $value;
                continue;
            }

            $fieldFailed = false;

            foreach ($rules as $rule) {
                if (in_array($rule, ['nullable', 'bail', 'sometimes'], true)) {
                    continue;
                }

                [$ruleName, $params] = $this->parseRule($rule);

                // Handle exclude_if / exclude_unless before applying
                if ($ruleName === 'exclude_if') {
                    $other = $params[0] ?? '';
                    $val   = $params[1] ?? '';
                    if ((string) $this->getValue($other) === (string) $val) {
                        $this->excluded[$field] = true;
                    }
                    continue;
                }

                if ($ruleName === 'exclude_unless') {
                    $other = $params[0] ?? '';
                    $val   = $params[1] ?? '';
                    if ((string) $this->getValue($other) !== (string) $val) {
                        $this->excluded[$field] = true;
                    }
                    continue;
                }

                $passed = $this->applyRule($field, $value, $ruleName, $params);

                if (!$passed) {
                    $fieldFailed = true;
                    if ($bail) break;
                }
            }

            if (!$fieldFailed && !isset($this->excluded[$field])) {
                $this->validated[$field] = $value;
            }
        }

        if ($this->errors->isNotEmpty()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    /**
     * Returns true if validation fails (non-throwing).
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
     * Returns true if validation passes (non-throwing).
     */
    public function passes(): bool
    {
        return !$this->fails();
    }

    // -------------------------------------------------------------------------
    // Results
    // -------------------------------------------------------------------------

    /**
     * Returns a MessageBag with all validation errors.
     */
    public function errors(): MessageBag
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
        $result = [];
        foreach ($this->errors->toArray() as $field => $msgs) {
            $result[$field] = $msgs[0] ?? '';
        }
        return $result;
    }

    /**
     * Returns the first error message for a specific field.
     */
    public function first(string $field): ?string
    {
        $msg = $this->errors->first($field);
        return $msg !== '' ? $msg : null;
    }

    /**
     * Returns all validated data.
     */
    public function safe(): array
    {
        return $this->validated;
    }

    /**
     * Returns validated data for the given keys only.
     */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->validated, array_flip($keys));
    }

    /**
     * Returns validated data excluding the given keys.
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    // -------------------------------------------------------------------------
    // Rule Dispatcher
    // -------------------------------------------------------------------------

    protected function applyRule(string $field, mixed $value, string $rule, array $params): bool
    {
        $passed = match ($rule) {
            // Core types
            'required'             => $this->ruleRequired($value),
            'string'               => $this->ruleString($value),
            'integer'              => $this->ruleInteger($value),
            'float'                => $this->ruleFloat($value),
            'boolean'              => $this->ruleBoolean($value),
            'array'                => $this->ruleArray($value),
            'numeric'              => $this->ruleNumeric($value),

            // Format
            'email'                => $this->ruleEmail($value),
            'url'                  => $this->ruleUrl($value),
            'ip'                   => $this->ruleIp($value),
            'ipv4'                 => $this->ruleIpv4($value),
            'ipv6'                 => $this->ruleIpv6($value),
            'uuid'                 => $this->ruleUuid($value),
            'date'                 => $this->ruleDate($value),
            'date_format'          => $this->ruleDateFormat($value, $params[0] ?? 'Y-m-d'),
            'before'               => $this->ruleBefore($value, $params[0] ?? ''),
            'after'                => $this->ruleAfter($value, $params[0] ?? ''),
            'before_or_equal'      => $this->ruleBeforeOrEqual($value, $params[0] ?? ''),
            'after_or_equal'       => $this->ruleAfterOrEqual($value, $params[0] ?? ''),
            'alpha'                => $this->ruleAlpha($value),
            'alpha_num'            => $this->ruleAlphaNum($value),
            'alpha_dash'           => $this->ruleAlphaDash($value),
            'json'                 => $this->ruleJson($value),

            // String rules
            'min'                  => $this->ruleMin($value, (int) ($params[0] ?? 0)),
            'max'                  => $this->ruleMax($value, (int) ($params[0] ?? 0)),
            'between'              => $this->ruleBetween($value, (float) ($params[0] ?? 0), (float) ($params[1] ?? 0)),
            'size'                 => $this->ruleSize($value, (int) ($params[0] ?? 0)),
            'starts_with'          => $this->ruleStartsWith($value, $params),
            'ends_with'            => $this->ruleEndsWith($value, $params),
            'contains'             => $this->ruleContains($value, $params),
            'doesnt_start_with'    => !$this->ruleStartsWith($value, $params),
            'doesnt_end_with'      => !$this->ruleEndsWith($value, $params),
            'regex'                => $this->ruleRegex($value, $params[0] ?? ''),
            'not_regex'            => !$this->ruleRegex($value, $params[0] ?? ''),

            // Number comparisons
            'gt'                   => $this->ruleGt($value, $params[0] ?? '0'),
            'gte'                  => $this->ruleGte($value, $params[0] ?? '0'),
            'lt'                   => $this->ruleLt($value, $params[0] ?? '0'),
            'lte'                  => $this->ruleLte($value, $params[0] ?? '0'),
            'digits'               => $this->ruleDigits($value, (int) ($params[0] ?? 0)),
            'digits_between'       => $this->ruleDigitsBetween($value, (int) ($params[0] ?? 0), (int) ($params[1] ?? 0)),
            'multiple_of'          => $this->ruleMultipleOf($value, (float) ($params[0] ?? 1)),

            // Array
            'in'                   => $this->ruleIn($value, $params),
            'not_in'               => $this->ruleNotIn($value, $params),
            'distinct'             => $this->ruleDistinct($value),

            // Conditional required
            'required_if'          => $this->ruleRequiredIf($field, $value, $params),
            'required_unless'      => $this->ruleRequiredUnless($field, $value, $params),
            'required_with'        => $this->ruleRequiredWith($field, $value, $params),
            'required_with_all'    => $this->ruleRequiredWithAll($field, $value, $params),
            'required_without'     => $this->ruleRequiredWithout($field, $value, $params),
            'required_without_all' => $this->ruleRequiredWithoutAll($field, $value, $params),

            // Cross-field
            'confirmed'            => $this->ruleConfirmed($field, $value),
            'same'                 => $this->ruleSame($value, $this->getValue($params[0] ?? '')),
            'different'            => $this->ruleDifferent($value, $this->getValue($params[0] ?? '')),

            // Accepted / Declined
            'accepted'             => $this->ruleAccepted($value),
            'accepted_if'          => $this->ruleAcceptedIf($value, $params),
            'declined'             => $this->ruleDeclined($value),
            'prohibited'           => $this->ruleProhibited($value),
            'prohibited_if'        => $this->ruleProhibitedIf($value, $params),

            // File
            'mimes'                => $this->ruleMimes($field, $params),
            'max_size'             => $this->ruleMaxSize($field, (int) ($params[0] ?? 0)),
            'min_size'             => $this->ruleMinSize($field, (int) ($params[0] ?? 0)),
            'image'                => $this->ruleMimes($field, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']),
            'dimensions'           => $this->ruleDimensions($field, $params),

            // Database
            'unique'               => $this->ruleUnique($value, $params[0] ?? '', $params[1] ?? 'id', $params[2] ?? null),
            'exists'               => $this->ruleExists($value, $params[0] ?? '', $params[1] ?? 'id'),

            default                => true,
        };

        if (!$passed) {
            $this->addError($field, $rule, $params);
        }

        return $passed;
    }

    // ---- Core ---------------------------------------------------------------

    protected function ruleRequired(mixed $v): bool
    {
        if (is_string($v)) return trim($v) !== '';
        if (is_array($v))  return !empty($v);
        return $v !== null;
    }

    protected function ruleString(mixed $v): bool    { return is_string($v); }
    protected function ruleInteger(mixed $v): bool   { return filter_var($v, FILTER_VALIDATE_INT) !== false; }
    protected function ruleFloat(mixed $v): bool     { return filter_var($v, FILTER_VALIDATE_FLOAT) !== false; }
    protected function ruleNumeric(mixed $v): bool   { return is_numeric($v); }
    protected function ruleArray(mixed $v): bool     { return is_array($v); }

    protected function ruleBoolean(mixed $v): bool
    {
        return in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true);
    }

    // ---- Format / Type -------------------------------------------------------

    protected function ruleEmail(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function ruleUrl(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_URL) !== false;
    }

    protected function ruleIp(mixed $v): bool   { return filter_var($v, FILTER_VALIDATE_IP) !== false; }
    protected function ruleIpv4(mixed $v): bool { return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false; }
    protected function ruleIpv6(mixed $v): bool { return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false; }

    protected function ruleUuid(mixed $v): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $v
        );
    }

    protected function ruleAlpha(mixed $v): bool    { return ctype_alpha((string) $v); }
    protected function ruleAlphaNum(mixed $v): bool { return ctype_alnum((string) $v); }

    protected function ruleAlphaDash(mixed $v): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', (string) $v);
    }

    protected function ruleJson(mixed $v): bool
    {
        if (!is_string($v)) return false;
        json_decode($v);
        return json_last_error() === JSON_ERROR_NONE;
    }

    // ---- Date ---------------------------------------------------------------

    protected function ruleDate(mixed $v): bool
    {
        return strtotime((string) $v) !== false;
    }

    protected function ruleDateFormat(mixed $v, string $format): bool
    {
        $d = \DateTime::createFromFormat($format, (string) $v);
        return $d !== false && $d->format($format) === (string) $v;
    }

    protected function ruleBefore(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v);
        $d = strtotime($date);
        return $t !== false && $d !== false && $t < $d;
    }

    protected function ruleAfter(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v);
        $d = strtotime($date);
        return $t !== false && $d !== false && $t > $d;
    }

    protected function ruleBeforeOrEqual(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v);
        $d = strtotime($date);
        return $t !== false && $d !== false && $t <= $d;
    }

    protected function ruleAfterOrEqual(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v);
        $d = strtotime($date);
        return $t !== false && $d !== false && $t >= $d;
    }

    // ---- String / Length ----------------------------------------------------

    protected function ruleMin(mixed $v, int $min): bool
    {
        if (is_string($v)) return mb_strlen($v) >= $min;
        if (is_array($v))  return count($v) >= $min;
        return (float) $v >= $min;
    }

    protected function ruleMax(mixed $v, int $max): bool
    {
        if (is_string($v)) return mb_strlen($v) <= $max;
        if (is_array($v))  return count($v) <= $max;
        return (float) $v <= $max;
    }

    protected function ruleBetween(mixed $v, float $min, float $max): bool
    {
        $val = is_string($v) ? mb_strlen($v) : (is_array($v) ? count($v) : (float) $v);
        return $val >= $min && $val <= $max;
    }

    protected function ruleSize(mixed $v, int $size): bool
    {
        if (is_string($v)) return mb_strlen($v) === $size;
        if (is_array($v))  return count($v) === $size;
        return (int) $v === $size;
    }

    protected function ruleStartsWith(mixed $v, array $params): bool
    {
        $str = (string) $v;
        foreach ($params as $prefix) {
            if (str_starts_with($str, (string) $prefix)) return true;
        }
        return false;
    }

    protected function ruleEndsWith(mixed $v, array $params): bool
    {
        $str = (string) $v;
        foreach ($params as $suffix) {
            if (str_ends_with($str, (string) $suffix)) return true;
        }
        return false;
    }

    protected function ruleContains(mixed $v, array $params): bool
    {
        $str = (string) $v;
        foreach ($params as $needle) {
            if (str_contains($str, (string) $needle)) return true;
        }
        return false;
    }

    protected function ruleRegex(mixed $v, string $pattern): bool
    {
        return (bool) preg_match($pattern, (string) $v);
    }

    // ---- Number Comparisons -------------------------------------------------

    protected function ruleGt(mixed $v, string $other): bool
    {
        $cmp = is_numeric($other) ? (float) $other : (float) $this->getValue($other);
        return (float) $v > $cmp;
    }

    protected function ruleGte(mixed $v, string $other): bool
    {
        $cmp = is_numeric($other) ? (float) $other : (float) $this->getValue($other);
        return (float) $v >= $cmp;
    }

    protected function ruleLt(mixed $v, string $other): bool
    {
        $cmp = is_numeric($other) ? (float) $other : (float) $this->getValue($other);
        return (float) $v < $cmp;
    }

    protected function ruleLte(mixed $v, string $other): bool
    {
        $cmp = is_numeric($other) ? (float) $other : (float) $this->getValue($other);
        return (float) $v <= $cmp;
    }

    protected function ruleDigits(mixed $v, int $length): bool
    {
        return ctype_digit((string) $v) && strlen((string) $v) === $length;
    }

    protected function ruleDigitsBetween(mixed $v, int $min, int $max): bool
    {
        $len = strlen((string) $v);
        return ctype_digit((string) $v) && $len >= $min && $len <= $max;
    }

    protected function ruleMultipleOf(mixed $v, float $factor): bool
    {
        if ($factor == 0) return false;
        return fmod((float) $v, $factor) === 0.0;
    }

    // ---- Array --------------------------------------------------------------

    protected function ruleIn(mixed $v, array $params): bool
    {
        return in_array((string) $v, $params, true);
    }

    protected function ruleNotIn(mixed $v, array $params): bool
    {
        return !in_array((string) $v, $params, true);
    }

    protected function ruleDistinct(mixed $v): bool
    {
        if (!is_array($v)) return false;
        return count($v) === count(array_unique($v));
    }

    // ---- Conditional Required -----------------------------------------------

    protected function ruleRequiredIf(string $field, mixed $v, array $params): bool
    {
        $other = $params[0] ?? '';
        $val   = $params[1] ?? '';
        if ((string) $this->getValue($other) === (string) $val) {
            return $this->ruleRequired($v);
        }
        return true;
    }

    protected function ruleRequiredUnless(string $field, mixed $v, array $params): bool
    {
        $other  = $params[0] ?? '';
        $values = array_slice($params, 1);
        if (!in_array((string) $this->getValue($other), $values, true)) {
            return $this->ruleRequired($v);
        }
        return true;
    }

    protected function ruleRequiredWith(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if ($this->ruleRequired($this->getValue($other))) {
                return $this->ruleRequired($v);
            }
        }
        return true;
    }

    protected function ruleRequiredWithAll(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if (!$this->ruleRequired($this->getValue($other))) {
                return true;
            }
        }
        return $this->ruleRequired($v);
    }

    protected function ruleRequiredWithout(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if (!$this->ruleRequired($this->getValue($other))) {
                return $this->ruleRequired($v);
            }
        }
        return true;
    }

    protected function ruleRequiredWithoutAll(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if ($this->ruleRequired($this->getValue($other))) {
                return true;
            }
        }
        return $this->ruleRequired($v);
    }

    // ---- Cross-field --------------------------------------------------------

    protected function ruleConfirmed(string $field, mixed $v): bool
    {
        return $v === $this->getValue($field . '_confirmation');
    }

    protected function ruleSame(mixed $v, mixed $other): bool
    {
        return $v === $other;
    }

    protected function ruleDifferent(mixed $v, mixed $other): bool
    {
        return $v !== $other;
    }

    // ---- Accepted / Declined ------------------------------------------------

    protected function ruleAccepted(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    protected function ruleAcceptedIf(mixed $v, array $params): bool
    {
        $other = $params[0] ?? '';
        $val   = $params[1] ?? '';
        if ((string) $this->getValue($other) === (string) $val) {
            return $this->ruleAccepted($v);
        }
        return true;
    }

    protected function ruleDeclined(mixed $v): bool
    {
        return in_array($v, [false, 0, '0', 'false', 'no', 'off'], true);
    }

    protected function ruleProhibited(mixed $v): bool
    {
        return !$this->ruleRequired($v);
    }

    protected function ruleProhibitedIf(mixed $v, array $params): bool
    {
        $other = $params[0] ?? '';
        $val   = $params[1] ?? '';
        if ((string) $this->getValue($other) === (string) $val) {
            return $this->ruleProhibited($v);
        }
        return true;
    }

    // ---- File ---------------------------------------------------------------

    protected function ruleMimes(string $field, array $extensions): bool
    {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        return in_array($ext, $extensions, true);
    }

    protected function ruleMaxSize(string $field, int $kb): bool
    {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return true; // size check only relevant when file is present
        }
        return ($file['size'] ?? 0) <= $kb * 1024;
    }

    protected function ruleMinSize(string $field, int $kb): bool
    {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        return ($file['size'] ?? 0) >= $kb * 1024;
    }

    /**
     * Validate image dimensions.
     * Params: width=N, height=N, min_width=N, min_height=N, max_width=N, max_height=N
     */
    protected function ruleDimensions(string $field, array $params): bool
    {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }

        [$w, $h] = getimagesize($file['tmp_name']) ?: [0, 0];
        $constraints = [];
        foreach ($params as $param) {
            [$key, $val] = explode('=', $param) + ['', '0'];
            $constraints[$key] = (int) $val;
        }

        if (isset($constraints['width'])      && $w !== $constraints['width'])      return false;
        if (isset($constraints['height'])     && $h !== $constraints['height'])     return false;
        if (isset($constraints['min_width'])  && $w < $constraints['min_width'])   return false;
        if (isset($constraints['min_height']) && $h < $constraints['min_height'])  return false;
        if (isset($constraints['max_width'])  && $w > $constraints['max_width'])   return false;
        if (isset($constraints['max_height']) && $h > $constraints['max_height'])  return false;

        return true;
    }

    // ---- Database -----------------------------------------------------------

    protected function ruleUnique(mixed $v, string $table, string $column, ?string $ignoreId): bool
    {
        if (empty($table)) return true;
        try {
            $pdo  = Connection::getInstance();
            $sql  = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value";
            $bind = ['value' => $v];
            if ($ignoreId !== null) {
                $sql           .= " AND `id` != :ignore";
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

    protected function addError(string $field, string $rule, array $params): void
    {
        $customKey = "{$field}.{$rule}";

        $message = $this->messages[$customKey]
            ?? $this->messages[$rule]
            ?? $this->defaultMessage($field, $rule, $params);

        // Replace :attribute, :field, :value placeholders
        $label   = $this->labels[$field] ?? ucfirst(str_replace(['_', '.'], ' ', $field));
        $message = str_replace(':attribute', $label, $message);
        $message = str_replace(':field',     $label, $message);

        foreach ($params as $i => $param) {
            $message = str_replace(':param' . $i, (string) $param, $message);
        }

        if (!empty($params)) {
            $message = str_replace(':value', (string) ($params[0] ?? ''), $message);
        }

        $this->errors->add($field, $message);
    }

    protected function defaultMessage(string $field, string $rule, array $params): string
    {
        $label = $this->labels[$field] ?? ucfirst(str_replace(['_', '.'], ' ', $field));

        return match ($rule) {
            'required'             => "The {$label} field is required.",
            'string'               => "The {$label} field must be a string.",
            'integer'              => "The {$label} field must be an integer.",
            'float'                => "The {$label} field must be a decimal number.",
            'boolean'              => "The {$label} field must be true or false.",
            'array'                => "The {$label} field must be an array.",
            'email'                => "The {$label} field must be a valid email address.",
            'url'                  => "The {$label} field must be a valid URL.",
            'ip', 'ipv4', 'ipv6'  => "The {$label} field must be a valid IP address.",
            'uuid'                 => "The {$label} field must be a valid UUID.",
            'date'                 => "The {$label} field must be a valid date.",
            'date_format'          => "The {$label} field must match the format {$params[0]}.",
            'before'               => "The {$label} must be a date before {$params[0]}.",
            'after'                => "The {$label} must be a date after {$params[0]}.",
            'before_or_equal'      => "The {$label} must be a date before or equal to {$params[0]}.",
            'after_or_equal'       => "The {$label} must be a date after or equal to {$params[0]}.",
            'alpha'                => "The {$label} field must contain only letters.",
            'alpha_num'            => "The {$label} field must contain only letters and numbers.",
            'alpha_dash'           => "The {$label} field may only contain letters, numbers, dashes, and underscores.",
            'numeric'              => "The {$label} field must be numeric.",
            'json'                 => "The {$label} field must be a valid JSON string.",
            'confirmed'            => "The {$label} confirmation does not match.",
            'same'                 => "The {$label} and {$params[0]} must match.",
            'different'            => "The {$label} and {$params[0]} must be different.",
            'min'                  => "The {$label} must be at least {$params[0]}.",
            'max'                  => "The {$label} may not be greater than {$params[0]}.",
            'between'              => "The {$label} must be between {$params[0]} and {$params[1]}.",
            'size'                 => "The {$label} must be exactly {$params[0]}.",
            'gt'                   => "The {$label} must be greater than {$params[0]}.",
            'gte'                  => "The {$label} must be greater than or equal to {$params[0]}.",
            'lt'                   => "The {$label} must be less than {$params[0]}.",
            'lte'                  => "The {$label} must be less than or equal to {$params[0]}.",
            'digits'               => "The {$label} must be {$params[0]} digits.",
            'digits_between'       => "The {$label} must be between {$params[0]} and {$params[1]} digits.",
            'multiple_of'          => "The {$label} must be a multiple of {$params[0]}.",
            'starts_with'          => "The {$label} must start with one of: " . implode(', ', $params) . ".",
            'ends_with'            => "The {$label} must end with one of: " . implode(', ', $params) . ".",
            'doesnt_start_with'    => "The {$label} must not start with: " . implode(', ', $params) . ".",
            'doesnt_end_with'      => "The {$label} must not end with: " . implode(', ', $params) . ".",
            'contains'             => "The {$label} must contain one of: " . implode(', ', $params) . ".",
            'in'                   => "The selected {$label} is invalid.",
            'not_in'               => "The selected {$label} is not allowed.",
            'distinct'             => "The {$label} field has a duplicate value.",
            'unique'               => "The {$label} has already been taken.",
            'exists'               => "The selected {$label} does not exist.",
            'regex'                => "The {$label} field format is invalid.",
            'not_regex'            => "The {$label} field format is invalid.",
            'accepted'             => "The {$label} must be accepted.",
            'accepted_if'          => "The {$label} must be accepted when {$params[0]} is {$params[1]}.",
            'declined'             => "The {$label} must be declined.",
            'prohibited'           => "The {$label} field is prohibited.",
            'prohibited_if'        => "The {$label} field is prohibited when {$params[0]} is {$params[1]}.",
            'required_if'          => "The {$label} field is required when {$params[0]} is {$params[1]}.",
            'required_unless'      => "The {$label} field is required unless {$params[0]} is in " . implode(', ', array_slice($params, 1)) . ".",
            'required_with'        => "The {$label} field is required when " . implode(' / ', $params) . " is present.",
            'required_with_all'    => "The {$label} field is required when " . implode(', ', $params) . " are all present.",
            'required_without'     => "The {$label} field is required when " . implode(' / ', $params) . " is not present.",
            'required_without_all' => "The {$label} field is required when none of " . implode(', ', $params) . " are present.",
            'mimes'                => "The {$label} must be a file of type: " . implode(', ', $params) . ".",
            'max_size'             => "The {$label} may not be larger than {$params[0]} kilobytes.",
            'min_size'             => "The {$label} must be at least {$params[0]} kilobytes.",
            'image'                => "The {$label} must be an image.",
            'dimensions'           => "The {$label} has invalid image dimensions.",
            default                => "The {$label} field is invalid.",
        };
    }

    /**
     * Retrieves a value from the data array, supporting dot-notation for nested keys.
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
     * Checks whether a field key exists anywhere in the data (including null values).
     */
    protected function fieldPresent(string $field): bool
    {
        $keys  = explode('.', $field);
        $value = $this->data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) return false;
            $value = $value[$key];
        }
        return true;
    }

    /**
     * Parses a rule string into its name and parameters.
     * e.g. "min:3" → ['min', ['3']]
     *      "between:1,10" → ['between', ['1', '10']]
     *
     * @return array{0: string, 1: string[]}
     */
    protected function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) return [$rule, []];
        [$name, $paramStr] = explode(':', $rule, 2);
        return [$name, explode(',', $paramStr)];
    }
}