<?php

/*
|--------------------------------------------------------------------------
| Classe Fake (Gerador de dados falsos)
|--------------------------------------------------------------------------
|
| Helper estático para gerar dados falsos em seeders e factories.
| Não requer dependências externas — usa apenas PHP puro.
| Para projetos que queiram dados mais ricos, instale fakerphp/faker
| e use diretamente: \Faker\Factory::create('pt_BR')
|
*/

declare(strict_types=1);

namespace Slenix\Database\Seeds;

class Fake
{
    // =========================================================
    // NOMES
    // =========================================================

    private static array $firstNames = [
        'Ana', 'Bruno', 'Carlos', 'Diana', 'Eduardo', 'Fernanda',
        'Gabriel', 'Helena', 'Igor', 'Juliana', 'Kleber', 'Larissa',
        'Marcos', 'Natália', 'Otávio', 'Patrícia', 'Rafael', 'Sandra',
        'Thiago', 'Úrsula', 'Vinícius', 'Wanderley', 'Ximena', 'Yasmin',
        'João', 'Maria', 'José', 'Luiz', 'Paulo', 'Pedro', 'Antoaneta',
        'Efraim', 'Anderson', 'Vanessa', 'Preciosa', 'Cristina', 'Suzana',
        'Celma', 'Filipe', 'Julfania', 'Benilda', 'Rebecca', 'Cláudio',
        'Alice', 'Lucas', 'Mariana', 'Guilherme', 'Beatriz', 'Rodrigo',
    ];

