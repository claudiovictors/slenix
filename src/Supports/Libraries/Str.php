<?php

/*
|--------------------------------------------------------------------------
| Classe Str
|--------------------------------------------------------------------------
|
| Utilitários estáticos para manipulação de strings, inspirados no
| Illuminate\Support\Str do Laravel. Suporte completo a UTF-8/multibyte.
|
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

class Str
{

    // -------------------------------------------------------------------------
    // CACHE interno (memoization para conversões repetidas)
    // -------------------------------------------------------------------------

    private static array $camelCache = [];
    private static array $studlyCache = [];
    private static array $snakeCache = [];

    /**
     * Converte para camelCase.
     * Str::camel('foo_bar_baz') → 'fooBarBaz'
     */
    public static function camel(string $value): string
    {
        if (isset(self::$camelCache[$value])) {
            return self::$camelCache[$value];
        }

        return self::$camelCache[$value] = lcfirst(static::studly($value));
    }

    /**
     * Converte para StudlyCase / PascalCase.
     * Str::studly('foo_bar') → 'FooBar'
     */
    public static function studly(string $value): string
    {
        if (isset(self::$studlyCache[$value])) {
            return self::$studlyCache[$value];
        }

        $words = explode(' ', str_replace(['-', '_'], ' ', $value));

        return self::$studlyCache[$value] = implode(
            '',
            array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), $words)
        );
    }

    /**
     * Converte para snake_case.
     * Str::snake('FooBarBaz') → 'foo_bar_baz'
     * Str::snake('FooBar', '-') → 'foo-bar'
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value . $delimiter;

        if (isset(self::$snakeCache[$key])) {
            return self::$snakeCache[$key];
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', $value) ?? $value;
            $value = mb_strtolower(
                preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value) ?? $value,
                'UTF-8'
            );
        }

        return self::$snakeCache[$key] = $value;
    }

    /**
     * Converte para kebab-case.
     * Str::kebab('FooBar') → 'foo-bar'
     */
    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    /**
     * Converte para SCREAMING_SNAKE_CASE.
     * Str::screaming('fooBar') → 'FOO_BAR'
     */
    public static function screaming(string $value): string
    {
        return mb_strtoupper(static::snake($value), 'UTF-8');
    }

    /**
     * Converte para Title Case.
     * Str::title('hello world') → 'Hello World'
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Converte para letras minúsculas.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Converte para letras maiúsculas.
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Coloca apenas a primeira letra em maiúsculo.
     */
    public static function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($value, 1, null, 'UTF-8');
    }

    /**
     * Coloca apenas a primeira letra em minúsculo.
     */
    public static function lcfirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($value, 1, null, 'UTF-8');
    }

    /**
     * Inverte o case de cada caractere.
     * Str::swapCase('Hello') → 'hELLO'
     */
    public static function swapCase(string $value): string
    {
        return mb_strtolower($value) ^ mb_strtoupper($value) ^ $value;
    }

    /**
     * Gera slug URL-friendly.
     * Str::slug('Olá, Mundo!') → 'ola-mundo'
     */
    public static function slug(string $title, string $separator = '-', string $language = 'pt'): string
    {
        // Transliteração UTF-8 → ASCII
        $title = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;

        // Remove caracteres que não sejam letras, números, espaços ou separadores
        $title = preg_replace('![^' . preg_quote($separator, '!') . '\pL\pN\s]+!u', '', mb_strtolower($title)) ?? $title;

        // Substitui espaços e caracteres duplicados pelo separador
        $title = preg_replace('![' . preg_quote($separator, '!') . '\s]+!u', $separator, $title) ?? $title;

        return trim($title, $separator);
    }

    /**
     * Converte slug de volta para palavras.
     * Str::unslug('foo-bar-baz') → 'Foo Bar Baz'
     */
    public static function unslug(string $value, string $separator = '-'): string
    {
        return static::title(str_replace($separator, ' ', $value));
    }

    /**
     * Generates a cryptographically secure numeric OTP of a specific length.
     * 
     * @param int $length The desired length of the OTP.
     * @return string
     */
    public static function otp(int $length = 6): string
    {
        $max = (10 ** $length) - 1;

        try {
            $number = random_int(0, $max);
        } catch (\Exception $e) {
            $number = mt_rand(0, $max);
        }

        return str_pad((string) $number, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Generates a cryptographically secure alphanumeric code.
     * @param int $length
     * @return string
     */
    public static function generateCode(int $length = 6): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Limita a string por número de caracteres.
     * Str::limit('Hello World', 5) → 'Hello...'
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    /**
     * Limita a string por número de palavras.
     * Str::words('Hello beautiful World', 2) → 'Hello beautiful...'
     */
    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S+\s*+){1,' . $words . '}/u', $value, $matches);

        if (!isset($matches[0]) || static::length($value) === static::length($matches[0])) {
            return $value;
        }

        return rtrim($matches[0]) . $end;
    }

    /**
     * Trunca no final da palavra mais próxima.
     */
    public static function truncate(string $value, int $length, string $end = '...'): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        $truncated = mb_substr($value, 0, $length - mb_strlen($end));
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . $end;
    }

    /**
     * Padding à esquerda.
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        $short = max(0, $length - mb_strlen($value));
        return str_repeat($pad, $short) . $value;
    }

    /**
     * Padding à direita.
     */
    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        $short = max(0, $length - mb_strlen($value));
        return $value . str_repeat($pad, $short);
    }

    /**
     * Padding em ambos os lados (centralizado).
     */
    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        $short = max(0, $length - mb_strlen($value));
        $left = (int) floor($short / 2);
        $right = (int) ceil($short / 2);
        return str_repeat($pad, $left) . $value . str_repeat($pad, $right);
    }

    // =========================================================================
    // BUSCA & VERIFICAÇÃO
    // =========================================================================

    /**
     * Verifica se a string contém o valor (ou qualquer dos valores).
     */
    public static function contains(string $haystack, string|array $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack);
        }

        foreach ((array) $needles as $needle) {
            if ($ignoreCase) {
                $needle = mb_strtolower((string) $needle);
            }
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se a string contém todos os valores.
     */
    public static function containsAll(string $haystack, array $needles, bool $ignoreCase = false): bool
    {
        foreach ($needles as $needle) {
            if (!static::contains($haystack, $needle, $ignoreCase)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verifica se a string começa com o valor (ou qualquer dos valores).
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ((string) $needle !== '' && str_starts_with($haystack, (string) $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se a string termina com o valor (ou qualquer dos valores).
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ((string) $needle !== '' && str_ends_with($haystack, (string) $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se a string corresponde ao padrão (com wildcard *).
     * Str::is('*.php', 'index.php') → true
     */
    public static function is(string|array $pattern, string $value): bool
    {
        foreach ((array) $pattern as $pat) {
            $pat = preg_quote($pat, '#');
            $pat = str_replace('\*', '.*', $pat);
            if (preg_match('#^' . $pat . '\z#su', $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se a string é vazia ou contém apenas espaços.
     */
    public static function isEmpty(string $value): bool
    {
        return trim($value) === '';
    }

    /**
     * Verifica se a string não é vazia.
     */
    public static function isNotEmpty(string $value): bool
    {
        return !static::isEmpty($value);
    }

    /**
     * Validates whether the given string is a valid proper name.
     * Accepts letters, spaces, hyphens, and apostrophes (UTF-8).
     * Str::isName("Joan D'Arc Silva-Mendes") → true
     * Str::isName("Joan123") → false
     *
     * @param string $value     The string to validate.
     * @param int    $minWords  The minimum number of words required (e.g., 2 for first + last name).
     * @param int    $maxLength The maximum allowable length.
     * @return bool             True if the string is a valid name, false otherwise.
     */
    public static function isName(string $value, int $minWords = 1, int $maxLength = 100): bool
    {
        $value = trim($value);

        if (mb_strlen($value) < 2 || mb_strlen($value) > $maxLength) {
            return false;
        }

        // Only allow Unicode letters, spaces, hyphens, and apostrophes
        if (!preg_match("/^[\pL\s\-']+$/u", $value)) {
            return false;
        }

        // Verify minimum word count
        $words = array_filter(explode(' ', $value));
        if (count($words) < $minWords) {
            return false;
        }

        // Ensure no word is shorter than 2 characters (excluding specific name particles)
        $particles = ['de', 'da', 'do', 'dos', 'das', 'e', 'y', 'of', 'van', 'von', 'di', 'du'];
        foreach ($words as $word) {
            if (mb_strlen($word) < 2 && !in_array(mb_strtolower($word), $particles, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates whether the given string is a valid username.
     * Rules: Alphanumeric characters, underscores, and hyphens only. No spaces.
     * Str::isUsername('claudio_victor-98') → true
     *
     * @param string $value The string to validate.
     * @param int    $min   Minimum length constraint (default: 3).
     * @param int    $max   Maximum length constraint (default: 32).
     * @return bool         True if the string is a valid username, false otherwise.
     */
    public static function isUsername(string $value, int $min = 3, int $max = 32): bool
    {
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            return false;
        }

        // Cannot start or end with a hyphen or an underscore
        if (preg_match('/^[-_]|[-_]$/', $value)) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $value);
    }

    /**
     * Validates whether the given string is a valid phone number (International format).
     * Accepts: +244923000000, +1 (555) 000-0000, 923000000, etc.
     * Str::isPhone('+244923456789') → true
     *
     * @param string $value The string to validate.
     * @return bool         True if the string is a valid phone number, false otherwise.
     */
    public static function isPhone(string $value): bool
    {
        // Strip spaces, parentheses, hyphens, and dots to normalize the string
        $normalized = preg_replace('/[\s\-\.\(\)]/', '', $value) ?? $value;

        // International format: '+' followed by 7 to 15 digits
        if (str_starts_with($normalized, '+')) {
            return (bool) preg_match('/^\+[1-9]\d{6,14}$/', $normalized);
        }

        // Local format: 7 to 15 digits
        return (bool) preg_match('/^[1-9]\d{6,14}$/', $normalized);
    }

    /**
     * Validates whether the given string is a valid postal/zip code.
     * Supports multiple format structures per country (PT, AO, BR, US, UK, ES, FR, DE).
     * Str::isPostalCode('1000-001', 'PT') → true
     * Str::isPostalCode('10001', 'US') → true
     *
     * @param string $value   The string to validate.
     * @param string $country The ISO country code.
     * @return bool           True if the string is a valid postal code, false otherwise.
     */
    public static function isPostalCode(string $value, string $country = 'PT'): bool
    {
        $value = trim($value);

        $patterns = [
            'PT' => '/^\d{4}-\d{3}$/',                           // 1000-001
            'AO' => '/^\d{4}$/',                                 // 1234
            'BR' => '/^\d{5}-?\d{3}$/',                          // 01310-100
            'US' => '/^\d{5}(-\d{4})?$/',                        // 10001 or 10001-1234
            'UK' => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i',   // SW1A 1AA
            'ES' => '/^\d{5}$/',                                 // 28001
            'FR' => '/^\d{5}$/',                                 // 75001
            'DE' => '/^\d{5}$/',                                 // 10115
        ];

        $pattern = $patterns[strtoupper($country)] ?? '/^\d{4,10}$/';

        return (bool) preg_match($pattern, $value);
    }

    /**
     * Validates regional taxpayer identification numbers (NIF/NIT/CPF/NUIT).
     * Str::isTaxId('5417023LA041', 'AO') → true (Angola)
     *
     * @param string $value   The string to validate.
     * @param string $country The targeted country code ('AO' | 'BR' | 'PT').
     * @return bool           True if the string matches the regional tax ID constraints.
     */
    public static function isTaxId(string $value, string $country = 'AO'): bool
    {
        $value = preg_replace('/[\s\.\-\/]/', '', $value) ?? $value;

        return match (strtoupper($country)) {
            // Angola NIF: 9 to 14 alphanumeric characters
            'AO' => (bool) preg_match('/^[0-9A-Z]{9,14}$/i', $value),

            // Brazil CPF: 11 digits with verification digit calculations
            'BR' => static::isValidCpf($value),

            // Portugal NIF: 9 digits
            'PT' => (bool) preg_match('/^[0-9]{9}$/', $value),

            default => mb_strlen($value) >= 6 && mb_strlen($value) <= 20,
        };
    }

    /**
     * Validates a Brazilian CPF (Verification Digits Checksum).
     * Helper method for isTaxId().
     * 
     * @param string $cpf The normalized CPF string.
     * @return bool       True if valid, false otherwise.
     */
    private static function isValidCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf)) {
            return false;
        }

        // Reject known invalid sequential patterns (e.g., 00000000000)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        // Compute first verification digit
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cpf[$i] * (10 - $i);
        }
        $d1 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);

        // Compute second verification digit
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cpf[$i] * (11 - $i);
        }
        $d2 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);

        return (int) $cpf[9] === $d1 && (int) $cpf[10] === $d2;
    }

    /**
     * Validates whether the given string is a valid IP address (v4 or v6).
     * Str::isIp('192.168.1.1') → true
     * Str::isIp('::1', 'v6') → true
     *
     * @param string $value   The string to validate.
     * @param string $version The IP protocol version context ('v4' | 'v6' | 'any').
     * @return bool           True if valid, false otherwise.
     */
    public static function isIp(string $value, string $version = 'any'): bool
    {
        return match ($version) {
            'v4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'v6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            default => filter_var($value, FILTER_VALIDATE_IP) !== false,
        };
    }

    /**
     * Validates whether the given string is a valid MAC address.
     * Str::isMac('00:1A:2B:3C:4D:5E') → true
     * 
     * @param string $value The string to validate.
     * @return bool         True if valid, false otherwise.
     */
    public static function isMac(string $value): bool
    {
        return (bool) preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $value);
    }

    /**
     * Validates whether the given string represents a valid date.
     * Str::isDate('2026-12-31') → true
     * Str::isDate('31/12/2026', 'd/m/Y') → true
     *
     * @param string $value  The string to validate.
     * @param string $format The expected date format matching DateTime tokens.
     * @return bool          True if the date matches the structure and is valid, false otherwise.
     */
    public static function isDate(string $value, string $format = 'Y-m-d'): bool
    {
        $dt = \DateTime::createFromFormat($format, $value);
        return $dt !== false && $dt->format($format) === $value;
    }

    /**
     * Validates whether the given string is a valid time notation (HH:MM or HH:MM:SS).
     * Str::isTime('23:59') → true
     * Str::isTime('25:00') → false
     * 
     * @param string $value The string to validate.
     * @return bool         True if valid, false otherwise.
     */
    public static function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value);
    }

    /**
     * Validates whether the given string is a valid hexadecimal color representation.
     * Str::isHexColor('#FF2D20') → true
     * Str::isHexColor('FF2D20') → true
     * Str::isHexColor('#FFF') → true
     * 
     * @param string $value The string to validate.
     * @return bool         True if valid, false otherwise.
     */
    public static function isHexColor(string $value): bool
    {
        return (bool) preg_match('/^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $value);
    }

    /**
     * Validates whether a password meets specific security strength rules.
     * Str::isStrongPassword('Slenix@2026') → true
     *
     * @param string $value         The string to evaluate.
     * @param int    $minLength     Minimum character length (default: 8).
     * @param bool   $requireUpper  Enforce at least one uppercase letter.
     * @param bool   $requireNumber Enforce at least one digit character.
     * @param bool   $requireSymbol Enforce at least one special character symbol.
     * @return bool                 True if all conditions are satisfied, false otherwise.
     */
    public static function isStrongPassword(
        string $value,
        int $minLength = 8,
        bool $requireUpper = true,
        bool $requireNumber = true,
        bool $requireSymbol = true
    ): bool {
        if (mb_strlen($value) < $minLength) {
            return false;
        }
        if ($requireUpper && !preg_match('/[A-Z]/', $value)) {
            return false;
        }
        if ($requireNumber && !preg_match('/[0-9]/', $value)) {
            return false;
        }
        if ($requireSymbol && !preg_match('/[^a-zA-Z0-9]/', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Evaluates the structural complexity of a password and returns a classification string.
     * Classifications: 'weak' | 'fair' | 'strong' | 'very_strong'
     * Str::passwordStrength('Slenix@2026') → 'very_strong'
     * 
     * @param string $value The string to evaluate.
     * @return string       The complexity category string.
     */
    public static function passwordStrength(string $value): string
    {
        $score = 0;

        if (mb_strlen($value) >= 8)
            $score++;
        if (mb_strlen($value) >= 12)
            $score++;
        if (preg_match('/[A-Z]/', $value))
            $score++;
        if (preg_match('/[a-z]/', $value))
            $score++;
        if (preg_match('/[0-9]/', $value))
            $score++;
        if (preg_match('/[^a-zA-Z0-9]/', $value))
            $score++;
        if (mb_strlen($value) >= 16 && preg_match('/[^a-zA-Z0-9]/', $value))
            $score++;

        return match (true) {
            $score >= 6 => 'very_strong',
            $score >= 4 => 'strong',
            $score >= 2 => 'fair',
            default => 'weak',
        };
    }

    /**
     * Validates whether the given string is a valid URL-friendly slug pattern.
     * Str::isSlug('my-post-title') → true
     * Str::isSlug('My Post') → false
     * 
     * @param string $value The string to evaluate.
     * @return bool         True if valid, false otherwise.
     */
    public static function isSlug(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value);
    }

    /**
     * Validates whether the given string matches the structure and check digit criteria of an IBAN string.
     * Str::isIban('PT50000201231234567890154') → true
     * 
     * @param string $value The string to evaluate.
     * @return bool         True if valid, false otherwise.
     */
    public static function isIban(string $value): bool
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);

        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,}$/', $value)) {
            return false;
        }

        // Shift the first 4 characters to the end and map letters to integers
        $rearranged = substr($value, 4) . substr($value, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // Chunk split Mod 97 processing loops to safely avoid integer overflows without bcmath
        $remainder = 0;
        foreach (str_split($numeric, 9) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    /**
     * Verifica se a string é um e-mail válido.
     */
    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Verifica se a string é uma URL válida.
     */
    public static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Verifica se a string contém apenas letras.
     */
    public static function isAlpha(string $value): bool
    {
        return (bool) preg_match('/^[\pL]+$/u', $value);
    }

    /**
     * Verifica se a string contém apenas letras e números.
     */
    public static function isAlphaNum(string $value): bool
    {
        return (bool) preg_match('/^[\pL\pN]+$/u', $value);
    }

    /**
     * Verifica se a string contém apenas dígitos.
     */
    public static function isNumeric(string $value): bool
    {
        return is_numeric($value);
    }

    /**
     * Verifica se a string é um JSON válido.
     */
    public static function isJson(string $value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Verifica se a string é um UUID válido (v4).
     */
    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }


    /**
     * Retorna tudo antes da primeira ocorrência do valor.
     * Str::before('foo@bar.com', '@') → 'foo'
     */
    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = mb_strpos($subject, $search);
        return $pos === false ? $subject : mb_substr($subject, 0, $pos);
    }

    /**
     * Retorna tudo antes da última ocorrência do valor.
     */
    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = mb_strrpos($subject, $search);
        return $pos === false ? $subject : mb_substr($subject, 0, $pos);
    }

    /**
     * Retorna tudo depois da primeira ocorrência do valor.
     * Str::after('foo@bar.com', '@') → 'bar.com'
     */
    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = mb_strpos($subject, $search);
        return $pos === false ? $subject : mb_substr($subject, $pos + mb_strlen($search));
    }

    /**
     * Retorna tudo depois da última ocorrência do valor.
     */
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = mb_strrpos($subject, $search);
        return $pos === false ? $subject : mb_substr($subject, $pos + mb_strlen($search));
    }

    /**
     * Retorna o trecho entre dois valores.
     * Str::between('<b>texto</b>', '<b>', '</b>') → 'texto'
     */
    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }
        return static::beforeLast(static::after($subject, $from), $to);
    }

    /**
     * Retorna todos os trechos entre dois valores.
     */
    public static function betweenAll(string $subject, string $from, string $to): array
    {
        if ($from === '' || $to === '') {
            return [$subject];
        }

        $pattern = '/' . preg_quote($from, '/') . '(.*?)' . preg_quote($to, '/') . '/s';
        preg_match_all($pattern, $subject, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Retorna o caractere na posição especificada.
     */
    public static function charAt(string $value, int $index): string
    {
        $length = mb_strlen($value);
        if ($index < 0) {
            $index += $length;
        }
        if ($index < 0 || $index >= $length) {
            return '';
        }
        return mb_substr($value, $index, 1);
    }

    /**
     * Retorna os primeiros N caracteres.
     */
    public static function take(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }

    /**
     * Posição da primeira ocorrência (multibyte).
     */
    public static function position(string $haystack, string $needle, int $offset = 0): int|false
    {
        return mb_strpos($haystack, $needle, $offset, 'UTF-8');
    }

    // =========================================================================
    // SUBSTITUIÇÃO & MANIPULAÇÃO
    // =========================================================================

    /**
     * Substitui a primeira ocorrência.
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = strpos($subject, $search);
        return $pos !== false
            ? substr_replace($subject, $replace, $pos, strlen($search))
            : $subject;
    }

    /**
     * Substitui a última ocorrência.
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = strrpos($subject, $search);
        return $pos !== false
            ? substr_replace($subject, $replace, $pos, strlen($search))
            : $subject;
    }

    /**
     * Remove ocorrências de uma string.
     * Str::remove('!', 'Hello!') → 'Hello'
     */
    public static function remove(string|array $search, string $subject, bool $caseSensitive = true): string
    {
        return $caseSensitive
            ? str_replace($search, '', $subject)
            : str_ireplace($search, '', $subject);
    }

    /**
     * Substitui múltiplos valores usando array associativo.
     * Str::swap(['{name}' => 'Claudio'], 'Olá {name}!') → 'Olá Claudio!'
     */
    public static function swap(array $map, string $subject): string
    {
        return strtr($subject, $map);
    }

    /**
     * Adiciona prefixo se a string ainda não tiver.
     * Str::start('/foo', '/') → '/foo'
     * Str::start('foo', '/') → '/foo'
     */
    public static function start(string $value, string $prefix): string
    {
        return static::startsWith($value, $prefix) ? $value : $prefix . $value;
    }

    /**
     * Adiciona sufixo se a string ainda não tiver.
     */
    public static function finish(string $value, string $cap): string
    {
        return static::endsWith($value, $cap) ? $value : $value . $cap;
    }

    /**
     * Remove prefixo da string.
     */
    public static function stripStart(string $value, string $prefix): string
    {
        return static::startsWith($value, $prefix)
            ? mb_substr($value, mb_strlen($prefix))
            : $value;
    }

    /**
     * Remove sufixo da string.
     */
    public static function stripEnd(string $value, string $suffix): string
    {
        return static::endsWith($value, $suffix)
            ? mb_substr($value, 0, -mb_strlen($suffix))
            : $value;
    }

    /**
     * Envolve a string com outro valor.
     * Str::wrap('world', 'Hello ', '!') → 'Hello world!'
     * Str::wrap('value', '"') → '"value"'
     */
    public static function wrap(string $value, string $before, string $after = ''): string
    {
        return $before . $value . ($after !== '' ? $after : $before);
    }

    /**
     * Remove wrap se existir.
     */
    public static function unwrap(string $value, string $before, string $after = ''): string
    {
        $after = $after !== '' ? $after : $before;

        if (static::startsWith($value, $before) && static::endsWith($value, $after)) {
            return mb_substr($value, mb_strlen($before), -mb_strlen($after));
        }

        return $value;
    }

    /**
     * Repete a string N vezes.
     */
    public static function repeat(string $value, int $times): string
    {
        return str_repeat($value, $times);
    }

    /**
     * Inverte a string (multibyte).
     */
    public static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value, 1, 'UTF-8')));
    }

    /**
     * Remove espaços extras (multibyte).
     */
    public static function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    // =========================================================================
    // DIVISÃO & JUNÇÃO
    // =========================================================================

    /**
     * Divide a string e retorna um array.
     */
    public static function explode(string $separator, string $value, int $limit = PHP_INT_MAX): array
    {
        return explode($separator, $value, $limit);
    }

    /**
     * Divide por múltiplos delimitadores.
     */
    public static function splitBy(string $value, string $pattern): array
    {
        return preg_split($pattern, $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Divide a string em partes de tamanho fixo.
     */
    public static function chunk(string $value, int $size = 1): array
    {
        return mb_str_split($value, $size, 'UTF-8');
    }

    /**
     * Divide em linhas.
     */
    public static function lines(string $value): array
    {
        return preg_split('/\r\n|\r|\n/', $value) ?: [];
    }

    // =========================================================================
    // FORMATAÇÃO
    // =========================================================================

    /**
     * Formata número para exibição legível por humanos.
     * Str::number(1234567.891, 2) → '1.234.567,89'
     */
    public static function number(
        float|int $number,
        int $decimals = 0,
        string $decPoint = ',',
        string $thousandsSep = '.'
    ): string {
        return number_format($number, $decimals, $decPoint, $thousandsSep);
    }

    /**
     * Formata bytes em unidade legível.
     * Str::fileSize(1024) → '1 KB'
     */
    public static function fileSize(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[(int) $pow];
    }

    /**
     * Aplica máscara a uma string.
     * Str::mask('joao@email.com', '*', 2, 8) → 'jo********mail.com'
     */
    public static function mask(string $string, string $char, int $index, ?int $length = null): string
    {
        if ($char === '') {
            return $string;
        }

        $segment = mb_substr($string, $index, $length, 'UTF-8');

        if ($segment === '') {
            return $string;
        }

        $start = mb_substr($string, 0, $index, 'UTF-8');
        $end = mb_substr($string, $index + mb_strlen($segment, 'UTF-8'), null, 'UTF-8');
        $masked = str_repeat(mb_substr($char, 0, 1, 'UTF-8'), mb_strlen($segment, 'UTF-8'));

        return $start . $masked . $end;
    }

    /**
     * Converte para plural simples (inglês por padrão).
     * Para PT-BR, use regras personalizadas via $irregulars.
     */
    public static function plural(string $value, int $count = 2, array $irregulars = []): string
    {
        if ($count === 1) {
            return $value;
        }

        $lower = mb_strtolower($value);

        // Irregulares personalizados
        foreach ($irregulars as $singular => $plural) {
            if (mb_strtolower($singular) === $lower) {
                return static::ucfirst($plural);
            }
        }

        // Irregulares comuns PT
        $ptIrregulars = [
            'homem' => 'homens',
            'mulher' => 'mulheres',
            'mão' => 'mãos',
            'pão' => 'pães',
            'cão' => 'cães',
            'alemão' => 'alemães',
        ];

        if (isset($ptIrregulars[$lower])) {
            return static::ucfirst($ptIrregulars[$lower]);
        }

        // Regras básicas PT-BR
        if (preg_match('/(ão)$/u', $lower)) {
            return preg_replace('/(ão)$/u', 'ões', $value) ?? $value;
        }
        if (preg_match('/(al|el|ol|ul)$/u', $lower)) {
            return preg_replace('/(l)$/u', 'is', $value) ?? $value;
        }
        if (preg_match('/(r|z|n)$/u', $lower)) {
            return $value . 'es';
        }
        if (preg_match('/(s)$/u', $lower)) {
            return $value;
        }

        return $value . 's';
    }

    /**
     * Retorna singular ou plural conforme o count.
     * Str::pluralStudly('User', 1) → '1 User'
     * Str::pluralStudly('User', 3) → '3 Users'
     */
    public static function pluralStudly(string $value, int $count = 2): string
    {
        return $count . ' ' . static::plural($value, $count);
    }

    /**
     * Interpolação de variáveis na string com :placeholder.
     * Str::interpolate('Olá, :name!', ['name' => 'Claudio']) → 'Olá, Claudio!'
     */
    public static function interpolate(string $template, array $replacements, string $prefix = ':'): string
    {
        foreach ($replacements as $key => $value) {
            $template = str_replace($prefix . $key, (string) $value, $template);
        }
        return $template;
    }

    /**
     * Formata string estilo sprintf com named params.
     * Str::format('Olá {name}, tens {age} anos', ['name' => 'João', 'age' => 30])
     */
    public static function format(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }
        return $template;
    }

    // =========================================================================
    // GERAÇÃO
    // =========================================================================

    /**
     * Gera um UUID v4 aleatório.
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Gera uma string aleatória de N caracteres.
     * Str::random(32) → 'aBcDeFgH...'
     */
    public static function random(int $length = 16): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Gera token alfanumérico seguro (URL-safe base64).
     */
    public static function token(int $length = 40): string
    {
        return substr(
            str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes($length))),
            0,
            $length
        );
    }

    /**
     * Gera ULID (sortable unique ID).
     */
    public static function ulid(): string
    {
        $time = (int) (microtime(true) * 1000);
        $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $encoded = '';

        for ($i = 0; $i < 10; $i++) {
            $encoded = $chars[$time % 32] . $encoded;
            $time = (int) ($time / 32);
        }

        for ($i = 0; $i < 16; $i++) {
            $encoded .= $chars[random_int(0, 31)];
        }

        return $encoded;
    }

    /**
     * Gera senha aleatória com requisitos configuráveis.
     */
    public static function password(
        int $length = 16,
        bool $letters = true,
        bool $numbers = true,
        bool $symbols = true,
        bool $uppercase = true
    ): string {
        $pool = '';
        if ($letters)
            $pool .= 'abcdefghijklmnopqrstuvwxyz';
        if ($uppercase)
            $pool .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if ($numbers)
            $pool .= '0123456789';
        if ($symbols)
            $pool .= '!@#$%^&*()-_=+[]{}|;:,.<>?';

        if ($pool === '') {
            $pool = 'abcdefghijklmnopqrstuvwxyz0123456789';
        }

        $result = '';
        $max = strlen($pool) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $pool[random_int(0, $max)];
        }

        return $result;
    }

    // =========================================================================
    // ENCODING / SANITIZAÇÃO
    // =========================================================================

    /**
     * Escapa HTML (XSS safe).
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Desfaz escape HTML.
     */
    public static function unescape(string $value): string
    {
        return htmlspecialchars_decode($value, ENT_QUOTES);
    }

    /**
     * Codifica para uso em URL.
     */
    public static function encodeUrl(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Decodifica URL.
     */
    public static function decodeUrl(string $value): string
    {
        return rawurldecode($value);
    }

    /**
     * Codifica para base64 URL-safe.
     */
    public static function base64Encode(string $value): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($value));
    }

    /**
     * Decodifica base64 URL-safe.
     */
    public static function base64Decode(string $value): string
    {
        $value = str_replace(['-', '_'], ['+', '/'], $value);
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($value) ?: '';
    }

    /**
     * Remove tags HTML de uma string.
     */
    public static function stripTags(string $value, string|array $allowed = []): string
    {
        return strip_tags($value, $allowed);
    }

    /**
     * Remove espaços em branco de todos os lados (multibyte).
     */
    public static function trim(string $value, string $characters = " \t\n\r\0\x0B"): string
    {
        return trim($value, $characters);
    }

    /**
     * Normaliza quebras de linha para \n.
     */
    public static function normalizeLineEndings(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * Converte entidades HTML em caracteres.
     */
    public static function decodeHtml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // =========================================================================
    // MÉTRICAS
    // =========================================================================

    /**
     * Comprimento da string (multibyte).
     */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * Conta a ocorrência de uma substring.
     */
    public static function substrCount(string $haystack, string $needle): int
    {
        return substr_count($haystack, $needle);
    }

    /**
     * Conta palavras na string.
     */
    public static function wordCount(string $value): int
    {
        return str_word_count(trim($value));
    }

    /**
     * Calcula a similaridade entre duas strings (0.0 a 1.0).
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $percent);
        return round($percent / 100, 4);
    }

    /**
     * Calcula a distância de Levenshtein entre duas strings.
     */
    public static function levenshtein(string $a, string $b): int
    {
        return levenshtein($a, $b);
    }

    // =========================================================================
    // UTILITÁRIOS AVANÇADOS
    // =========================================================================

    /**
     * Aplica um callback na string e retorna o resultado.
     * Str::pipe('hello', 'strtoupper') → 'HELLO'
     */
    public static function pipe(string $value, callable ...$callbacks): string
    {
        foreach ($callbacks as $callback) {
            $value = $callback($value);
        }
        return $value;
    }

    /**
     * Executa o callback se a condição for verdadeira.
     */
    public static function when(string $value, bool $condition, callable $callback): string
    {
        return $condition ? $callback($value) : $value;
    }

    /**
     * Executa o callback se a condição for falsa.
     */
    public static function unless(string $value, bool $condition, callable $callback): string
    {
        return !$condition ? $callback($value) : $value;
    }

    /**
     * Extrai todos os matches de um regex como array.
     */
    public static function matchAll(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches);
        return $matches[1] ?? $matches[0] ?? [];
    }

    /**
     * Retorna o primeiro match de um regex.
     */
    public static function match(string $pattern, string $subject): string
    {
        preg_match($pattern, $subject, $matches);
        return $matches[1] ?? $matches[0] ?? '';
    }

    /**
     * Verifica se corresponde ao regex.
     */
    public static function test(string $pattern, string $subject): bool
    {
        return (bool) preg_match($pattern, $subject);
    }

    /**
     * Centraliza texto em uma largura com preenchimento.
     * Str::headline('hello_world_foo') → 'Hello World Foo'
     */
    public static function headline(string $value): string
    {
        $parts = preg_split('/[_\-\s]+|(?<=[a-z])(?=[A-Z])/u', $value) ?: [$value];
        return implode(' ', array_map(fn($w) => static::ucfirst(mb_strtolower($w)), array_filter($parts)));
    }

    /**
     * Converte string para array de caracteres (multibyte).
     */
    public static function split(string $value, int $chunkSize = 1): array
    {
        return mb_str_split($value, $chunkSize, 'UTF-8');
    }

    /**
     * Verifica se duas strings são iguais (constant-time, seguro para senhas).
     */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Gera hash da string com algoritmo configurável.
     * Str::hash('valor') → 'sha256:abc123...'
     */
    public static function hash(string $value, string $algorithm = 'sha256'): string
    {
        return $algorithm . ':' . hash($algorithm, $value);
    }

    /**
     * Substitui placeholders {0}, {1}... por valores.
     * Str::fmt('Olá {0}, tens {1} anos', 'João', 30)
     */
    public static function fmt(string $template, mixed ...$args): string
    {
        foreach ($args as $i => $arg) {
            $template = str_replace('{' . $i . '}', (string) $arg, $template);
        }
        return $template;
    }

    /**
     * Limpa o cache de conversões.
     */
    public static function flushCache(): void
    {
        self::$camelCache = [];
        self::$studlyCache = [];
        self::$snakeCache = [];
    }
}