<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Compiles template source into executable PHP.
 *
 * Flow:
 *  1. Let Parser rewrite components / slots → plain directive syntax
 *  2. Apply custom directives (e.g. @upper(), @lang())
 *  3. Convert {{ }} and {!! !!} echoes
 *  4. Debugging directives
 *  5. Prepend header for strict types + comment
 */
class Compiler
{
    public function __construct(private Parser $parser) {}

    /**
     * @param string $source Raw template input
     * @param string $path   Absolute path of original file (for debug)
     * @return string        PHP source ready to be cached & included
     */
    public function compile(string $source, string $path): string
    {
        // 0) Pre-process code examples to protect template syntax
        $source = $this->preProcessCodeExamples($source);

        // 1) Run parser (handles <x-*> & @slot)
        $php = $this->parser->parse($source);

        // 2) Custom directive: @upper(expr)
        $php = preg_replace_callback(
            '/@upper\(([^)]+)\)/',
            static fn(array $m) => "\n<?= htmlspecialchars(strtoupper((string)({$m[1]} ?? '')), ENT_QUOTES, 'UTF-8') ?>\n",
            $php
        );

        // 3) Translation directive: @lang('key', ['c'=>1])
        $php = preg_replace_callback(
            "/@lang\((?:'|\")(.+?)(?:'|\")(?:\s*,\s*(\[[^\]]*\]))?\)/",
            function (array $m) {
                $key     = $m[1];
                $replace = $m[2] ?? '[]';
                return "\n<?= trans('{$key}', {$replace}) ?>\n";
            },
            $php
        );

        // 4) Process expressions in HTML attributes FIRST (without adding newlines)
        // This will match ANY HTML attribute with expressions: id, class, data-*, style, etc.
        $php = preg_replace_callback(
            '/<([^>]+?)(\s+[a-zA-Z0-9_:-]+\s*=\s*["\'])([^"\']*?)(\{\{|\{!!)(.*?)(\}\}|!!\})([^"\']*?)(["\'])/s',
            function (array $m) {
                $tagStart = $m[1];         // Tag opening part
                $attrStart = $m[2];        // Attribute name and opening quote
                $beforeExpr = $m[3];       // Content before expression
                $exprOpen = $m[4];         // {{ or {!!
                $exprContent = $m[5];      // Expression content
                $exprClose = $m[6];        // }} or !!}
                $afterExpr = $m[7];        // Content after expression
                $attrClose = $m[8];        // Closing quote

                // Is this an escaped expression {{ ... }} or raw {!! ... !!}?
                $isEscaped = $exprOpen === '{{';

                // Generate PHP output without newlines
                $phpOutput = $isEscaped
                    ? "<?= htmlspecialchars((string)({$exprContent} ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    : "<?= {$exprContent} ?? '' ?>";

                // Rebuild the attribute without newlines
                return '<' . $tagStart . $attrStart . $beforeExpr . $phpOutput . $afterExpr . $attrClose;
            },
            $php
        );

        // 5) Regular escaped echo (outside attributes)
        $php = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            static fn(array $m) => "\n<?= htmlspecialchars((string)({$m[1]} ?? ''), ENT_QUOTES, 'UTF-8') ?>\n",
            $php
        );

