<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Lexer;

/**
 * Immutable value object representing a single token from the template lexer.
 *
 * Each token tracks its type, value, and precise position (line/column)
 * in the original source for accurate error reporting.
 */
final class Token
{
    /**
     * @param TokenType $type         The token classification
     * @param string    $value        The raw token value from the source
     * @param int       $line         1-based line number in the source
     * @param int       $column       1-based column number in the source
     * @param bool      $trimLeft     Whether to trim whitespace before this token (Jinja2-style)
     * @param bool      $trimRight    Whether to trim whitespace after this token (Jinja2-style)
     */
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column,
        public readonly bool $trimLeft = false,
        public readonly bool $trimRight = false,
    ) {}

    /**
     * Get the byte length of this token's value.
     */
    public function length(): int
    {
        return strlen($this->value);
    }

    /**
     * Check if this token is of a specific type.
     */
    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Check if this token matches a specific directive name.
     *
     * Only meaningful for DIRECTIVE tokens.
     */
    public function isDirective(string $name): bool
    {
        if ($this->type !== TokenType::DIRECTIVE) {
            return false;
        }

        // Extract directive name from value like "@if", "@foreach(...)"
        if (preg_match('/^@(\w+)/', $this->value, $m)) {
            return $m[1] === $name;
        }

        return false;
    }

    /**
     * Debug representation.
     */
    public function __toString(): string
    {
        $val = strlen($this->value) > 40
            ? substr($this->value, 0, 40) . '...'
            : $this->value;

        return sprintf(
            '%s(%s) at %d:%d',
            $this->type->value,
            json_encode($val),
            $this->line,
            $this->column,
        );
    }
}
