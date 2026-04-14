<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Lexer;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;

/**
 * Template lexer / tokenizer for MLView templates.
 *
 * Performs character-level scanning to produce a `Token[]` stream with
 * accurate line/column tracking. Validates balanced delimiters and
 * matched block directives at tokenization time.
 *
 * Features:
 * - Parenthesis-balanced argument extraction (fixes nested-paren bug)
 * - Whitespace control markers (Jinja2/Go-style: {{- -}}, @-directive)
 * - Validates matched @if/@endif, @foreach/@endforeach, etc.
 * - Throws TemplateSyntaxException on any validation failure
 */
final class Lexer
{
    /** @var string Full template source */
    private string $source;

    /** @var int Current byte offset in source */
    private int $pos;

    /** @var int Source byte length */
    private int $length;

    /** @var int Current 1-based line number */
    private int $line;

    /** @var int Current 1-based column number */
    private int $col;

    /** @var string Template file path (for error reporting) */
    private string $path;

    /** Block directives that require matching end tags */
    private const array BLOCK_DIRECTIVES = [
        'if'           => 'endif',
        'elseif'       => null,    // continuation, not standalone opener
        'else'         => null,    // continuation
        'foreach'      => 'endforeach',
        'forelse'      => 'endforelse',
        'for'          => 'endfor',
        'while'        => 'endwhile',
        'switch'       => 'endswitch',
        'section'      => 'endsection',
        'once'         => 'endonce',
        'verbatim'     => 'endverbatim',
        'php'          => 'endphp',
        'env'          => 'endenv',
        'auth'         => 'endauth',
        'guest'        => 'endguest',
        'error'        => 'enderror',
        'can'          => 'endcan',
        'cannot'       => 'endcannot',
        'hasSection'   => 'endhasSection',
        'sectionMissing' => 'endsectionMissing',
        'production'   => 'endproduction',
        'fragment'     => 'endfragment',
        'teleport'     => 'endteleport',
        'persist'      => 'endpersist',
        'session'      => 'endsession',
        'autoescape'   => 'endautoescape',
        'push'         => 'endpush',
        'pushOnce'     => 'endPushOnce',
        'prepend'      => 'endprepend',
        'macro'        => 'endmacro',
    ];

    /**
     * Tokenize a template source string into a stream of tokens.
     *
     * @param string $source Template source code
     * @param string $path   Template file path (for error messages)
     * @return Token[]        Array of tokens
     * @throws TemplateSyntaxException On syntax errors
     */
    public function tokenize(string $source, string $path = '<string>'): array
    {
        $this->source = $source;
        $this->pos    = 0;
        $this->length = strlen($source);
        $this->line   = 1;
        $this->col    = 1;
        $this->path   = $path;

        /** @var Token[] $tokens */
        $tokens = [];
        $textBuffer = '';
        $textLine   = $this->line;
        $textCol    = $this->col;

        while ($this->pos < $this->length) {
            // Check for comment {{-- ... --}}
            if ($this->lookAhead('{{--')) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(TokenType::TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens[] = $this->readComment();
                $textLine = $this->line;
                $textCol  = $this->col;
                continue;
            }

            // Check for raw echo {!! ... !!}
            if ($this->lookAhead('{!!')) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(TokenType::TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens = array_merge($tokens, $this->readRawEcho());
                $textLine = $this->line;
                $textCol  = $this->col;
                continue;
            }

            // Check for escaped echo {{ ... }}
            if ($this->lookAhead('{{')) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(TokenType::TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens = array_merge($tokens, $this->readEscapedEcho());
                $textLine = $this->line;
                $textCol  = $this->col;
                continue;
            }

            // Check for component tags <x-name> or </x-name> or <x-name />
            if ($this->lookAhead('<x-') || $this->lookAhead('</x-')) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(TokenType::TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens[] = $this->readComponentTag();
                $textLine = $this->line;
                $textCol  = $this->col;
                continue;
            }

            // Check for escaped @@ (literal @)
            if ($this->lookAhead('@@')) {
                $textBuffer .= '@';
                $this->advance(2);
                continue;
            }

            // Check for directives @name or @name(...)
            if ($this->lookAhead('@') && $this->isDirectiveStart()) {
                if ($textBuffer !== '') {
                    $tokens[]   = new Token(TokenType::TEXT, $textBuffer, $textLine, $textCol);
                    $textBuffer = '';
                }
                $tokens[] = $this->readDirective();
                $textLine = $this->line;
                $textCol  = $this->col;
                continue;
            }

            // Regular text character
            $char = $this->source[$this->pos];
            $textBuffer .= $char;
            $this->advance(1);
        }

        // Flush remaining text
        if ($textBuffer !== '') {
            $tokens[] = new Token(TokenType::TEXT, $textBuffer, $textLine, $textCol);
        }

        // Add EOF
        $tokens[] = new Token(TokenType::EOF, '', $this->line, $this->col);

        return $tokens;
    }

