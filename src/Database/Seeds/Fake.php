<?php

/*
|--------------------------------------------------------------------------
| Fake Class (Fake data generator)
|--------------------------------------------------------------------------
|
| Static helper for generating fake data in seeders and factories.
| No external dependencies — pure PHP only.
| For richer data sets, install fakerphp/faker and use directly:
|   \Faker\Factory::create('en_US')
|
*/

declare(strict_types=1);

namespace Slenix\Database\Seeds;

class Fake
{
    /**
     * @var array List of standard sample first names.
     */
    private static array $firstNames = [
        'James',
        'Mary',
        'Robert',
        'Patricia',
        'John',
        'Jennifer',
        'Michael',
        'Linda',
        'William',
        'Barbara',
        'David',
        'Elizabeth',
        'Richard',
        'Susan',
        'Joseph',
        'Jessica',
        'Thomas',
        'Sarah',
        'Charles',
        'Karen',
        'Christopher',
        'Lisa',
        'Daniel',
        'Nancy',
        'Matthew',
        'Betty',
        'Anthony',
        'Margaret',
        'Mark',
        'Sandra',
        'Donald',
        'Ashley',
        'Steven',
        'Dorothy',
        'Paul',
        'Kimberly',
        'Andrew',
        'Emily',
        'Joshua',
        'Donna',
        'Kenneth',
        'Michelle',
        'Kevin',
        'Carol',
        'Brian',
        'Amanda',
        'George',
        'Melissa',
        'Timothy',
        'Deborah',
    ];

    /**
     * @var array List of standard sample last names.
     */
    private static array $lastNames = [
        'Smith',
        'Johnson',
        'Williams',
        'Brown',
        'Jones',
        'Garcia',
        'Miller',
        'Davis',
        'Rodriguez',
        'Martinez',
        'Hernandez',
        'Lopez',
        'Gonzalez',
        'Wilson',
        'Anderson',
        'Thomas',
        'Taylor',
        'Moore',
        'Jackson',
        'Martin',
        'Lee',
        'Perez',
        'Thompson',
        'White',
        'Harris',
        'Sanchez',
        'Clark',
        'Ramirez',
        'Lewis',
        'Robinson',
    ];

    /**
     * @var array List of common public web domains.
     */
    private static array $domains = [
        'gmail.com',
        'outlook.com',
        'hotmail.com',
        'yahoo.com',
        'icloud.com',
        'proton.me',
        'example.com',
        'mail.com',
    ];

    /**
     * @var array Sample vocabulary for text composition blocks.
     */
    private static array $wordList = [
        'lorem',
        'ipsum',
        'dolor',
        'sit',
        'amet',
        'consectetur',
        'adipiscing',
        'elit',
        'sed',
        'eiusmod',
        'tempor',
        'incididunt',
        'labore',
        'magna',
        'aliqua',
        'enim',
        'veniam',
        'quis',
        'nostrud',
        'exercitation',
        'ullamco',
        'laboris',
        'nisi',
        'aliquip',
        'commodo',
        'consequat',
        'duis',
        'aute',
        'irure',
        'voluptate',
        'velit',
        'cillum',
        'fugiat',
        'nulla',
        'pariatur',
        'excepteur',
        'sint',
        'occaecat',
        'cupidatat',
        'proident',
        'culpa',
        'officia',
        'deserunt',
        'mollit',
        'anim',
        'good',
        'fine',
        'great',
    ];

    /**
     * @var array List of major cities for structural addresses.
     */
    private static array $cities = [
        'New York',
        'Los Angeles',
        'Chicago',
        'Houston',
        'Phoenix',
        'Philadelphia',
        'San Antonio',
        'San Diego',
        'Dallas',
        'San Jose',
        'Austin',
        'Jacksonville',
        'Fort Worth',
        'Columbus',
        'Charlotte',
        'Indianapolis',
        'San Francisco',
        'Seattle',
        'Denver',
        'Nashville',
    ];

    /**
     * @var array List of US state abbreviations.
     */
    private static array $states = [
        'AL',
        'AK',
        'AZ',
        'AR',
        'CA',
        'CO',
        'CT',
        'DE',
        'FL',
        'GA',
        'HI',
        'ID',
        'IL',
        'IN',
        'IA',
        'KS',
        'KY',
        'LA',
        'ME',
        'MD',
        'MA',
        'MI',
        'MN',
        'MS',
        'MO',
        'MT',
        'NE',
        'NV',
        'NH',
        'NJ',
        'NM',
        'NY',
        'NC',
        'ND',
        'OH',
        'OK',
        'OR',
        'PA',
        'RI',
        'SC',
        'SD',
        'TN',
        'TX',
        'UT',
        'VT',
        'VA',
        'WA',
        'WV',
        'WI',
        'WY',
    ];

