<?php

declare(strict_types=1);

namespace Slenix\Core\Console;

class Console
{
    /**
     * Cores para estilizar as saídas no terminal
     *
     * @var array
     */
    private array $colors = [
        'black' => '0;30',
        'red' => '0;31',
        'green' => '0;32',
        'yellow' => '0;33',
        'blue' => '0;34',
        'purple' => '0;35',
        'cyan' => '0;36',
        'white' => '0;37',
    ];
    
    /**
     * Aplica cores e formatação ao texto para exibição na linha de comando.
     *
     * @param string $text O texto a ser formatado.
     * @param string $color A cor do texto (black, red, green, yellow, blue, purple, cyan, white).
     * @param bool $bold Se true, aplica negrito ao texto.
     * @return string O texto formatado com códigos ANSI.
     */
    public function colorize(string $text, string $color, bool $bold = false): string
    {
        if (!isset($this->colors[$color])) {
            return $text;
        }

        $code = $this->colors[$color];
        if ($bold) {
            $code = str_replace('0;', '1;', $code);
        }

        return "\033[{$code}m{$text}\033[0m";
    }
}