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
 *  - Slot tags       : <x-slot:name>…</x-slot:name>
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
        // 1) Handle slot tags first (x-slot:name)
        $source = $this->parseSlotTags($source);
        // 2) Then slot directives (@slot)
        $source = $this->parseSlots($source);
        // 3) Components last so slots are already processed
        $source = $this->parseComponents($source);
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
                return "\n<?php /* component: {$name} */ ?>\n" .
                    "<?php \$slots = \$slots ?? []; ?>\n" .
                    "<?php \$__ml_attrs = {$attrsCode}; extract(\$__ml_attrs, EXTR_SKIP); ?>\n" .
                    "<?php ob_start(); ?>\n{$innerParsed}\n<?php \$slotContent = ob_get_clean(); ?>\n" .
                    "<?php\n" .
                    "foreach(['components','layouts','partials'] as \$__ml_dir) {\n" .
                    "    \$__ml_path = base_path('resources/views/'.\$__ml_dir.'/{$name}.ml.php');\n" .
                    "    if (is_file(\$__ml_path)) { include \$__ml_path; break; }\n" .
                    "}\n" .
                    "?>\n";
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
                return "\n<?php \$slots = \$slots ?? []; \$slots['{$slot}'] = function() { ?>\n{$body}\n<?php }; ?>\n";
            },
            $source
        );
    }

    /**
     * Convert <x-slot:name>…</x-slot:name> into PHP closures in \$slots.
     */
    private function parseSlotTags(string $source): string
    {
        return preg_replace_callback(
            '/<x-slot:([a-zA-Z0-9_-]+)([^>]*)>(.*?)<\/x-slot:\\1>/s',
            function (array $m) {
                $slot = $m[1];
                $body = $this->parse($m[3]);
                return "\n<?php \$slots = \$slots ?? []; \$slots['{$slot}'] = function() { ?>\n{$body}\n<?php }; ?>\n";
            },
            $source
        );
    }
}
