<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Support\AttributeBag;
use MonkeysLegion\Template\Support\SlotCollection;

/**
 * Enhanced Parser with modern directives for MLView templates.
 *
 * New Features:
 * - @props(['title' => 'Default']) instead of @param
 * - $attrs object for attribute management
 * - $slots collection for slot management
 * - :class directive for conditional classes
 * - Component namespacing (ui.button -> ui/button)
 * - Better error messages
 */
class Parser
{
    /**
     * Parse components and slots in the template source.
     * Ensures all template elements are recursively processed.
     */
    public function parse(string $source): string
    {
        // First, remove @props/@param directives from the output
        $source = $this->removePropsDirectives($source);

        // Apply multiple parsing passes to ensure everything is parsed properly
        $previousSource = '';
        $iterationCount = 0;
        $maxIterations = 10; // Prevent infinite loops

        while ($source !== $previousSource && $iterationCount < $maxIterations) {
            $previousSource = $source;
            $iterationCount++;

            // Process in correct order - from inside out
            // 1) Handle conditional class directive (:class)
            $source = $this->parseClassDirective($source);
            // 2) Handle slot tags first (x-slot:name)
            $source = $this->parseSlotTags($source);
            // 3) Then slot directives (@slot)
            $source = $this->parseSlots($source);
            // 4) Parse layout inheritance directives
            $source = $this->parseExtends($source);
            $source = $this->parseSections($source);
            $source = $this->parseYields($source);
            // 5) Parse includes
            $source = $this->parseIncludes($source);
            // 6) Components last so slots are already processed
            $source = $this->parseComponents($source);
        }

        return $source;
    }

    /**
     * Parse :class directive for conditional CSS classes
     *
     * Syntax: :class="['btn', 'active' => $isActive, $variant]"
     */
    private function parseClassDirective(string $source): string
    {
        return preg_replace_callback(
            '/:class\s*=\s*"([^"]+)"/s',
            function (array $m) {
                $expression = $m[1];
                // Remove any curly braces if present
                $expression = preg_replace('/^\{\{|\}\}$/', '', trim($expression));

                return 'class="<?= \\MonkeysLegion\\Template\\Support\\AttributeBag::conditional('
                    . $expression . ') ?>"';
            },
            $source
        );
    }

    /**
     * Extract parameter declarations from component source
     * Supports both @props and @param syntax for backward compatibility
     *
     * @param string $source Component source code
     * @return array Parameter declarations with default values
     */
    public function extractComponentParams(string $source): array
    {
        $params = [];

        // Try new @props syntax first: @props(['title' => 'Default', 'active' => false])
        if (preg_match('/@props\s*\(\s*\[\s*(.*?)\s*\]\s*\)/s', $source, $match)) {
            $paramString = $match[1];
            $params = $this->parseParamString($paramString);
        }
        // Fall back to old @param syntax for backward compatibility
        elseif (preg_match('/@param\s*\(\s*\[\s*(.*?)\s*\]\s*\)/s', $source, $match)) {
            $paramString = $match[1];
            $params = $this->parseParamString($paramString);
        }

        return $params;
    }

    /**
     * Parse parameter string into key-value array
     */
    private function parseParamString(string $paramString): array
    {
        $params = [];

        // Extract individual key-value pairs
        // Handle both simple values and complex expressions
        preg_match_all(
            '/[\'"]([^\'"]*)[\'"]\s*=>\s*(.+?)(?:,|$)/s',
            $paramString . ',', // Add trailing comma to catch last param
            $paramMatches,
            PREG_SET_ORDER
        );

        foreach ($paramMatches as $paramMatch) {
            $paramName = $paramMatch[1];
            $defaultValueStr = trim($paramMatch[2], " \t\n\r\0\x0B,");

            // Evaluate the default value
            $params[$paramName] = $this->evaluateDefaultValue($defaultValueStr);
        }

        return $params;
    }

