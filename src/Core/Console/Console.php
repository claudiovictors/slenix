<?php

/*
|--------------------------------------------------------------------------
| Console — Slenix Framework
|--------------------------------------------------------------------------
|
| This class provides utilities for formatting and styling output in the 
| terminal (CLI). It allows applying ANSI colors and styles to text 
| displayed on the command line, making framework messages more readable 
| and visually organized during development.
|
| Used internally by Celestial CLI and any command requiring formatted 
| terminal output.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

class Console
{

    /**
     * Map of color names to their respective ANSI escape codes.
     *
     * Codes follow the ANSI/VT100 standard and are compatible with most
     * modern terminals (bash, zsh, PowerShell, Windows Terminal).
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
     * Applies ANSI color and formatting to text for terminal display.
     *
     * Wraps the text with ANSI escape codes corresponding to the provided color.
     * If the color is not recognized, it returns the unformatted text.
     * The $bold parameter converts the '0;' prefix to '1;', enabling bold mode.
     *
     * @param  string $text  The text to be formatted and displayed in the terminal.
     * @param  string $color Color name (black, red, green, yellow, blue, purple, cyan, white).
     * @param  bool   $bold  Whether to apply bold styling (default: false).
     * @return string        The text with ANSI codes applied, or the original text if the color is invalid.
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