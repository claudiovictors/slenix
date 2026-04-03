<?php

/*
|--------------------------------------------------------------------------
| Console — Slenix Framework
|--------------------------------------------------------------------------
|
| Esta classe fornece utilitários para formatação e estilização de saída
| no terminal (CLI). Permite aplicar cores e estilos ANSI ao texto exibido
| na linha de comando, tornando as mensagens do framework mais legíveis
| e visualmente organizadas durante o desenvolvimento.
|
| Utilizada internamente pelo Celestial CLI e por qualquer comando que
| necessite de saída formatada no terminal.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

class Console
{

    /**
     * Mapa de cores para os respectivos códigos ANSI de escape.
     *
     * Os códigos seguem o padrão ANSI/VT100 e são compatíveis com a maioria
     * dos terminais modernos (bash, zsh, PowerShell, Windows Terminal).
     *
     * @var array<string, string>
     */
    private array $colors = [
        'black'  => '0;30',
        'red'    => '0;31',
        'green'  => '0;32',
        'yellow' => '0;33',
        'blue'   => '0;34',
        'purple' => '0;35',
        'cyan'   => '0;36',
        'white'  => '0;37',
    ];

    /**
     * Aplica cor e formatação ANSI ao texto para exibição no terminal.
     *
     * Envolve o texto com os códigos de escape ANSI correspondentes à cor
     * informada. Se a cor não for reconhecida, retorna o texto sem formatação.
     * O parâmetro $bold converte o prefixo '0;' para '1;', ativando o negrito.
     *
     * Exemplo de uso:
     * ```php
     * echo $console->colorize('Sucesso!', 'green', true);
     * echo $console->colorize('[ERRO]', 'red');
     * ```
     *
     * @param  string $text  Texto a ser formatado e exibido no terminal.
     * @param  string $color Nome da cor (black, red, green, yellow, blue, purple, cyan, white).
     * @param  bool   $bold  Se true, aplica negrito ao texto (padrão: false).
     * @return string        Texto com os códigos ANSI aplicados, ou o texto original se a cor for inválida.
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