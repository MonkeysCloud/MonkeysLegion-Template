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
 */
class Parser
{
    public function parse(string $source): string
    {
        // 1) Components first (so nested slots aren't broken)
        $source = $this->parseComponents($source);
        // 2) Then slot directives
        $source = $this->parseSlots($source);
        return $source;
    }

    private function parseComponents(string $source): string
    {
        return preg_replace_callback(
            '/<x-([a-zA-Z0-9_:-]+)([^>]*)>(.*?)<\/x-\1>/s',
            function (array $m) {
                $name    = $m[1];
                $attrStr = $m[2];
                $inner   = $m[3];

                // 1) Parse HTML‑style attributes, detect dynamic expressions
                $attrs = [];
                if (preg_match_all(
                    '/([a-zA-Z0-9_:-]+)\s*=\s*"([^"]*)"/',
                    $attrStr,
                    $matches,
                    PREG_SET_ORDER
                )) {
                    foreach ($matches as [$full, $key, $raw]) {
                        // {{ expr }} → escaped
                        if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/', $raw, $ex)) {
                            $attrs[$key] = "htmlspecialchars({$ex[1]}, ENT_QUOTES, 'UTF-8')";
                        }
                        // {!! expr !!} → raw
                        elseif (preg_match('/^\{!!\s*(.+?)\s*!!\}$/', $raw, $ex)) {
                            $attrs[$key] = $ex[1];
                        }
                        // literal string
                        else {
                            $attrs[$key] = var_export($raw, true);
                        }
                    }
                }

                // 2) Build PHP code for the attrs array
                $parts = [];
                foreach ($attrs as $k => $v) {
                    $parts[] = var_export($k, true) . ' => ' . $v;
                }
                $attrsCode = '[' . implode(', ', $parts) . ']';

                // 3) Recursively parse inner content
                $innerParsed = $this->parse($inner);

                // 4) Emit the snippet
                return implode("\n", [
                    "<?php /* component: {$name} */ ?>",
                    "<?php \$slots = \$slots ?? []; ?>",
                    "<?php \$__ml_attrs = {$attrsCode}; extract(\$__ml_attrs, EXTR_SKIP); ?>",
                    "<?php ob_start(); ?>{$innerParsed}<?php \$slotContent = ob_get_clean(); ?>",
                    "<?php include base_path('resources/views/components/{$name}.ml.php'); ?>",
                ]);
            },
            $source
        );
    }

    private function parseSlots(string $source): string
    {
        return preg_replace_callback(
            '/@slot\(["\']([^)]+)["\']\)(.*?)@endslot/s',
            function (array $m) {
                $slot = $m[1];
                $body = $this->parse($m[2]);
                return "<?php \$slots = \$slots ?? []; \$slots['{$slot}'] = function() { ?>{$body}<?php }; ?>";
            },
            $source
        );
    }
}