    /**
     * @var array Common street suffix designations.
     */
    private static array $streetTypes = [
        'Street',
        'Avenue',
        'Boulevard',
        'Drive',
        'Court',
        'Place',
        'Lane',
        'Road',
        'Way',
        'Circle',
    ];

    /**
     * Generate a random first name string.
     *
     * @return string
     */
    public static function firstName(): string
    {
        return self::$firstNames[array_rand(self::$firstNames)];
    }

    /**
     * Generate a random last name string.
     *
     * @return string
     */
    public static function lastName(): string
    {
        return self::$lastNames[array_rand(self::$lastNames)];
    }

    /**
     * Generate a combined full name structure.
     *
     * @return string
     */
    public static function name(): string
    {
        return self::firstName() . ' ' . self::lastName();
    }

    /**
     * Generate a pseudo-random email address payload.
     *
     * @param string $name Optional baseline name token to build the prefix from.
     * @return string
     */
    public static function email(string $name = ''): string
    {
        if (empty($name)) {
            $name = self::firstName();
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '.', $name));
        $domain = self::$domains[array_rand(self::$domains)];
        $suffix = self::numberBetween(1, 999);

        return "{$slug}{$suffix}@{$domain}";
    }

    /**
     * Generate a formatted placeholder telephone string.
     *
     * @return string
     */
    public static function phone(): string
    {
        $area = self::numberBetween(200, 999);
        $n1 = self::numberBetween(200, 999);
        $n2 = self::numberBetween(1000, 9999);
        return "({$area}) {$n1}-{$n2}";
    }

    /**
     * Generate a random username sequence.
     *
     * @return string
     */
    public static function username(): string
    {
        $adj = ['cool', 'dark', 'fast', 'blue', 'real', 'pro', 'dev', 'ace'];
        return $adj[array_rand($adj)] . self::numberBetween(10, 9999);
    }

    /**
     * Generate an unhashed clear text random password token.
     *
     * @param int $length The targeted character length boundary.
     * @return string
     */
    public static function password(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }

    /**
     * Hash a clear text password input using the default BCRYPT algorithm.
     *
     * @param string $password Clear text password string value.
     * @return string Cryptographic hash string payload.
     */
    public static function hashedPassword(string $password = 'password'): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Generate a mocked website address URL string.
     *
     * @return string
     */
    public static function url(): string
    {
        $schemes = ['https', 'http'];
        $words = ['blog', 'site', 'app', 'web', 'dev', 'tech'];
        $tlds = ['com', 'io', 'net', 'org', 'dev'];

        return $schemes[array_rand($schemes)]
            . '://'
            . $words[array_rand($words)]
            . self::numberBetween(1, 999)
            . '.'
            . $tlds[array_rand($tlds)];
    }

    /**
     * Convert an arbitrary text block into a URL-safe lowercase slug sequence.
     *
     * @param string $text Raw text value context. If empty, generates random words.
     * @return string
     */
    public static function slug(string $text = ''): string
    {
        if (empty($text)) {
            $text = self::words(3, ' ');
        }
        $slug = strtolower($text);
        return preg_replace('/[^a-z0-9]+/', '-', trim($slug, '-'));
    }

    /**
     * Generate a structurally valid Version 4 Universally Unique Identifier (UUID).
     *
     * @return string
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Fetch a securely cryptographically secure random integer between limits.
     *
     * @param int $min Lower limit index boundary.
     * @param int $max Upper limit index boundary.
     * @return int
     */
    public static function numberBetween(int $min = 0, int $max = 9999): int
    {
        return random_int($min, $max);
    }

    /**
     * Generate a randomized floating-point float precision numerical value.
     *
     * @param float $min      Minimum decimal floor threshold.
     * @param float $max      Maximum decimal ceiling threshold.
     * @param int   $decimals Fractional precision length constraint.
     * @return float
     */
    public static function decimal(float $min = 0.01, float $max = 9999.99, int $decimals = 2): float
    {
        $value = $min + lcg_value() * ($max - $min);
        return round($value, $decimals);
    }

    /**
     * Resolve a random boolean conditional based on percentage weight probability.
     *
     * @param int $chanceOfTrue Mathematical percentage parameter indicator for matching true.
     * @return bool
     */
    public static function boolean(int $chanceOfTrue = 50): bool
    {
        return random_int(1, 100) <= $chanceOfTrue;
    }

    /**
     * Generate a date string mapping within specific strtotime criteria parameters.
     *
     * @param string $start Relative baseline starting chronological timeframe statement.
     * @param string $end   Relative baseline terminal chronological timeframe statement.
     * @return string Date formatted as 'Y-m-d'.
     */
    public static function date(string $start = '-2 years', string $end = 'now'): string
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        return date('Y-m-d', random_int($startTs, $endTs));
    }

    /**
     * Generate a full date time text layout format string block.
     *
     * @param string $start Relative baseline starting chronological timeframe statement.
     * @param string $end   Relative baseline terminal chronological timeframe statement.
     * @return string Date time string formatted as 'Y-m-d H:i:s'.
     */
    public static function dateTime(string $start = '-2 years', string $end = 'now'): string
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        return date('Y-m-d H:i:s', random_int($startTs, $endTs));
    }

    /**
     * Fetch a random integer Unix timestamp representation.
     *
     * @return int
     */
    public static function timestamp(): int
    {
        return random_int(strtotime('-5 years'), time());
    }

    /**
     * Retrieve a singular lexical random word string.
     *
     * @return string
     */
    public static function word(): string
    {
        return self::$wordList[array_rand(self::$wordList)];
    }

    /**
     * Generate a collection of random words grouped by a structural separator indicator.
     *
     * @param int    $count     Targeted number of separate words to extract.
     * @param string $separator Character tokenizer separating each structural item.
     * @return string
     */
    public static function words(int $count = 3, string $separator = ' '): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = self::word();
        }
        return implode($separator, $words);
    }

    /**
     * Form a clean randomized textual dummy sentence structure block.
     *
     * @param int $words Number of vocabulary tokens to build into the text string block.
     * @return string
     */
    public static function sentence(int $words = 8): string
    {
        return ucfirst(self::words($words, ' ')) . '.';
    }

    /**
     * Form a compound paragraph body tracking multiple separate sentence components.
     *
     * @param int $sentences Number of separate sentences to evaluate within the block.
     * @return string
     */
    public static function paragraph(int $sentences = 4): string
    {
        $paras = [];
        for ($i = 0; $i < $sentences; $i++) {
            $paras[] = self::sentence(random_int(6, 12));
        }
        return implode(' ', $paras);
    }

    /**
     * Extract a limited-length block of sentences capped at a specific maximum character size threshold.
     *
     * @param int $maxChars Maximum allowable string length allocation boundaries.
     * @return string
     */
    public static function text(int $maxChars = 200): string
    {
        $result = '';
        while (strlen($result) < $maxChars) {
            $result .= self::sentence() . ' ';
        }
        return trim(substr($result, 0, $maxChars));
    }

    /**
     * Build a capitalized pseudo random heading title text snippet string.
     *
     * @return string
     */
    public static function title(): string
    {
        return ucwords(self::words(random_int(3, 6), ' '));
    }

    /**
     * Extract a random city name string value wrapper.
     *
     * @return string
     */
    public static function city(): string
    {
        return self::$cities[array_rand(self::$cities)];
    }

    /**
     * Extract a random state metadata code identifier.
     *
     * @return string
     */
    public static function state(): string
    {
        return self::$states[array_rand(self::$states)];
    }

    /**
     * Generate a localized numerical standard zip code data entry placeholder string.
     *
     * @return string
     */
    public static function zipCode(): string
    {
        return sprintf('%05d', random_int(10000, 99999));
    }

    /**
     * Construct a multi-segment mocked physical street residential address representation line.
     *
     * @return string
     */
    public static function streetAddress(): string
    {
        return random_int(1, 9999)
            . ' '
            . ucfirst(self::word())
            . ' '
            . self::$streetTypes[array_rand(self::$streetTypes)];
    }

    /**
     * Isolate and pick a unique individual entity element out from an active flat array layout.
     *
     * @param array $array Targeted source list payload sequence.
     * @return mixed An array item value matching the parsed randomized key index choice.
     */
    public static function randomElement(array $array): mixed
    {
        return $array[array_rand($array)];
    }

    /**
     * Gather a segmented subset containing N elements returned from a source array data context.
     *
     * @param array $array Source list sequence input to split from.
     * @param int   $count Target volume index metric constraint setting limit size allocation.
     * @return array Subset collection context array.
     */
    public static function randomElements(array $array, int $count = 1): array
    {
        $keys = (array) array_rand($array, min($count, count($array)));
        $result = [];
        foreach ($keys as $key) {
            $result[] = $array[$key];
        }
        return $result;
    }
}