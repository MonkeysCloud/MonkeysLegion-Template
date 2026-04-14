<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Lexer;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;

/**
 * Extracts directive name and balanced arguments from a raw directive string.
 *
 * Handles nested parentheses, quoted strings, and array literals correctly.
 * This replaces the fragile regex-based argument extraction that broke on
 * expressions like `@style(['color: red' => ($count ?? 0) > 0])`.
 */
final class DirectiveTokenizer
{
    /**
     * Parsed result: the directive name.
     */
    public readonly string $name;

    /**
     * Parsed result: the raw arguments string (without outer parentheses).
     * Empty string if the directive has no arguments.
     */
    public readonly string $arguments;

    /**
     * Whether the directive had parenthesized arguments.
     */
    public readonly bool $hasArguments;

    /**
     * Parse a directive token value into its name and arguments.
     *
     * @param string $directiveValue Raw token value, e.g. "@foreach($items as $item)"
     * @param string $path           Template path for error reporting
     * @param int    $line           Line number for error reporting
     * @param int    $column         Column number for error reporting
     * @throws TemplateSyntaxException On malformed directive syntax
     */
    public function __construct(
        string $directiveValue,
        string $path = '<string>',
        int $line = 1,
        int $column = 1,
    ) {
        // Strip leading @
        $value = $directiveValue;
        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        // Strip whitespace trim marker
        if (str_starts_with($value, '-')) {
            $value = substr($value, 1);
        }

        // Extract name
        if (!preg_match('/^([a-zA-Z_]\w*)/', $value, $nameMatch)) {
            throw new TemplateSyntaxException(
                "Invalid directive syntax: could not extract name from \"{$directiveValue}\".",
                $path,
                $line,
                $column,
            );
        }

        $this->name = $nameMatch[1];
        $remainder  = substr($value, strlen($this->name));

        // Check for arguments
        if ($remainder === '' || $remainder[0] !== '(') {
            $this->arguments    = '';
            $this->hasArguments = false;
            return;
        }

        // Extract balanced arguments (strip outer parens)
        $this->arguments    = $this->extractArguments($remainder, $path, $line, $column);
        $this->hasArguments = true;
    }

    /**
     * Split arguments by comma, respecting nested parentheses and strings.
     *
     * @return string[] Individual argument strings, trimmed
     */
    public function splitArguments(): array
    {
        if ($this->arguments === '') {
            return [];
        }

        $args     = [];
        $current  = '';
        $depth    = 0;
        $inSingle = false;
        $inDouble = false;
        $escaped  = false;
        $len      = strlen($this->arguments);

        for ($i = 0; $i < $len; $i++) {
            $char = $this->arguments[$i];

            if ($escaped) {
                $current .= $char;
                $escaped  = false;
                continue;
            }

            if ($char === '\\') {
                $escaped  = true;
                $current .= $char;
                continue;
            }

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            }

            if (!$inSingle && !$inDouble) {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                }

                if ($char === ',' && $depth === 0) {
                    $args[]  = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $args[] = trim($current);
        }

        return $args;
    }

    /**
     * Get the first argument (common pattern: @directive('name')).
     *
     * Strips surrounding quotes if present.
     */
    public function getFirstArgument(): ?string
    {
        $args = $this->splitArguments();
        if (empty($args)) {
            return null;
        }

        return $this->unquote($args[0]);
    }

    /**
     * Get the second argument if present.
     *
     * Strips surrounding quotes if present.
     */
    public function getSecondArgument(): ?string
    {
        $args = $this->splitArguments();
        if (count($args) < 2) {
            return null;
        }

        return $this->unquote($args[1]);
    }

    /**
     * Extract the content between balanced outer parentheses.
     *
     * @return string Content without the outer ( and )
     */
    private function extractArguments(
        string $value,
        string $path,
        int $line,
        int $column,
    ): string {
        // Value starts with '(' — find the matching ')'
        $depth    = 0;
        $inSingle = false;
        $inDouble = false;
        $escaped  = false;
        $len      = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            }

            if (!$inSingle && !$inDouble) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        // Return content between outermost parens
                        return substr($value, 1, $i - 1);
                    }
                }
            }
        }

        throw new TemplateSyntaxException(
            'Unclosed parenthesis in directive arguments.',
            $path,
            $line,
            $column,
        );
    }

    /**
     * Strip surrounding quotes from a string value.
     */
    private function unquote(string $value): string
    {
        $value = trim($value);

        if (
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