    /**
     * Validate block directive matching (e.g. every @if has an @endif).
     *
     * @param Token[] $tokens Token stream to validate
     * @throws TemplateSyntaxException On mismatched block directives
     */
    public function validateBlockDirectives(array $tokens): void
    {
        /** @var array<int, array{name: string, line: int, col: int}> $stack */
        $stack = [];

        foreach ($tokens as $token) {
            if ($token->type !== TokenType::DIRECTIVE) {
                continue;
            }

            $name = $this->extractDirectiveName($token->value);

            if ($name === null) {
                continue;
            }

            // Is this an opening block directive?
            if (array_key_exists($name, self::BLOCK_DIRECTIVES) && self::BLOCK_DIRECTIVES[$name] !== null) {
                // Special case: @section shorthand doesn't need @endsection
                if ($name === 'section' && $this->isSectionShorthand($token->value)) {
                    continue;
                }

                $stack[] = ['name' => $name, 'line' => $token->line, 'col' => $token->column];
                continue;
            }

            // Is this a closing block directive?
            $closerFor = $this->findOpenerForCloser($name);

            if ($closerFor !== null) {
                if (empty($stack)) {
                    throw new TemplateSyntaxException(
                        "Unexpected @{$name} without matching @{$closerFor}.",
                        $this->path,
                        $token->line,
                        $token->column,
                        $this->source,
                    );
                }

                $opener = array_pop($stack);

                if ($opener['name'] !== $closerFor) {
                    $expected = self::BLOCK_DIRECTIVES[$opener['name']];
                    throw new TemplateSyntaxException(
                        "Expected @{$expected} to close @{$opener['name']} (opened on line {$opener['line']}), but found @{$name}.",
                        $this->path,
                        $token->line,
                        $token->column,
                        $this->source,
                    );
                }
            }
        }

        // Check for unclosed blocks
        if (!empty($stack)) {
            $unclosed = array_pop($stack);
            $expected = self::BLOCK_DIRECTIVES[$unclosed['name']] ?? 'end' . $unclosed['name'];
            throw new TemplateSyntaxException(
                "Unclosed @{$unclosed['name']} directive. Expected @{$expected}.",
                $this->path,
                $unclosed['line'],
                $unclosed['col'],
                $this->source,
            );
        }
    }

    /**
     * Read a comment: {{-- ... --}}
     */
    private function readComment(): Token
    {
        $startLine = $this->line;
        $startCol  = $this->col;

        // Skip past {{--
        $this->advance(4);

        $content = '';

        while ($this->pos < $this->length) {
            if ($this->lookAhead('--}}')) {
                $this->advance(4);
                return new Token(TokenType::COMMENT_OPEN, '{{--' . $content . '--}}', $startLine, $startCol);
            }
            $content .= $this->source[$this->pos];
            $this->advance(1);
        }

        throw new TemplateSyntaxException(
            'Unclosed comment. Expected --}} to close the {{-- comment.',
            $this->path,
            $startLine,
            $startCol,
            $this->source,
        );
    }

