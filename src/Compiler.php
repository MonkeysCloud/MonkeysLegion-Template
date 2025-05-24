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
            static fn(array $m) => "<?= htmlspecialchars(strtoupper({$m[1]}), ENT_QUOTES, 'UTF-8') ?>",
            $php
        );

        // 3) Translation directive: @lang('key', ['c'=>1])
        $php = preg_replace_callback(
            "/@lang\((?:'|\")(.+?)(?:'|\")(?:\s*,\s*(\[[^\]]*\]))?\)/",
            function(array $m) {
                $key     = $m[1];
                $replace = $m[2] ?? '[]';
                return "<?= trans('{$key}', {$replace}) ?>";
            },
            $php
        );

        // 4) Escaped echo  {{ expr }}
        $php = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            static fn(array $m) => "<?= htmlspecialchars({$m[1]}, ENT_QUOTES, 'UTF-8') ?>",
            $php
        );

        // 5) Raw echo  {!! expr !!}
        $php = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/',
            static fn(array $m) => "<?= {$m[1]} ?>",
            $php
        );

        // 6) Debugging: @dump(expr)
        $php = preg_replace_callback(
            '/@dump\((.+?)\)/',
            fn(array $m) => "<?php echo '<pre>'; var_dump({$m[1]}); echo '</pre>'; ?>",
            $php
        );

        // 7) Prepend header for strict types + comment
        return "<?php\ndeclare(strict_types=1);\n/* Compiled from: {$path} */\n?>\n" . $php;
    }
}