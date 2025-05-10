<?php
declare(strict_types=1);

namespace MonkeysLegion\Template;

class Parser
{
    public function parse(string $source): string
    {
        $source = $this->parseComponents($source);
        $source = $this->parseSlots($source);
        return $source;
    }

    private function parseComponents(string $source): string
    {
        return preg_replace_callback(
            '/<x-([a-zA-Z0-9_:-]+)([^>]*)>(.*?)<\/x-\\1>/s',
            function (array $m) {
                $name   = $m[1];
                $attrStr= $m[2];
                $inner  = $m[3];

                // build associative array of HTML‑style attributes
                $attrs = [];
                if (preg_match_all('/([a-zA-Z0-9_:-]+)\s*=\s*"([^"]*)"/', $attrStr, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $set) {
                        $attrs[$set[1]] = $set[2];
                    }
                }
                $attrsCode   = var_export($attrs, true);
                $innerParsed = $this->parse($inner);

                // Single PHP block ensures $__ml_attrs and $slotContent exist
                $snippet = str_replace(
                    ['NAME', 'ATTRS', 'INNER'],
                    [$name, $attrsCode, $innerParsed],
                    <<<'PHP'
<?php /* component: NAME */
$__ml_attrs = ATTRS;
extract($__ml_attrs, EXTR_SKIP);
ob_start();
?>INNER<?php
$slotContent = ob_get_clean();
include base_path('resources/views/components/NAME.ml.php');
?>
PHP
                );

                return $snippet;
            },
            $source
        );
    }

    private function parseSlots(string $source): string
    {
        return preg_replace_callback(
            '/@slot\(["\'\"]([^\)]+)["\'\"]\)(.*?)@endslot/s',
            function (array $m) {
                $slot = $m[1];
                $body = $this->parse($m[2]);

                // ① ensure an array exists, ② then assign closure
                return "<?php \$slots = \$slots ?? []; "
                    . "\$slots['{$slot}'] = function() { ?>{$body}<?php }; ?>";
            },
            $source
        );
    }
}