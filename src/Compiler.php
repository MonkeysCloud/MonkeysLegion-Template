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

        // 4) Escaped echo  {{ expr }} - Handle null values safely
        $php = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            static fn(array $m) => "\n<?= htmlspecialchars((string)({$m[1]} ?? ''), ENT_QUOTES, 'UTF-8') ?>\n",
            $php
        );

        // 5) Raw echo  {!! expr !!} - Handle null values safely
        $php = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/',
            static fn(array $m) => "\n<?= {$m[1]} ?? '' ?>\n",
            $php
        );

        // 6) Debugging: @dump(expr)
        $php = preg_replace_callback(
            '/@dump\((.+?)\)/',
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

        // 10) Prepend header for strict types + comment
        return "<?php\ndeclare(strict_types=1);\n/* Compiled from: {$path} */\n?>\n" . $php;
    }
}
