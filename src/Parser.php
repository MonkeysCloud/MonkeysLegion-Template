<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Parses advanced MLView directives before they are fed to Compiler.
 *
 * Supported in this minimal pass:
 *  - Component tags  : <x-alert title="Hi">…</x-alert>
 *  - Slot directives : @slot('name') … @endslot
 *
 * The parser transforms them into plain PHP snippets. They will then be
 * processed by Compiler (which handles {{ }} / {!! !!} interpolation).
 */
class Parser
{
    /**
     * Parse components and slots in the template source.
     */
    public function parse(string $source): string
    {
        // 1) Components first so nested slots stay intact
        $source = $this->parseComponents($source);
        // 2) Then slot directives
        $source = $this->parseSlots($source);
        return $source;
    }

    /**
     * Convert <x-name attr="…">…</x-name> into PHP include snippets.
     * It will search in multiple view subdirectories (components, layouts, partials).
     */
    private function parseComponents(string $source): string
    {
        return preg_replace_callback(
            '/<x-([a-zA-Z0-9_:-]+)([^>]*)>(.*?)<\/x-\\1>/s',
            function (array $m) {
                $name       = $m[1];
                $attrStr    = $m[2];
                $inner      = $m[3];

                // Parse HTML-like attributes
                $attrs = [];
                if (preg_match_all(
                    '/([a-zA-Z0-9_:-]+)\\s*=\\s*"([^"]*)"/',
                    $attrStr,
                    $matches,
                    PREG_SET_ORDER
                )) {
                    foreach ($matches as $set) {
                        $key = $set[1];
                        $raw = $set[2];
                        // Escaped
                        if (preg_match('/^\\{\\{\\s*(.+?)\\s*\\}\\}$/', $raw, $ex)) {
                            $attrs[$key] = "htmlspecialchars({$ex[1]}, ENT_QUOTES, 'UTF-8')";
                        }
                        // Raw
                        elseif (preg_match('/^\\{!!\\s*(.+?)\\s*!!\\}$/', $raw, $ex)) {
                            $attrs[$key] = $ex[1];
                        }
                        // Literal
                        else {
                            $attrs[$key] = var_export($raw, true);
                        }
                    }
                }

                // Build attrs PHP code
                $parts = [];
                foreach ($attrs as $k => $v) {
                    $parts[] = var_export($k, true) . ' => ' . $v;
                }
                $attrsCode = '[' . implode(', ', $parts) . ']';

                // Recursively parse inner content
                $innerParsed = $this->parse($inner);

                // Generate PHP snippet with dynamic directory lookup
                return implode("\n", [
                    "<?php /* component: {$name} */ ?>",
                    "<?php \$slots = \$slots ?? []; ?>",
                    "<?php \$__ml_attrs = {$attrsCode}; extract(\$__ml_attrs, EXTR_SKIP); ?>",
                    "<?php ob_start(); ?>{$innerParsed}<?php \$slotContent = ob_get_clean(); ?>",
                    "<?php",
                    "foreach(['components','layouts','partials'] as \$__ml_dir) {",
                    "    \$__ml_path = base_path('resources/views/'.\$__ml_dir.'/{$name}.ml.php');",
                    "    if (is_file(\$__ml_path)) { include \$__ml_path; break; }",
                    "}",
                    "?>"
                ]);
            },
            $source
        );
    }

    /**
     * Convert @slot('name')…@endslot into PHP closures in \$slots.
     */
    private function parseSlots(string $source): string
    {
        return preg_replace_callback(
            '/@slot\(["\']([^"\']+)["\']\)(.*?)@endslot/s',
            function (array $m) {
                $slot = $m[1];
                $body = $this->parse($m[2]);
                return "<?php \$slots = \$slots ?? []; \$slots['{$slot}'] = function() { ?>{$body}<?php }; ?>";
            },
            $source
        );
    }
}
