<?php

/*
|--------------------------------------------------------------------------
| Validator Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Slenix Validation Engine. Supports piped rules (|), custom messages,
| MessageBag errors, direct request integration, and file upload rules
| that delegate to the Upload class for security-aware validation.
|
|
| @version 2.8.0
| @package Slenix\Supports\Validation
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

use Slenix\Database\Connection;
use Slenix\Supports\Uploads\Upload;

class Validator
{
    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var array<string, mixed> Data to be validated */
    protected array $data;

    /** @var array<string, string|string[]> Defined validation rules */
    protected array $rules;

    /** @var array<string, string> Custom error messages keyed by "field.rule" or "rule" */
    protected array $messages;

    /** @var array<string, string> Human-readable field labels used in error messages */
    protected array $labels;

    /** @var MessageBag Accumulated validation errors from the current run */
    protected MessageBag $errors;

    /** @var array<string, mixed> Data that passed all validation rules */
    protected array $validated = [];

    /** @var bool Whether to stop all fields on first global failure */
    protected bool $bail = false;

    /** @var array<string, mixed> Fields marked for exclusion via exclude_if / exclude_unless */
    protected array $excluded = [];

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>           $data
     * @param array<string, string|string[]>  $rules
     * @param array<string, string>           $messages  Custom per-rule messages.
     * @param array<string, string>           $labels    Human-readable field names.
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
     * Static factory — create and return a Validator instance.
     *
     * @param array<string, mixed>           $data
     * @param array<string, string|string[]>  $rules
     * @param array<string, string>           $messages
     * @param array<string, string>           $labels
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
     * Run all validation rules against the data.
     *
     * Upload rules read from $_FILES automatically — no special handling
     * required in the caller.
     *
     * @throws ValidationException If any rule fails.
     * @return array<string, mixed> Validated (safe) data.
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

            // @sometimes — skip when the field is absent from both data and $_FILES
            if (in_array('sometimes', $rules, true)
                && !$this->fieldPresent($field)
                && !$this->hasUpload($field)
            ) {
                continue;
            }

            // Nullable short-circuit — pass through empty non-file values
            if ($nullable && ($value === null || $value === '') && !$this->hasUpload($field)) {
                $this->validated[$field] = $value;
                continue;
            }

            $fieldFailed = false;

            foreach ($rules as $rule) {
                if (in_array($rule, ['nullable', 'bail', 'sometimes'], true)) {
                    continue;
                }

                [$ruleName, $params] = $this->parseRule($rule);

                // exclude_if / exclude_unless — mark field and skip rule
                if ($ruleName === 'exclude_if') {
                    if ((string) $this->getValue($params[0] ?? '') === (string) ($params[1] ?? '')) {
                        $this->excluded[$field] = true;
                    }
                    continue;
                }

                if ($ruleName === 'exclude_unless') {
                    if ((string) $this->getValue($params[0] ?? '') !== (string) ($params[1] ?? '')) {
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

    /** Returns a MessageBag containing all validation errors. */
    public function errors(): MessageBag
    {
        return $this->errors;
    }

    /**
     * Returns the first error message for each field.
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

    /** Returns the first error for a specific field, or null. */
    public function first(string $field): ?string
    {
        $msg = $this->errors->first($field);
        return $msg !== '' ? $msg : null;
    }

    /** Returns all validated data. */
    public function safe(): array { return $this->validated; }

    /** Returns validated data for the given keys only. */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->validated, array_flip($keys));
    }

    /** Returns validated data excluding the given keys. */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    // -------------------------------------------------------------------------
    // Rule Dispatcher
    // -------------------------------------------------------------------------

    /**
     * Dispatch a single rule against a field value and record an error on failure.
     *
     * Upload rules read from $_FILES rather than $this->data, so they work
     * transparently even though file data is never part of the POST body.
     */
    protected function applyRule(string $field, mixed $value, string $rule, array $params): bool
    {
        $passed = match ($rule) {
            // ── Core types ──────────────────────────────────────────────────
            'required'             => $this->ruleRequired($field, $value),
            'string'               => $this->ruleString($value),
            'integer'              => $this->ruleInteger($value),
            'float'                => $this->ruleFloat($value),
            'boolean'              => $this->ruleBoolean($value),
            'array'                => $this->ruleArray($value),
            'numeric'              => $this->ruleNumeric($value),

            // ── Format ──────────────────────────────────────────────────────
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

            // ── String / length ──────────────────────────────────────────────
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

            // ── Number comparisons ────────────────────────────────────────────
            'gt'                   => $this->ruleGt($value, $params[0] ?? '0'),
            'gte'                  => $this->ruleGte($value, $params[0] ?? '0'),
            'lt'                   => $this->ruleLt($value, $params[0] ?? '0'),
            'lte'                  => $this->ruleLte($value, $params[0] ?? '0'),
            'digits'               => $this->ruleDigits($value, (int) ($params[0] ?? 0)),
            'digits_between'       => $this->ruleDigitsBetween($value, (int) ($params[0] ?? 0), (int) ($params[1] ?? 0)),
            'multiple_of'          => $this->ruleMultipleOf($value, (float) ($params[0] ?? 1)),

            // ── Array ─────────────────────────────────────────────────────────
            'in'                   => $this->ruleIn($value, $params),
            'not_in'               => $this->ruleNotIn($value, $params),
            'distinct'             => $this->ruleDistinct($value),

            // ── File uploads ──────────────────────────────────────────────────
            'file'                 => $this->ruleFile($field),
            'image'                => $this->ruleFileType($field, 'image'),
            'video'                => $this->ruleFileType($field, 'video'),
            'audio'                => $this->ruleFileType($field, 'audio'),
            'document'             => $this->ruleFileType($field, 'document'),
            'mimes',
            'extensions'           => $this->ruleFileMimes($field, $params),
            'mime_types'           => $this->ruleFileMimeTypes($field, $params),
            'max_kb'               => $this->ruleFileMaxKb($field, (int) ($params[0] ?? 0)),
            'min_kb'               => $this->ruleFileMinKb($field, (int) ($params[0] ?? 0)),
            'dimensions'           => $this->ruleFileDimensions($field, $params, 'exact'),
            'max_dimensions'       => $this->ruleFileDimensions($field, $params, 'max'),
            'min_dimensions'       => $this->ruleFileDimensions($field, $params, 'min'),

            // ── Conditional required ──────────────────────────────────────────
            'required_if'          => $this->ruleRequiredIf($field, $value, $params),
            'required_unless'      => $this->ruleRequiredUnless($field, $value, $params),
            'required_with'        => $this->ruleRequiredWith($field, $value, $params),
            'required_with_all'    => $this->ruleRequiredWithAll($field, $value, $params),
            'required_without'     => $this->ruleRequiredWithout($field, $value, $params),
            'required_without_all' => $this->ruleRequiredWithoutAll($field, $value, $params),

            // ── Cross-field ───────────────────────────────────────────────────
            'confirmed'            => $this->ruleConfirmed($field, $value),
            'same'                 => $this->ruleSame($value, $this->getValue($params[0] ?? '')),
            'different'            => $this->ruleDifferent($value, $this->getValue($params[0] ?? '')),

            // ── Accepted / declined ───────────────────────────────────────────
            'accepted'             => $this->ruleAccepted($value),
            'accepted_if'          => $this->ruleAcceptedIf($value, $params),
            'declined'             => $this->ruleDeclined($value),
            'prohibited'           => $this->ruleProhibited($value),
            'prohibited_if'        => $this->ruleProhibitedIf($value, $params),

            // ── Database ──────────────────────────────────────────────────────
            'unique'               => $this->ruleUnique($value, $params[0] ?? '', $params[1] ?? 'id', $params[2] ?? null),
            'exists'               => $this->ruleExists($value, $params[0] ?? '', $params[1] ?? 'id'),

            default                => true,
        };

        if (!$passed) {
            $this->addError($field, $rule, $params);
        }

        return $passed;
    }

    // =========================================================================
    // Upload Rule Implementations
    // =========================================================================

    /**
     * Resolve an Upload instance for the given field from $_FILES.
     * Returns null when no file was sent or the upload slot is empty.
     */
    protected function resolveUpload(string $field): ?Upload
    {
        $file = $_FILES[$field] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return new Upload($file);
    }

    /**
     * Return true when a non-empty file was uploaded for the given field.
     * Used by ruleRequired() and the nullable short-circuit in validate().
     */
    protected function hasUpload(string $field): bool
    {
        $file = $_FILES[$field] ?? null;
        return $file !== null
            && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && !empty($file['tmp_name']);
    }

    /**
     * Rule: 'file'
     *
     * The field must have a successfully uploaded file with no PHP upload error
     * and a valid temporary path. This is the base gate — place it before any
     * other file rule: 'required|file|image|max_kb:2048'.
     */
    protected function ruleFile(string $field): bool
    {
        $file = $_FILES[$field] ?? null;

        return $file !== null
            && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && !empty($file['tmp_name'])
            && is_uploaded_file($file['tmp_name']);
    }

    /**
     * Rule: 'image' | 'video' | 'audio' | 'document'
     *
     * Delegates to the Upload class type-detection methods, which inspect the
     * real MIME type from file content (via finfo) rather than trusting the
     * client-supplied Content-Type header, making spoofing much harder.
     */
    protected function ruleFileType(string $field, string $type): bool
    {
        $upload = $this->resolveUpload($field);

        if (!$upload) {
            return false;
        }

        return match ($type) {
            'image'    => $upload->isImage(),
            'video'    => $upload->isVideo(),
            'audio'    => $upload->isAudio(),
            'document' => $upload->isDocument(),
            default    => false,
        };
    }

    /**
     * Rule: 'mimes:{ext,ext}' | 'extensions:{ext,ext}'
     *
     * The uploaded file extension must be in the given list.
     * Case-insensitive. Combine with 'mime_types' for double enforcement.
     *
     * Example: 'file|mimes:jpg,png,webp'
     */
    protected function ruleFileMimes(string $field, array $params): bool
    {
        $upload = $this->resolveUpload($field);

        if (!$upload) {
            return false;
        }

        $allowed = array_map('strtolower', $params);
        return in_array(strtolower($upload->getExtension()), $allowed, true);
    }

    /**
     * Rule: 'mime_types:{type,type}'
     *
     * The real MIME type (read from file content) must be in the allowed list.
     * More secure than 'mimes' because it cannot be spoofed by renaming the file.
     *
     * Example: 'file|mime_types:image/jpeg,image/png'
     */
    protected function ruleFileMimeTypes(string $field, array $params): bool
    {
        $upload = $this->resolveUpload($field);

        if (!$upload) {
            return false;
        }

        return in_array($upload->getMimeType(), $params, true);
    }

    /**
     * Rule: 'max_kb:{n}'
     *
     * The uploaded file must not exceed n kilobytes.
     *
     * Example: 'file|image|max_kb:2048'  (= 2 MB)
     */
    protected function ruleFileMaxKb(string $field, int $kb): bool
    {
        $upload = $this->resolveUpload($field);
        return $upload !== null && $upload->getSize() <= $kb * 1024;
    }

    /**
     * Rule: 'min_kb:{n}'
     *
     * The uploaded file must be at least n kilobytes.
     *
     * Example: 'file|min_kb:10'
     */
    protected function ruleFileMinKb(string $field, int $kb): bool
    {
        $upload = $this->resolveUpload($field);
        return $upload !== null && $upload->getSize() >= $kb * 1024;
    }

    /**
     * Rule: 'dimensions:{w}x{h}' | 'max_dimensions:{w}x{h}' | 'min_dimensions:{w}x{h}'
     *
     * Validates image pixel dimensions. Each dimension component is optional:
     *   '800x'  — only checks width
     *   'x600'  — only checks height
     *   '800x600' — checks both
     *
     * Supported $mode values:
     *   'exact' — dimensions must match exactly
     *   'max'   — dimensions must not exceed the limit
     *   'min'   — dimensions must be at least the limit
     *
     * Example:
     *   'file|image|max_dimensions:1920x1080'
     *   'file|image|min_dimensions:100x100'
     *   'file|image|dimensions:800x600'
     */
    protected function ruleFileDimensions(string $field, array $params, string $mode): bool
    {
        $upload = $this->resolveUpload($field);

        if (!$upload || !$upload->isImage()) {
            return false;
        }

        $info = $upload->getImageInfo();

        if (!$info) {
            return false;
        }

        // Parse '{width}x{height}' — each part is optional
        $spec   = $params[0] ?? '';
        $parts  = explode('x', strtolower($spec));
        $wLimit = isset($parts[0]) && $parts[0] !== '' ? (int) $parts[0] : null;
        $hLimit = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : null;

        $w = $info['width'];
        $h = $info['height'];

        return match ($mode) {
            'exact' => ($wLimit === null || $w === $wLimit) && ($hLimit === null || $h === $hLimit),
            'max'   => ($wLimit === null || $w <= $wLimit) && ($hLimit === null || $h <= $hLimit),
            'min'   => ($wLimit === null || $w >= $wLimit) && ($hLimit === null || $h >= $hLimit),
            default => false,
        };
    }

    // =========================================================================
    // Standard Rule Implementations
    // =========================================================================

    /**
     * Rule: 'required'
     *
     * For file fields, a successful upload counts as present even though
     * file data never appears in the POST body ($this->data).
     */
    protected function ruleRequired(string $field, mixed $v): bool
    {
        if ($this->hasUpload($field)) {
            return true;
        }

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

    protected function ruleEmail(mixed $v): bool  { return filter_var($v, FILTER_VALIDATE_EMAIL) !== false; }
    protected function ruleUrl(mixed $v): bool    { return filter_var($v, FILTER_VALIDATE_URL) !== false; }
    protected function ruleIp(mixed $v): bool     { return filter_var($v, FILTER_VALIDATE_IP) !== false; }
    protected function ruleIpv4(mixed $v): bool   { return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false; }
    protected function ruleIpv6(mixed $v): bool   { return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false; }
    protected function ruleAlpha(mixed $v): bool  { return ctype_alpha((string) $v); }
    protected function ruleAlphaNum(mixed $v): bool { return ctype_alnum((string) $v); }

    protected function ruleAlphaDash(mixed $v): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', (string) $v);
    }

    protected function ruleUuid(mixed $v): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $v
        );
    }

    protected function ruleJson(mixed $v): bool
    {
        if (!is_string($v)) return false;
        json_decode($v);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function ruleDate(mixed $v): bool { return strtotime((string) $v) !== false; }

    protected function ruleDateFormat(mixed $v, string $format): bool
    {
        $d = \DateTime::createFromFormat($format, (string) $v);
        return $d !== false && $d->format($format) === (string) $v;
    }

    protected function ruleBefore(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v); $d = strtotime($date);
        return $t !== false && $d !== false && $t < $d;
    }

    protected function ruleAfter(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v); $d = strtotime($date);
        return $t !== false && $d !== false && $t > $d;
    }

    protected function ruleBeforeOrEqual(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v); $d = strtotime($date);
        return $t !== false && $d !== false && $t <= $d;
    }

    protected function ruleAfterOrEqual(mixed $v, string $date): bool
    {
        $t = strtotime((string) $v); $d = strtotime($date);
        return $t !== false && $d !== false && $t >= $d;
    }

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
        foreach ($params as $p) { if (str_starts_with((string) $v, (string) $p)) return true; }
        return false;
    }

    protected function ruleEndsWith(mixed $v, array $params): bool
    {
        foreach ($params as $p) { if (str_ends_with((string) $v, (string) $p)) return true; }
        return false;
    }

    protected function ruleContains(mixed $v, array $params): bool
    {
        foreach ($params as $p) { if (str_contains((string) $v, (string) $p)) return true; }
        return false;
    }

    protected function ruleRegex(mixed $v, string $pattern): bool
    {
        return (bool) preg_match($pattern, (string) $v);
    }

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
        return $factor != 0 && fmod((float) $v, $factor) === 0.0;
    }

    protected function ruleIn(mixed $v, array $params): bool    { return in_array((string) $v, $params, true); }
    protected function ruleNotIn(mixed $v, array $params): bool { return !in_array((string) $v, $params, true); }

    protected function ruleDistinct(mixed $v): bool
    {
        if (!is_array($v)) return false;
        return count($v) === count(array_unique($v));
    }

    protected function ruleRequiredIf(string $field, mixed $v, array $params): bool
    {
        if ((string) $this->getValue($params[0] ?? '') === (string) ($params[1] ?? '')) {
            return $this->ruleRequired($field, $v);
        }
        return true;
    }

    protected function ruleRequiredUnless(string $field, mixed $v, array $params): bool
    {
        if (!in_array((string) $this->getValue($params[0] ?? ''), array_slice($params, 1), true)) {
            return $this->ruleRequired($field, $v);
        }
        return true;
    }

    protected function ruleRequiredWith(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if ($this->ruleRequired($other, $this->getValue($other))) {
                return $this->ruleRequired($field, $v);
            }
        }
        return true;
    }

    protected function ruleRequiredWithAll(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if (!$this->ruleRequired($other, $this->getValue($other))) return true;
        }
        return $this->ruleRequired($field, $v);
    }

    protected function ruleRequiredWithout(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if (!$this->ruleRequired($other, $this->getValue($other))) {
                return $this->ruleRequired($field, $v);
            }
        }
        return true;
    }

    protected function ruleRequiredWithoutAll(string $field, mixed $v, array $params): bool
    {
        foreach ($params as $other) {
            if ($this->ruleRequired($other, $this->getValue($other))) return true;
        }
        return $this->ruleRequired($field, $v);
    }

    protected function ruleConfirmed(string $field, mixed $v): bool
    {
        return $v === $this->getValue($field . '_confirmation');
    }

    protected function ruleSame(mixed $v, mixed $other): bool      { return $v === $other; }
    protected function ruleDifferent(mixed $v, mixed $other): bool { return $v !== $other; }

    protected function ruleAccepted(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    protected function ruleAcceptedIf(mixed $v, array $params): bool
    {
        if ((string) $this->getValue($params[0] ?? '') === (string) ($params[1] ?? '')) {
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
        return !$this->ruleRequired('__prohibited__', $v);
    }

    protected function ruleProhibitedIf(mixed $v, array $params): bool
    {
        if ((string) $this->getValue($params[0] ?? '') === (string) ($params[1] ?? '')) {
            return $this->ruleProhibited($v);
        }
        return true;
    }

    /**
     * Rule: 'unique:table,column,ignoreId'
     * Value must not already exist in the given database table/column.
     */
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

    /**
     * Rule: 'exists:table,column'
     * Value must exist in the given database table/column.
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

    // =========================================================================
    // Error Handling
    // =========================================================================

    /**
     * Build and store an error message for the given field and rule.
     *
     * Priority: custom message ({field}.{rule}) → custom message ({rule}) → default.
     * Placeholders :attribute, :field, :value, :param0, :param1, … are replaced.
     */
    protected function addError(string $field, string $rule, array $params): void
    {
        $message = $this->messages["{$field}.{$rule}"]
            ?? $this->messages[$rule]
            ?? $this->defaultMessage($field, $rule, $params);

        $label   = $this->labels[$field] ?? ucfirst(str_replace(['_', '.'], ' ', $field));
        $message = str_replace([':attribute', ':field'], $label, $message);

        foreach ($params as $i => $param) {
            $message = str_replace(':param' . $i, (string) $param, $message);
        }

        if (!empty($params)) {
            $message = str_replace(':value', (string) ($params[0] ?? ''), $message);
        }

        $this->errors->add($field, $message);
    }

    /**
     * Returns the default English error message for a given rule.
     * Upload-specific messages are included alongside standard ones.
     */
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
            'numeric'              => "The {$label} field must be numeric.",
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
            'json'                 => "The {$label} field must be a valid JSON string.",
            'min'                  => "The {$label} must be at least {$params[0]}.",
            'max'                  => "The {$label} may not be greater than {$params[0]}.",
            'between'              => "The {$label} must be between {$params[0]} and {$params[1]}.",
            'size'                 => "The {$label} must be exactly {$params[0]}.",
            'starts_with'          => "The {$label} must start with one of: " . implode(', ', $params) . ".",
            'ends_with'            => "The {$label} must end with one of: " . implode(', ', $params) . ".",
            'doesnt_start_with'    => "The {$label} must not start with: " . implode(', ', $params) . ".",
            'doesnt_end_with'      => "The {$label} must not end with: " . implode(', ', $params) . ".",
            'contains'             => "The {$label} must contain one of: " . implode(', ', $params) . ".",
            'regex', 'not_regex'   => "The {$label} field format is invalid.",
            'gt'                   => "The {$label} must be greater than {$params[0]}.",
            'gte'                  => "The {$label} must be greater than or equal to {$params[0]}.",
            'lt'                   => "The {$label} must be less than {$params[0]}.",
            'lte'                  => "The {$label} must be less than or equal to {$params[0]}.",
            'digits'               => "The {$label} must be exactly {$params[0]} digits.",
            'digits_between'       => "The {$label} must be between {$params[0]} and {$params[1]} digits.",
            'multiple_of'          => "The {$label} must be a multiple of {$params[0]}.",
            'in'                   => "The selected {$label} is invalid.",
            'not_in'               => "The selected {$label} is not allowed.",
            'distinct'             => "The {$label} field has a duplicate value.",

            // Upload
            'file'                 => "The {$label} must be an uploaded file.",
            'image'                => "The {$label} must be an image (jpeg, png, gif, webp, bmp, svg).",
            'video'                => "The {$label} must be a video file.",
            'audio'                => "The {$label} must be an audio file.",
            'document'             => "The {$label} must be a document (pdf, doc, docx, xls, xlsx, txt, csv).",
            'mimes', 'extensions'  => "The {$label} must be a file of type: " . implode(', ', $params) . ".",
            'mime_types'           => "The {$label} must have one of the following MIME types: " . implode(', ', $params) . ".",
            'max_kb'               => "The {$label} may not be larger than {$params[0]} KB.",
            'min_kb'               => "The {$label} must be at least {$params[0]} KB.",
            'dimensions'           => "The {$label} must be exactly {$params[0]} pixels.",
            'max_dimensions'       => "The {$label} image must not exceed {$params[0]} pixels.",
            'min_dimensions'       => "The {$label} image must be at least {$params[0]} pixels.",

            'confirmed'            => "The {$label} confirmation does not match.",
            'same'                 => "The {$label} and {$params[0]} must match.",
            'different'            => "The {$label} and {$params[0]} must be different.",
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
            'unique'               => "The {$label} has already been taken.",
            'exists'               => "The selected {$label} does not exist.",

            default                => "The {$label} field is invalid.",
        };
    }

    /**
     * Retrieve a value from the data array using dot-notation for nested keys.
     * Returns null when any segment along the path does not exist.
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
     * Check whether a field key exists in the data array (including null values).
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
     * Parse a rule string into its name and parameters array.
     *
     * Examples:
     *   'min:3'          → ['min',     ['3']]
     *   'between:1,10'   → ['between', ['1', '10']]
     *   'mimes:jpg,png'  → ['mimes',   ['jpg', 'png']]
     *   'dimensions:800x600' → ['dimensions', ['800x600']]
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