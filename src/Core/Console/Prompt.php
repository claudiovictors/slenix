<?php

/*
|--------------------------------------------------------------------------
| Prompt Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Provides  interactive terminal prompts with styled boxes,
| text inputs and single/multi-select menus using ANSI escape codes.
|
| Supports: text input, select (arrow-key navigation), confirm (y/n).
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

class Prompt
{
    /**
     * Width of the prompt box (inner content area).
     *
     * @var int
     */
    private const BOX_WIDTH = 62;

    /**
     * Console helper instance for ANSI styling.
     *
     * @var Console
     */
    private Console $c;

    public function __construct()
    {
        $this->c = new Console();

        // Enable raw terminal mode so we can read arrow keys.
        $this->enableRawMode();
    }

    public function __destruct()
    {
        $this->disableRawMode();
    }

    /**
     * Renders a styled text-input prompt and returns the entered value.
     *
     * Example:
     *   ┌ What should the controller be named? ─────────────────┐
     *   │ E.g. UserController                                   │
     *   └───────────────────────────────────────────────────────┘
     *
     * @param string $question  The question label shown in the top border.
     * @param string $placeholder Placeholder / hint text shown inside the box.
     * @param string $default   Default value returned when user presses Enter.
     *
     * @return string The value entered by the user (trimmed).
     */
    public function text(
        string $question,
        string $placeholder = '',
        string $default = ''
    ): string {
        echo PHP_EOL;

        $this->renderBoxTop($question);
        $this->renderBoxRow($this->c->muted($placeholder ?: ($default ? "Default: {$default}" : '')));
        $this->renderBoxBottom();

        // Move cursor up 2 lines into the input row and position after '│ '
        echo "\033[2A\033[2C";

        // Restore normal line mode for fgets
        $this->disableRawMode();

        $input = $this->readLine();

        $this->enableRawMode();

        // Move past the box bottom
        echo "\033[1B";
        echo PHP_EOL;

        $value = trim($input);

        return $value !== '' ? $value : $default;
    }

    /**
     * Renders a styled single-select prompt with arrow-key navigation.
     *
     * Example:
     *   ┌ Which type of controller would you like? ─────────────┐
     *   │ › ● Empty                                             │
     *   │   ○ Resource                                          │
     *   └───────────────────────────────────────────────────────┘
     *
     * @param string        $question The question label in the top border.
     * @param array<string> $options  List of option labels.
     * @param int           $default  Index of the default selected option.
     *
     * @return string The label of the chosen option.
     */
    public function select(
        string $question,
        array  $options,
        int    $default = 0
    ): string {
        $selected = max(0, min($default, count($options) - 1));
        $count    = count($options);

        echo PHP_EOL;

        $this->renderSelect($question, $options, $selected);

        while (true) {
            $key = $this->readKey();

            if ($key === 'UP') {
                $selected = ($selected - 1 + $count) % $count;
            } elseif ($key === 'DOWN') {
                $selected = ($selected + 1) % $count;
            } elseif ($key === 'ENTER') {
                break;
            }

            // Clear the rendered box and redraw
            $lines = $count + 2; // top + options + bottom
            echo "\033[{$lines}A";
            $this->renderSelect($question, $options, $selected);
        }

        echo PHP_EOL;

        return $options[$selected];
    }

    /**
     * Renders a yes/no confirmation prompt.
     *
     * Example:
     *   ┌ Are you sure? ────────────────────────────────────────┐
     *   │ Yes / No                                              │
     *   └───────────────────────────────────────────────────────┘
     *
     * @param string $question The question label in the top border.
     * @param bool   $default  Default value when user presses Enter.
     *
     * @return bool
     */
    public function confirm(string $question, bool $default = true): bool
    {
        $options = ['Yes', 'No'];
        $choice  = $this->select($question, $options, $default ? 0 : 1);

        return $choice === 'Yes';
    }

    /**
     * Renders the top border with the question embedded.
     *
     * @param string $question Label text.
     *
     * @return void
     */
    private function renderBoxTop(string $question): void
    {
        $label    = " {$question} ";
        $labelLen = mb_strlen($label);
        $dashes   = self::BOX_WIDTH - $labelLen;
        $right    = $dashes > 0 ? str_repeat('─', $dashes) : '';

        echo $this->c->muted('┌')
            . $this->c->white($label)
            . $this->c->muted($right . '┐')
            . PHP_EOL;
    }

    /**
     * Renders a single content row inside the box.
     *
     * @param string $content Already-styled content string.
     *
     * @return void
     */
    private function renderBoxRow(string $content): void
    {
        $visible = $this->stripAnsi($content);
        $pad     = max(0, self::BOX_WIDTH - mb_strlen($visible) - 1);

        echo $this->c->muted('│')
            . ' '
            . $content
            . str_repeat(' ', $pad)
            . $this->c->muted('│')
            . PHP_EOL;
    }

    /**
     * Renders the bottom border of the box.
     *
     * @return void
     */
    private function renderBoxBottom(): void
    {
        echo $this->c->muted('└' . str_repeat('─', self::BOX_WIDTH) . '┘') . PHP_EOL;
    }

    /**
     * Renders the full select widget.
     *
     * @param string        $question Label.
     * @param array<string> $options  Options.
     * @param int           $selected Currently highlighted index.
     *
     * @return void
     */
    private function renderSelect(string $question, array $options, int $selected): void
    {
        $this->renderBoxTop($question);

        foreach ($options as $i => $option) {
            if ($i === $selected) {
                $bullet = $this->c->colorize('›', 'primary', true)
                    . ' '
                    . $this->c->colorize('●', 'primary')
                    . ' '
                    . $this->c->white($option);
            } else {
                $bullet = $this->c->muted('  ○ ' . $option);
            }

            $this->renderBoxRow($bullet);
        }

        $this->renderBoxBottom();
    }


    /**
     * Reads a single line from STDIN (normal line-buffered mode).
     *
     * @return string
     */
    private function readLine(): string
    {
        $line = fgets(STDIN);

        return $line !== false ? $line : '';
    }

    /**
     * Reads a single keypress or escape sequence from STDIN.
     *
     * Returns one of: 'UP', 'DOWN', 'ENTER', or the raw character.
     *
     * @return string
     */
    private function readKey(): string
    {
        $ch = fread(STDIN, 1);

        if ($ch === false || $ch === '') {
            return '';
        }

        // Escape sequence — read the rest
        if ($ch === "\033") {
            $next = fread(STDIN, 1);

            if ($next === '[') {
                $code = fread(STDIN, 1);

                return match ($code) {
                    'A' => 'UP',
                    'B' => 'DOWN',
                    'C' => 'RIGHT',
                    'D' => 'LEFT',
                    default => $ch . $next . $code,
                };
            }

            return $ch . $next;
        }

        // Enter key
        if ($ch === "\n" || $ch === "\r") {
            return 'ENTER';
        }

        return $ch;
    }

    /**
     * Switches the terminal to raw (unbuffered, no-echo) mode.
     *
     * Only applies on Unix-like systems. On Windows this is a no-op.
     *
     * @return void
     */
    private function enableRawMode(): void
    {
        if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
            shell_exec('stty -icanon -echo 2>/dev/null');
        }
    }

    /**
     * Restores the terminal to its default (cooked) mode.
     *
     * @return void
     */
    private function disableRawMode(): void
    {
        if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
            shell_exec('stty sane 2>/dev/null');
        }
    }

    /**
     * Strips ANSI escape sequences from a string to measure its visible length.
     *
     * @param string $text Input string (may contain ANSI codes).
     *
     * @return string Plain-text version.
     */
    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}