    /**
     * Read an escaped echo: {{ expr }} with optional whitespace trimming {{ - expr -}}
     *
     * @return Token[] Up to 3 tokens: ECHO_OPEN, TEXT (expression), ECHO_CLOSE
     */
    private function readEscapedEcho(): array
    {
        $startLine = $this->line;
        $startCol  = $this->col;
        $trimLeft  = false;
        $trimRight = false;

        // Skip past {{
        $this->advance(2);

        // Check for trim marker {{-
        if ($this->pos < $this->length && $this->source[$this->pos] === '-') {
            $trimLeft = true;
            $this->advance(1);
        }

        $expression = '';

        while ($this->pos < $this->length) {
            // Check for trim marker -}}
            if ($this->lookAhead('-}}')) {
                $trimRight = true;
                $this->advance(3);

                return [
                    new Token(TokenType::ECHO_OPEN, '{{', $startLine, $startCol, $trimLeft, false),
                    new Token(TokenType::TEXT, trim($expression), $startLine, $startCol + 2),
                    new Token(TokenType::ECHO_CLOSE, '}}', $this->line, $this->col, false, $trimRight),
                ];
            }

            if ($this->lookAhead('}}')) {
                $this->advance(2);

                return [
                    new Token(TokenType::ECHO_OPEN, '{{', $startLine, $startCol, $trimLeft, false),
                    new Token(TokenType::TEXT, trim($expression), $startLine, $startCol + 2),
                    new Token(TokenType::ECHO_CLOSE, '}}', $this->line, $this->col),
                ];
            }

            $expression .= $this->source[$this->pos];
            $this->advance(1);
        }

        throw new TemplateSyntaxException(
            'Unclosed echo statement. Expected }} to close the {{ echo.',
            $this->path,
            $startLine,
            $startCol,
            $this->source,
        );
    }

    /**
     * Read a raw echo: {!! expr !!}
     *
     * @return Token[]
     */
    private function readRawEcho(): array
    {
        $startLine = $this->line;
        $startCol  = $this->col;

        // Skip past {!!
        $this->advance(3);

        $expression = '';

        while ($this->pos < $this->length) {
            if ($this->lookAhead('!!}')) {
                $this->advance(3);

                return [
                    new Token(TokenType::RAW_ECHO_OPEN, '{!!', $startLine, $startCol),
                    new Token(TokenType::TEXT, trim($expression), $startLine, $startCol + 3),
                    new Token(TokenType::RAW_ECHO_CLOSE, '!!}', $this->line, $this->col),
                ];
            }

            $expression .= $this->source[$this->pos];
            $this->advance(1);
        }

        throw new TemplateSyntaxException(
            'Unclosed raw echo statement. Expected !!} to close the {!! raw echo.',
            $this->path,
            $startLine,
            $startCol,
            $this->source,
        );
    }

    /**
     * Read a directive: @name or @name(balanced args)
     *
     * Uses parenthesis-balanced extraction to handle nested parentheses.
     */
    private function readDirective(): Token
    {
        $startLine = $this->line;
        $startCol  = $this->col;
        $trimLeft  = false;
        $trimRight = false;

        // Skip @
        $this->advance(1);

        // Check for whitespace control: @-directive
        if ($this->pos < $this->length && $this->source[$this->pos] === '-') {
            $trimLeft = true;
            $this->advance(1);
        }

        // Read directive name
        $name = '';
        while ($this->pos < $this->length && preg_match('/[a-zA-Z0-9_]/', $this->source[$this->pos])) {
            $name .= $this->source[$this->pos];
            $this->advance(1);
        }

        if ($name === '') {
            throw new TemplateSyntaxException(
                'Invalid directive: @ must be followed by a directive name.',
                $this->path,
                $startLine,
                $startCol,
                $this->source,
            );
        }

        // Read arguments if present (balanced parentheses)
        $args = '';
        if ($this->pos < $this->length && $this->source[$this->pos] === '(') {
            $args = $this->readBalancedParentheses();
        }

        // Check for trailing trim marker: @directive-
        if ($this->pos < $this->length && $this->source[$this->pos] === '-') {
            // Only treat as trim if it's not part of a word
            $nextPos = $this->pos + 1;
            if ($nextPos >= $this->length || !preg_match('/[a-zA-Z0-9_]/', $this->source[$nextPos])) {
                $trimRight = true;
                $this->advance(1);
            }
        }

        $value = '@' . $name . $args;

        return new Token(TokenType::DIRECTIVE, $value, $startLine, $startCol, $trimLeft, $trimRight);
    }