    /**
     * Evaluate default value for parameter from string
     * Converts string representations to actual values (string, number, bool, etc)
     *
     * @param string $value String representation of default value
     * @return mixed Converted default value
     */
    private function evaluateDefaultValue(string $value): mixed
    {
        $value = trim($value);

        // Handle quoted strings
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $value, $matches)) {
            return $matches[1];
        }

        // Handle booleans
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;

        // Handle empty arrays
        if ($value === '[]') return [];

        // Handle numbers
        if (is_numeric($value)) {
            // Integer
            if ((string)(int)$value === $value) {
                return (int)$value;
            }
            // Float
            return (float)$value;
        }

        // Default to string
        return $value;
    }

    /**
     * Parse @include directives to include templates.
     *
     * Supports:
     * @include('name')
     * @include('name', ['var' => $value])
     */
    private function parseIncludes(string $source): string
    {
        return preg_replace_callback(
            '/@include\(\s*["\']([^"\']+)["\']\s*(?:,\s*(\[[^\]]+\]))?\s*\)/',
            function (array $m) {
                $viewName = $m[1];
                $data = $m[2] ?? '[]';

                // Convert dot notation to path
                $viewPath = str_replace('.', '/', $viewName);

                return "<?php\n" .
                    "// Include template: {$viewName}\n" .
                    "\$__data_include = {$data};\n" .
                    "\$__include_path = base_path('resources/views/{$viewPath}.ml.php');\n" .
                    "if (is_file(\$__include_path)) {\n" .
                    "    \$__ml_scope = \\MonkeysLegion\\Template\\VariableScope::getCurrent();\n" .
                    "    \$__include_source = file_get_contents(\$__include_path);\n" .
                    "    \$__include_parser = new \\MonkeysLegion\\Template\\Parser();\n" .
                    "    \$__include_params = \$__include_parser->extractComponentParams(\$__include_source);\n" .
                    "    \$__ml_scope->createIsolatedScope(\$__data_include, \$__include_params);\n" .
                    "    \n" .
                    "    try {\n" .
                    "        \$__include_parsed = \$__include_parser->parse(\$__include_source);\n" .
                    "        \$__include_compiler = new \\MonkeysLegion\\Template\\Compiler(\$__include_parser);\n" .
                    "        \$__include_compiled = \$__include_compiler->compile(\$__include_parsed, \$__include_path);\n" .
                    "        \$__include_compiled = substr(\$__include_compiled, strpos(\$__include_compiled, '?>') + 2);\n" .
                    "        \n" .
                    "        \$__isolated_data = \$__ml_scope->getCurrentScope();\n" .
                    "        extract(\$__isolated_data);\n" .
                    "        \n" .
                    "        eval('?>' . \$__include_compiled);\n" .
                    "    } catch (\\Throwable \$__include_error) {\n" .
                    "        throw new \\RuntimeException('Error in @include(\"{$viewName}\"): ' . \$__include_error->getMessage(), 0, \$__include_error);\n" .
                    "    } finally {\n" .
                    "        \$__ml_scope->popScope();\n" .
                    "    }\n" .
                    "} else {\n" .
                    "    throw new \\RuntimeException('Include not found: {$viewName} at {$viewPath}.ml.php');\n" .
                    "}\n" .
                    "?>";
            },
            $source
        );
    }

    /**
     * Convert <x-name> or <x-namespace.component> into PHP include snippets.
     * Enhanced with AttributeBag and SlotCollection support.
     */
    private function parseComponents(string $source): string
    {
        // First handle self-closing components
        $source = preg_replace_callback(
            '/<x-(?!slot:)([a-zA-Z0-9_:.-]+)([^>]*)\s*\/\s*>/',
            function (array $m) {
                return $this->buildComponentCode($m[1], $m[2], '', true);
            },
            $source
        );

        // Then handle components with content
        $source = preg_replace_callback(
            '/<x-([a-zA-Z0-9_:.-]+)([^>]*)>(.*?)<\/x-\1>/s',
            function (array $m) {
                return $this->buildComponentCode($m[1], $m[2], $m[3], false);
            },
            $source
        );

        return $source;
    }

    /**
     * Build the PHP code for a component
     */
    private function buildComponentCode(
        string $name,
        string $attrStr,
        string $inner,
        bool $selfClosing
    ): string {
        // Parse attributes into associative array
        $attrs = $this->parseAttributes($attrStr);

        // Normalize colon-prefixed keys again just in case
        $normalized = [];
        foreach ($attrs as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === ':') {
                $name = substr($k, 1);

                // If the value is a quoted string (from var_export), strip quotes
                if (is_string($v) && preg_match("/^'(.*)'$/s", $v, $m)) {
                    $expr = $m[1];
                } else {
                    $expr = $v;
                }

                $normalized[$name] = $expr;
            } else {
                $normalized[$k] = $v;
            }
        }

        // Build attrs PHP code
        $parts = [];
        foreach ($normalized as $k => $v) {
            $parts[] = var_export($k, true) . ' => ' . $v;
        }
        $attrsCode = '[' . implode(', ', $parts) . ']';

        // Parse inner content recursively if not self-closing
        $innerParsed = $selfClosing ? '' : $this->parse($inner);

        // Convert component name: ui.button -> ui/button, keep hyphens
        $componentPath = str_replace('.', '/', $name);

        // Generate enhanced PHP snippet with AttributeBag and SlotCollection
        return "\n<?php /* Component: {$name} */ ?>\n" .
            "<?php\n" .
            "// Component setup\n" .
            "\$__component_slots = [];\n" .
            "\$__component_attrs = {$attrsCode};\n" .
            "\$__component_content = '';\n" .
            ($selfClosing ? '' :
                "ob_start();\n" .
                "?>{$innerParsed}<?php\n" .
                "\$__component_content = ob_get_clean();\n"
            ) .
            "\n" .
            "// Locate component file\n" .
            "\$__component_found = false;\n" .
            "foreach(['components', 'layouts', 'partials'] as \$__ml_dir) {\n" .
            "    \$__ml_path = base_path('resources/views/'.\$__ml_dir.'/{$componentPath}.ml.php');\n" .
            "    if (is_file(\$__ml_path)) {\n" .
            "        \$__component_found = true;\n" .
            "        try {\n" .
            "            // Load and parse component\n" .
            "            \$__component_source = file_get_contents(\$__ml_path);\n" .
            "            \$__parser = new \\MonkeysLegion\\Template\\Parser();\n" .
            "            \$__component_params = \$__parser->extractComponentParams(\$__component_source);\n" .
            "            \n" .
            "            // Create isolated scope\n" .
            "            \$__ml_scope = \\MonkeysLegion\\Template\\VariableScope::getCurrent();\n" .
            "            \$__ml_scope->createIsolatedScope(\$__component_attrs, \$__component_params);\n" .
            "            \n" .
            "            // Create AttributeBag for remaining attributes\n" .
            "            \$attrs = new \\MonkeysLegion\\Template\\Support\\AttributeBag(\$__component_attrs);\n" .
            "            \n" .
            "            // Create SlotCollection\n" .
            "            \$slots = \\MonkeysLegion\\Template\\Support\\SlotCollection::fromArray(\n" .
            "                array_merge(\$__component_slots, ['__default' => \$__component_content])\n" .
            "            );\n" .
            "            \$slot = \$slots->getDefault();\n" .
            "            \n" .
            "            // Compile and execute\n" .
            "            \$__component_source = \$__parser->removePropsDirectives(\$__component_source);\n" .
            "            \$__parsed = \$__parser->parse(\$__component_source);\n" .
            "            \$__compiler = new \\MonkeysLegion\\Template\\Compiler(\$__parser);\n" .
            "            \$__compiled = \$__compiler->compile(\$__parsed, \$__ml_path);\n" .
            "            \$__compiled = substr(\$__compiled, strpos(\$__compiled, '?>') + 2);\n" .
            "            \n" .
            "            // Extract variables\n" .
            "            \$__isolated_data = \$__ml_scope->getCurrentScope();\n" .
            "            extract(\$__isolated_data);\n" .
            "            \n" .
            "            // Execute component\n" .
            "            eval('?>' . \$__compiled);\n" .
            "        } catch (\\Throwable \$__component_error) {\n" .
            "            throw new \\RuntimeException(\n" .
            "                'Error in component <x-{$name}>: ' . \$__component_error->getMessage() . \n" .
            "                ' (File: ' . \$__ml_path . ')',\n" .
            "                0,\n" .
            "                \$__component_error\n" .
            "            );\n" .
            "        } finally {\n" .
            "            \$__ml_scope->popScope();\n" .
            "        }\n" .
            "        break;\n" .
            "    }\n" .
            "}\n" .
            "if (!\$__component_found) {\n" .
            "    throw new \\RuntimeException(\n" .
            "        'Component not found: <x-{$name}> (searched: components/{$componentPath}.ml.php, ' .\n" .
            "        'layouts/{$componentPath}.ml.php, partials/{$componentPath}.ml.php)'\n" .
            "    );\n" .
            "}\n" .
            "?>\n";
    }

    /**
     * Parse HTML attributes from string into array
     * Handles both static and dynamic attributes
     */
    private function parseAttributes(string $attrStr): array
    {
        $attrs = [];

        // 1) key="value" pairs (including colon-bound like :tags="[...]")
        if (preg_match_all(
            '/([:@a-zA-Z0-9_:-]+)\s*=\s*"([^"]*)"/s',
            $attrStr,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $set) {
                $key = $set[1];
                $raw = $set[2];

                $isBound = str_starts_with($key, ':');
                if ($isBound) {
                    // Strip the leading colon for bound attributes
                    // :tags="['Blog']" → key = 'tags'
                    $key = substr($key, 1);
                }

                if ($isBound) {
                    // Bound attribute: treat value as raw PHP expression
                    // :tags="['Blog', 'Backend']"
                    // → 'tags' => ['Blog', 'Backend']
                    $attrs[$key] = $raw;
                    continue;
                }

                // Non-bound attributes:

                // Escaped expression: {{ $var }}
                if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/s', $raw, $ex)) {
                    $attrs[$key] = 'htmlspecialchars((string)(' . $ex[1] . " ?? ''), ENT_QUOTES, 'UTF-8')";
                    continue;
                }

                // Raw expression: {!! $var !!}
                if (preg_match('/^\{!!\s*(.+?)\s*!!\}$/s', $raw, $ex)) {
                    $attrs[$key] = '(' . $ex[1] . " ?? '')";
                    continue;
                }

                // Plain literal string
                $attrs[$key] = var_export($raw, true);
            }
        }

        // 2) Boolean attributes (e.g. disabled, required, highlight)
        if (preg_match_all('/\b([a-zA-Z0-9_:-]+)\b/', $attrStr, $boolMatches)) {
            foreach ($boolMatches[1] as $boolAttr) {
                // Skip already processed key="value" pairs and colon-prefixed keys
                if (!isset($attrs[$boolAttr]) && $boolAttr[0] !== ':') {
                    $attrs[$boolAttr] = 'true';
                }
            }
        }

        return $attrs;
    }

    /**
     * Convert @slot('name')…@endslot into PHP closures.
     * Ensures nested template syntax is fully parsed.
     */
    private function parseSlots(string $source): string
    {
        return preg_replace_callback(
            '/@slot\(["\']([^"\']+)["\']\)(.*?)@endslot/s',
            function (array $m) {
                $slot = $m[1];
                // Recursively parse the slot content
                $body = $this->parse($m[2]);

                return "\n<?php \$__component_slots = \$__component_slots ?? []; ?>\n" .
                    "<?php \$__component_slots['{$slot}'] = function() use (&\$__ml_scope) { 
                    \$__slot_data = \$__ml_scope->getCurrentScope();
                    extract(\$__slot_data);
                    ob_start();
                    ?>\n{$body}\n<?php 
                    return ob_get_clean();
                }; ?>\n";
            },
            $source
        );
    }

    /**
     * Convert <x-slot:name>…</x-slot:name> into PHP closures.
     * Ensures nested template syntax is fully parsed.
     */
    private function parseSlotTags(string $source): string
    {
        return preg_replace_callback(
            '/<x-slot:([a-zA-Z0-9_-]+)([^>]*)>(.*?)<\/x-slot:\1>/s',
            function (array $m) {
                $slot = $m[1];
                // Parse any attributes on the slot tag (for future use)
                // $attributes = $m[2];

                // Recursively parse the slot content
                $body = $this->parse($m[3]);

                return "\n<?php \$__component_slots = \$__component_slots ?? []; \$__component_slots['{$slot}'] = function() { 
                    if (isset(\$GLOBALS['__data']) && is_array(\$GLOBALS['__data'])) {
                        extract(\$GLOBALS['__data'], EXTR_SKIP);
                    }
                    ob_start();
                    ?>\n{$body}\n<?php 
                    return ob_get_clean();
                }; ?>\n";
            },
            $source
        );
    }

    /**
     * Convert @extends('name') into PHP inheritance logic.
     */
    private function parseExtends(string $source): string
    {
        return preg_replace_callback(
            '/@extends\(["\']([^"\']+)["\']\)/',
            function (array $m) {
                $layout = $m[1];
                $layoutPath = str_replace('.', '/', $layout);
                return "<?php \$__ml_extends = '{$layoutPath}'; ?>";
            },
            $source
        );
    }

    /**
     * Convert @section('name')...@endsection into PHP section definitions.
     */
    private function parseSections(string $source): string
    {
        return preg_replace_callback(
            '/@section\(["\']([^"\']+)["\']\)(.*?)@endsection/s',
            function (array $m) {
                $name = $m[1];
                $content = $this->parse($m[2]);

                return "\n<?php \$__ml_sections = \$__ml_sections ?? []; ?>\n" .
                    "<?php ob_start(); ?>\n{$content}\n<?php \$__ml_sections['{$name}'] = ob_get_clean(); ?>\n";
            },
            $source
        );
    }

    /**
     * Convert @yield('name') into PHP section output.
     */
    private function parseYields(string $source): string
    {
        return preg_replace_callback(
            '/@yield\(["\']([^"\']+)["\']\)/',
            function (array $m) {
                $name = $m[1];
                return "<?php echo \$__ml_sections['{$name}'] ?? ''; ?>";
            },
            $source
        );
    }

    /**
     * Remove @props and @param directives from the template output
     *
     * @param string $source Template source
     * @return string Template source without directives
     */
    public function removePropsDirectives(string $source): string
    {
        // Remove both @props and @param directives
        $source = preg_replace('/@props\s*\(\s*\[\s*.*?\s*\]\s*\)\s*(\r?\n)?/s', '', $source);
        $source = preg_replace('/@param\s*\(\s*\[\s*.*?\s*\]\s*\)\s*(\r?\n)?/s', '', $source);
        return $source;
    }
}