        // 6) Regular raw echo (outside attributes)
        $php = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/',
            static fn(array $m) => "\n<?= {$m[1]} ?? '' ?>\n",
            $php
        );

        // 6) Debugging: @dump(expr) - Enhanced to handle complex expressions with balanced parentheses
        $php = preg_replace_callback(
            '/@dump\(\s*((?>[^()]+|(?R))*)\s*\)/s',
            fn(array $m) => "\n<?php echo '<pre>'; var_dump({$m[1]}); echo '</pre>'; ?>\n",
            $php
        );

        // 7) Control structures: @if, @elseif, @else, @endif
        $php = preg_replace('/@if\s*\((.+?)\)/', "\n<?php if ($1): ?>\n", $php);
        $php = preg_replace('/@elseif\s*\((.+?)\)/', "\n<?php elseif ($1): ?>\n", $php);
        $php = preg_replace('/@else\b/', "\n<?php else: ?>\n", $php);
        $php = preg_replace('/@endif\b/', "\n<?php endif; ?>\n", $php);

        // 8) Loops: @foreach, @endforeach
        $php = preg_replace('/@foreach\s*\((.+?)\)/', "\n<?php foreach ($1): ?>\n", $php);
        $php = preg_replace('/@endforeach\b/', "\n<?php endforeach; ?>\n", $php);

        // 9) Clean up excessive newlines and format properly
        $php = preg_replace('/\n{3,}/', "\n\n", $php);
        $php = trim($php);

        // 10) Post-process to restore protected code examples
        $php = $this->postProcessCodeExamples($php);

        // 11) Prepend header for strict types + comment
        return "<?php\ndeclare(strict_types=1);\n/* Compiled from: {$path} */\n?>\n" . $php;
    }

    /**
     * Pre-process code blocks to protect template syntax
     * by replacing template tags with temporary markers
     */
    private function preProcessCodeExamples(string $source): string
    {
        // First process any explicit escapes (@@ to represent literal @)
        $source = preg_replace('/@@([a-zA-Z0-9_]+)/', '___MLVIEW_ESCAPED_AT___$1', $source);

        // Then process code blocks
        $source = preg_replace_callback(
            '/<code[^>]*>(.*?)<\/code>/s',
            function (array $matches) {
                $code = $matches[1];

                // Capture any attributes on the code tag
                $attributes = '';
                if (preg_match('/<code([^>]*)>/', $matches[0], $attrMatches)) {
                    $attributes = $attrMatches[1];
                }

                // First protect any double-escaped @ symbols
                $code = preg_replace('/@@([a-zA-Z0-9_]+)/', '___MLVIEW_DOUBLE_AT___$1', $code);

                // Replace template syntax with unique placeholders
                $code = str_replace('{{', '___MLVIEW_OPEN_CURLY___', $code);
                $code = str_replace('}}', '___MLVIEW_CLOSE_CURLY___', $code);
                $code = str_replace('{!!', '___MLVIEW_OPEN_RAW___', $code);
                $code = str_replace('!!}', '___MLVIEW_CLOSE_RAW___', $code);

                // Handle ALL @ directives comprehensively
                // First protect specific directives we know about
                $directives = [
                    'extends',
                    'section',
                    'endsection',
                    'yield',
                    'slot',
                    'endslot',
                    'dump',
                    'if',
                    'elseif',
                    'else',
                    'endif',
                    'foreach',
                    'endforeach',
                    'lang',
                    'upper',
                    'include',
                    'php',
                    'break',
                    'continue'
                ];

                foreach ($directives as $directive) {
                    $code = preg_replace('/@' . $directive . '\b/', '___MLVIEW_DIRECTIVE___' . $directive, $code);
                }

                // Finally handle any remaining @ symbols generically
                $code = preg_replace('/@([a-zA-Z0-9_]+)/', '___MLVIEW_AT___$1', $code);

                return '<code' . $attributes . '>' . $code . '</code>';
            },
            $source
        );

        return $source;
    }

    /**
     * Post-process to restore protected template syntax in code examples
     */
    private function postProcessCodeExamples(string $php): string
    {
        // First restore specific directives
        $php = str_replace('___MLVIEW_DIRECTIVE___', '@', $php);

        // Then handle other placeholders
        $php = str_replace('___MLVIEW_OPEN_CURLY___', '{{', $php);
        $php = str_replace('___MLVIEW_CLOSE_CURLY___', '}}', $php);
        $php = str_replace('___MLVIEW_OPEN_RAW___', '{!!', $php);
        $php = str_replace('___MLVIEW_CLOSE_RAW___', '!!}', $php);
        $php = str_replace('___MLVIEW_AT___', '@', $php);
        $php = str_replace('___MLVIEW_DOUBLE_AT___', '@@', $php);
        $php = str_replace('___MLVIEW_ESCAPED_AT___', '@', $php);

        return $php;
    }
}