    /**
     * Read balanced parentheses including nested levels.
     *
     * This is the core fix for Bug #1: nested parentheses in directives
     * like @style(['color: red' => ($count ?? 0) > 0]).
     *
     * Uses a depth counter with string-awareness to avoid being
     * confused by parentheses inside quoted strings.
     *
     * @return string The balanced parenthesized expression including outer parens
     * @throws TemplateSyntaxException On unclosed parentheses
     */
    private function readBalancedParentheses(): string
    {
        $startLine = $this->line;
        $startCol  = $this->col;
        $depth     = 0;
        $result    = '';
        $inSingle  = false;
        $inDouble  = false;
        $escaped   = false;

        while ($this->pos < $this->length) {
            $char = $this->source[$this->pos];

            if ($escaped) {
                $result  .= $char;
                $escaped  = false;
                $this->advance(1);
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $result .= $char;
                $this->advance(1);
                continue;
            }

            // Track string context to ignore parens inside strings
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
                        $result .= $char;
                        $this->advance(1);
                        return $result;
                    }
                }
            }

            $result .= $char;
            $this->advance(1);
        }

        throw new TemplateSyntaxException(
            'Unclosed parenthesis in directive. Expected ) but reached end of template.',
            $this->path,
            $startLine,
            $startCol,
            $this->source,
        );
    }

    /**
     * Read a component tag: <x-name ...>, </x-name>, or <x-name ... />
     */
    private function readComponentTag(): Token
    {
        $startLine = $this->line;
        $startCol  = $this->col;
        $isClosing = $this->lookAhead('</x-');

        $tag = '';

        // Read until >
        while ($this->pos < $this->length) {
            $char = $this->source[$this->pos];
            $tag .= $char;
            $this->advance(1);

            if ($char === '>') {
                break;
            }
        }

        if (!str_ends_with($tag, '>')) {
            throw new TemplateSyntaxException(
                'Unclosed component tag.',
                $this->path,
                $startLine,
                $startCol,
                $this->source,
            );
        }

        if ($isClosing) {
            return new Token(TokenType::COMPONENT_CLOSE, $tag, $startLine, $startCol);
        }

        if (str_ends_with($tag, '/>')) {
            return new Token(TokenType::COMPONENT_SELF_CLOSE, $tag, $startLine, $startCol);
        }

        return new Token(TokenType::COMPONENT_OPEN, $tag, $startLine, $startCol);
    }

    /**
     * Check if the current position starts a directive (@ followed by alpha).
     */
    private function isDirectiveStart(): bool
    {
        $nextPos = $this->pos + 1;

        // @- is whitespace control prefix
        if ($nextPos < $this->length && $this->source[$nextPos] === '-') {
            $nextPos++;
        }

        return $nextPos < $this->length && preg_match('/[a-zA-Z]/', $this->source[$nextPos]) === 1;
    }

    /**
     * Check if the source at the current position starts with the given string.
     */
    private function lookAhead(string $needle): bool
    {
        return substr($this->source, $this->pos, strlen($needle)) === $needle;
    }

    /**
     * Advance the position by N characters, tracking line/column.
     */
    private function advance(int $count): void
    {
        for ($i = 0; $i < $count && $this->pos < $this->length; $i++) {
            if ($this->source[$this->pos] === "\n") {
                $this->line++;
                $this->col = 1;
            } else {
                $this->col++;
            }
            $this->pos++;
        }
    }

    /**
     * Extract the directive name from a token value like "@foreach(...)".
     */
    private function extractDirectiveName(string $value): ?string
    {
        if (preg_match('/^@-?(\w+)/', $value, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Check if a @section directive is the shorthand form: @section('name', 'value')
     */
    private function isSectionShorthand(string $value): bool
    {
        // Shorthand has exactly two arguments separated by comma
        if (preg_match('/@section\(\s*["\'][^"\']+["\']\s*,/', $value)) {
            return true;
        }
        return false;
    }

    /**
     * Find which opener directive this closing directive belongs to.
     *
     * @return string|null The opener name, or null if not a closer
     */
    private function findOpenerForCloser(string $name): ?string
    {
        foreach (self::BLOCK_DIRECTIVES as $opener => $closer) {
            if ($closer === $name) {
                return $opener;
            }
        }
        return null;
    }
}
