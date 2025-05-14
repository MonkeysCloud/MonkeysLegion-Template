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
        // First handle component tags, so nested slots stay intact
        $source = $this->parseComponents($source);
        // Then handle @slot directives
        $source = $this->parseSlots($source);
        return $source;
    }

    /**
     * Convert <x-name attr="…">…</x-name> into PHP include snippets.
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
                        $attrs[$set[1]] = $set[2];
                    }
                }

                $attrsCode   = var_export($attrs, true);
                $innerParsed = $this->parse($inner);

                // Build PHP snippet: init slots, extract attrs, capture slotContent, include
                $snippet  = "<?php /* component: {$name} */ ?>";
                $snippet .= "<?php \$slots = \$slots ?? []; ?>";
                $snippet .= "<?php \$__ml_attrs = {$attrsCode}; extract(\$__ml_attrs, EXTR_SKIP); ?>";
                $snippet .= "<?php ob_start(); ?>{$innerParsed}<?php \$slotContent = ob_get_clean(); ?>";
                // Use (void) to suppress "expression result not used" warnings
                $snippet .= "<?php (void) include base_path('resources/views/components/{$name}.ml.php'); ?>";

                return $snippet;
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

                return "<?php \$slots = \$slots ?? []; \$slots['{\$slot}'] = function() { ?>{$body}<?php }; ?>";
            },
            $source
        );
    }
}