    private static array $lastNames = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Pereira',
        'Ferreira', 'Costa', 'Rodrigues', 'Almeida', 'Nascimento', 'Carvalho',
        'Araújo', 'Gomes', 'Martins', 'Ribeiro', 'Fernandes', 'Cavalcante',
        'Barbosa', 'Melo', 'Moreira', 'Nunes', 'Cardoso', 'Teixeira',
    ];

    public static function firstName(): string
    {
        return self::$firstNames[array_rand(self::$firstNames)];
    }

    public static function lastName(): string
    {
        return self::$lastNames[array_rand(self::$lastNames)];
    }

    public static function name(): string
    {
        return self::firstName() . ' ' . self::lastName();
    }

    // =========================================================
    // CONTATOS
    // =========================================================

    private static array $domains = [
        'gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com.br',
        'icloud.com', 'proton.me', 'empresa.com.br', 'mail.com',
    ];

    public static function email(string $name = ''): string
    {
        if (empty($name)) {
            $name = self::firstName();
        }

        $slug    = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name));
        $slug    = preg_replace('/[^a-z0-9]/', '.', $slug);
        $domain  = self::$domains[array_rand(self::$domains)];
        $suffix  = self::numberBetween(1, 999);

        return "{$slug}{$suffix}@{$domain}";
    }

    public static function phone(): string
    {
        $ddd = self::numberBetween(11, 99);
        $n1  = self::numberBetween(91000, 99999);
        $n2  = self::numberBetween(1000, 9999);
        return "({$ddd}) {$n1}-{$n2}";
    }

    // =========================================================
    // INTERNET
    // =========================================================

    public static function username(): string
    {
        $adj = ['cool', 'dark', 'fast', 'blue', 'real', 'pro', 'dev', 'ace'];
        return $adj[array_rand($adj)] . self::numberBetween(10, 9999);
    }

    public static function password(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $pass  = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pass;
    }

    public static function hashedPassword(string $password = 'password'): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function url(): string
    {
        $schemes = ['https', 'http'];
        $words   = ['blog', 'site', 'app', 'web', 'dev', 'tech'];
        $tlds    = ['com', 'com.br', 'io', 'net', 'org'];

        return $schemes[array_rand($schemes)]
            . '://'
            . $words[array_rand($words)]
            . self::numberBetween(1, 999)
            . '.'
            . $tlds[array_rand($tlds)];
    }

    public static function slug(string $text = ''): string
    {
        if (empty($text)) {
            $text = self::words(3, ' ');
        }
        $slug = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $text));
        return preg_replace('/[^a-z0-9]+/', '-', trim($slug, '-'));
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // =========================================================
    // NÚMEROS
    // =========================================================

    public static function numberBetween(int $min = 0, int $max = 9999): int
    {
        return random_int($min, $max);
    }

    public static function decimal(float $min = 0.01, float $max = 9999.99, int $decimals = 2): float
    {
        $value = $min + lcg_value() * ($max - $min);
        return round($value, $decimals);
    }

    public static function boolean(int $chanceOfTrue = 50): bool
    {
        return random_int(1, 100) <= $chanceOfTrue;
    }

    // =========================================================
    // DATAS
    // =========================================================

    public static function date(string $start = '-2 years', string $end = 'now'): string
    {
        $startTs = strtotime($start);
        $endTs   = strtotime($end);
        return date('Y-m-d', random_int($startTs, $endTs));
    }

    public static function dateTime(string $start = '-2 years', string $end = 'now'): string
    {
        $startTs = strtotime($start);
        $endTs   = strtotime($end);
        return date('Y-m-d H:i:s', random_int($startTs, $endTs));
    }

    public static function timestamp(): int
    {
        return random_int(strtotime('-5 years'), time());
    }

    // =========================================================
    // TEXTO
    // =========================================================

    private static array $wordList = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur',
        'adipiscing', 'elit', 'sed', 'eiusmod', 'tempor', 'incididunt',
        'labore', 'magna', 'aliqua', 'enim', 'veniam', 'quis',
        'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip',
        'commodo', 'consequat', 'duis', 'aute', 'irure', 'voluptate',
        'velit', 'cillum', 'fugiat', 'nulla', 'pariatur', 'excepteur',
        'sint', 'occaecat', 'cupidatat', 'proident', 'culpa', 'officia',
        'deserunt', 'mollit', 'anim', 'estão', 'boas', 'palavras',
    ];

    public static function word(): string
    {
        return self::$wordList[array_rand(self::$wordList)];
    }

    public static function words(int $count = 3, string $separator = ' '): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = self::word();
        }
        return implode($separator, $words);
    }

    public static function sentence(int $words = 8): string
    {
        $sentence = self::words($words, ' ');
        return ucfirst($sentence) . '.';
    }

    public static function paragraph(int $sentences = 4): string
    {
        $paras = [];
        for ($i = 0; $i < $sentences; $i++) {
            $paras[] = self::sentence(random_int(6, 12));
        }
        return implode(' ', $paras);
    }

    public static function text(int $maxChars = 200): string
    {
        $result = '';
        while (strlen($result) < $maxChars) {
            $result .= self::sentence() . ' ';
        }
        return trim(substr($result, 0, $maxChars));
    }

    public static function title(): string
    {
        return ucwords(self::words(random_int(3, 6), ' '));
    }

    // =========================================================
    // ENDEREÇO
    // =========================================================

    private static array $cities = [
        'Luanda', 'Cabinda', 'Huambo', 'Benguela', 'Bié', 'Lubango',
        'Bengo', 'Huíla', 'Namibe', 'Malanje', 'Lunda-Sul', 'Lunda-Norte',
        'Móxico', 'Kuando Kubango', 'Cuanza Norte', 'Cuanza Sul', 'Zaire'
    ];

    private static array $states = [
        'LD', 'CB', 'HB', 'BG', 'BE', 'LG', 'BO', 'HL', 'NB', 'LS', 'LN', 'M', 'KK', 'CS',
    ];

    public static function city(): string
    {
        return self::$cities[array_rand(self::$cities)];
    }

    public static function state(): string
    {
        return self::$states[array_rand(self::$states)];
    }

    public static function zipCode(): string
    {
        return sprintf('%0244d-%09d', random_int(1000, 99999), random_int(0, 999));
    }

    public static function streetAddress(): string
    {
        $streets = ['Rua', 'Avenida', 'Travessa', 'Praça', 'Alameda'];
        return $streets[array_rand($streets)] . ' '
            . ucfirst(self::word())
            . ', '
            . random_int(1, 9999);
    }

    // =========================================================
    // ESCOLHA
    // =========================================================

    /**
     * Seleciona aleatoriamente um elemento do array.
     *
     * @example Fake::randomElement(['ativo', 'inativo', 'pendente'])
     */
    public static function randomElement(array $array): mixed
    {
        return $array[array_rand($array)];
    }

    /**
     * Seleciona N elementos aleatórios do array.
     *
     * @example Fake::randomElements(['a','b','c','d'], 2)
     */
    public static function randomElements(array $array, int $count = 1): array
    {
        $keys   = array_rand($array, min($count, count($array)));
        $keys   = (array) $keys;
        $result = [];
        foreach ($keys as $key) {
            $result[] = $array[$key];
        }
        return $result;
    }
}
