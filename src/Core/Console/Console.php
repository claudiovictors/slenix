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
     * Whether the current terminal supports true-color ANSI output.
     *
     * @var bool
     */
    private bool $trueColor = true;

    /**
     * Enables ANSI escape sequence support on Windows terminals.
     *
     * Modern Windows terminals support VT100 sequences, but
     * older environments require explicit activation.
     */
    public function __construct()
    {
        if (
            DIRECTORY_SEPARATOR === '\\'
            && function_exists('sapi_windows_vt100_support')
        ) {
            @sapi_windows_vt100_support(STDOUT, true);
        }

        /**
         * Detect terminal true-color support.
         */
        $this->trueColor = $this->supportsTrueColor();
    }

    /**
     * RGB color palette used by the CLI formatter.
     *
     * Each color contains an array with:
     * [red, green, blue]
     *
     * @var array<string, array<int, int>>
     */
    private array $colors = [
        'info' => [80, 170, 255],
        'success' => [80, 250, 123],
        'warning' => [255, 184, 108],
        'error' => [255, 85, 85],
        'muted' => [120, 120, 120],
        'primary' => [139, 233, 253],
        'purple' => [189, 147, 249],
        'white' => [248, 248, 242],
    ];

    /**
     * Applies a true-color (24-bit RGB) ANSI style to terminal text.
     *
     * Unlike the classic 8-color ANSI palette, this method supports
     * full RGB colors, allowing modern CLI interfaces similar to
     * Laravel, Bun, Composer and Symfony.
     *
     * Example:
     * <code>
     * echo $console->rgb('Hello', 139, 233, 253);
     * </code>
     *
     * @param string $text  The text to style.
     * @param int    $r     Red channel value (0-255).
     * @param int    $g     Green channel value (0-255).
     * @param int    $b     Blue channel value (0-255).
     * @param bool   $bold  Whether bold formatting should be applied.
     *
     * @return string Styled ANSI string ready for terminal output.
     */
    /**
     * Applies ANSI RGB color formatting to terminal text.
     *
     * Automatically falls back to standard ANSI colors when
     * true-color support is unavailable.
     *
     * @param string $text  The text to style.
     * @param int    $r     Red channel value.
     * @param int    $g     Green channel value.
     * @param int    $b     Blue channel value.
     * @param bool   $bold  Whether bold styling should be applied.
     *
     * @return string ANSI formatted text.
     */
    public function rgb(
        string $text,
        int $r,
        int $g,
        int $b,
        bool $bold = false
    ): string {
        $style = $bold ? '1' : '0';

        // Fallback for older terminals
        if (!$this->trueColor) {
            return "\033[{$style};37m{$text}\033[0m";
        }

        return "\033[{$style};38;2;{$r};{$g};{$b}m{$text}\033[0m";
    }

    /**
     * Applies RGB ANSI coloring using a named palette color.
     *
     * The method resolves the requested color from the internal
     * palette and automatically applies true-color terminal styling.
     *
     * If the color does not exist in the palette, the original
     * text is returned unchanged.
     *
     * @param string $text  The text to style.
     * @param string $color Palette color identifier.
     * @param bool   $bold  Whether bold styling should be applied.
     *
     * @return string ANSI styled string.
     */
    public function colorize(
        string $text,
        string $color,
        bool $bold = false
    ): string {
        if (!isset($this->colors[$color])) {
            return $text;
        }

        [$r, $g, $b] = $this->colors[$color];

        return $this->rgb($text, $r, $g, $b, $bold);
    }

    /**
     * Renders a muted horizontal separator line.
     *
     * Useful for visually separating sections in CLI output.
     *
     * Example:
     * <code>
     * $console->separator();
     * </code>
     *
     * @param int $width Total line width.
     *
     * @return void
     */
    public function separator(int $width = 60): void
    {
        echo $this->muted(str_repeat('-', $width)) . PHP_EOL;
    }

    /**
     * Detects whether the current terminal supports 24-bit true color.
     *
     * Modern terminals usually expose COLORTERM=truecolor.
     *
     * @return bool
     */
    private function supportsTrueColor(): bool
    {
        $colorTerm = getenv('COLORTERM');

        if ($colorTerm !== false) {
            return str_contains(strtolower($colorTerm), 'truecolor')
                || str_contains(strtolower($colorTerm), '24bit');
        }

        return DIRECTORY_SEPARATOR !== '\\';
    }

    /**
     * Displays text using the framework muted color.
     *
     * Muted text is commonly used for secondary information,
     * file paths, SQL queries and separators.
     *
     * @param string $text The text to render.
     *
     * @return string ANSI styled text.
     */
    public function muted(string $text): string
    {
        return $this->colorize($text, 'muted');
    }

    /**
     * Displays bold white terminal text.
     *
     * Intended for titles, highlighted labels and important
     * CLI messages.
     *
     * @param string $text The text to render.
     *
     * @return string ANSI styled text.
     */
    public function white(string $text): string
    {
        return $this->colorize($text, 'white', true);
    }

    /**
     * Creates a modern CLI badge with background color.
     *
     * Badges are inspired by modern terminal interfaces used by
     * frameworks such as Laravel, Symfony and Bun.
     *
     * @param string $text  Badge label text.
     * @param string $color Registered palette color.
     *
     * @return string ANSI styled badge.
     */
    public function badge(string $text, string $color): string
    {
        if (!isset($this->colors[$color])) {
            return $text;
        }

        [$r, $g, $b] = $this->colors[$color];

        return "\033[1;48;2;{$r};{$g};{$b}m"
            . "\033[38;2;255;255;255m"
            . " {$text} "
            . "\033[0m";
    }
}