<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Compiles template source into executable PHP.
 *
 * Flow:
 *  1. Let Parser rewrite components / slots → plain directive syntax
 *  2. Apply custom directives (e.g. @upper())
 *  3. Convert {{ }} and {!! !!} echoes
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
        $source = $this->parser->parse($source);

        // 2) Custom directive: @upper(expr)
        $source = preg_replace_callback(
            '/@upper\(([^)]+)\)/',
            static fn($m) => "<?= htmlspecialchars(strtoupper({$m[1]}), ENT_QUOTES, 'UTF-8') ?>",
            $source
        );

        // 3) Escaped echo  {{ expr }}
        $source = preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/',
            static fn($m) => "<?= htmlspecialchars({$m[1]}, ENT_QUOTES, 'UTF-8') ?>",
            $source
        );

        // 4) Raw echo  {!! expr !!}
        $source = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/',
            static fn($m) => "<?= {$m[1]} ?>",
            $source
        );

        // 5) Prepend header for strict types + comment
        return "<?php\ndeclare(strict_types=1);\n/* Compiled from: {$path} */\n?>\n" . $source;
    }
}