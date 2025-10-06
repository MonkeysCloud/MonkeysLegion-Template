<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Parses advanced MLView directives before they are fed to Compiler.
 */
class Parser
{
    /**
     * Parse components and slots in the template source.
     * Ensures all template elements are recursively processed.
     */
    public function parse(string $source): string
    {
        // First, remove @param directives from the output
        $source = $this->removeParamDirectives($source);
        
        // Apply multiple parsing passes to ensure everything is parsed properly
        $previousSource = '';
        $iterationCount = 0;
        $maxIterations = 10; // Prevent infinite loops

        while ($source !== $previousSource && $iterationCount < $maxIterations) {
            $previousSource = $source;
            $iterationCount++;

            // Process in correct order - from inside out
            // 1) Handle slot tags first (x-slot:name)
            $source = $this->parseSlotTags($source);
            // 2) Then slot directives (@slot)
            $source = $this->parseSlots($source);
            // 3) Parse layout inheritance directives
            $source = $this->parseExtends($source);
            $source = $this->parseSections($source);
            $source = $this->parseYields($source);
            // 4) Parse includes
            $source = $this->parseIncludes($source);
            // 5) Components last so slots are already processed
            $source = $this->parseComponents($source);
        }

        return $source;
    }

    /**
     * Extract parameter declarations from component source
     * using the @param(['name' => 'default']) syntax
     * 
     * @param string $source Component source code
     * @return array Parameter declarations with default values
     */
    public function extractComponentParams(string $source): array
    {
        $params = [];
        
        // Match @param\(\s*\[\s*(.*?)\s*\]\s*\) declarations
        if (preg_match('/@param\(\s*\[\s*(.*?)\s*\]\s*\)/s', $source, $match)) {
            $paramString = $match[1];
            
            // Extract individual key-value pairs
            preg_match_all('/[\'"]([^\'"]*)[\'"]\s*=>\s*([^,]+)/', $paramString, $paramMatches, PREG_SET_ORDER);
            
            foreach ($paramMatches as $paramMatch) {
                $paramName = $paramMatch[1];
                $defaultValueStr = trim($paramMatch[2]);
                
                // Evaluate the default value
                $params[$paramName] = $this->evaluateDefaultValue($defaultValueStr);
            }
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
        // Handle quoted strings
        if (preg_match('/^[\'"](.*)[\'"]\s*$/', $value, $matches)) {
            return $matches[1];
        }
        
        // Handle booleans
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        
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
                    "    // Create a new scope for included template with only explicitly passed variables\n" .
                    "    \$__ml_scope = \\MonkeysLegion\\Template\\VariableScope::getCurrent();\n" .
                    "    \$__include_source = file_get_contents(\$__include_path);\n" .
                    "    \$__include_parser = new \\MonkeysLegion\\Template\\Parser();\n" .
                    "    \$__include_params = \$__include_parser->extractComponentParams(\$__include_source);\n" .
                    "    \$__ml_scope->createIsolatedScope(\$__data_include, \$__include_params);\n" .
                    "    \n" .
                    "    try {\n" .
                    "        // Parse and compile the included view\n" .
                    "        \$__include_parsed = \$__include_parser->parse(\$__include_source);\n" .
                    "        \$__include_compiler = new \\MonkeysLegion\\Template\\Compiler(\$__include_parser);\n" .
                    "        \$__include_compiled = \$__include_compiler->compile(\$__include_parsed, \$__include_path);\n" .
                    "        \$__include_compiled = substr(\$__include_compiled, strpos(\$__include_compiled, '?>') + 2);\n" .
                    "        \n" .
                    "        // Extract only variables from the include's scope\n" .
                    "        \$__isolated_data = \$__ml_scope->getCurrentScope();\n" .
                    "        extract(\$__isolated_data);\n" .
                    "        \n" .
                    "        // Evaluate the compiled include\n" .
                    "        eval('?>' . \$__include_compiled);\n" .
                    "    } finally {\n" .
                    "        // Always restore scope, even if there was an error\n" .
                    "        \$__ml_scope->popScope();\n" .
                    "    }\n" .
                    "} else {\n" .
                    "    trigger_error('Cannot find included view: {$viewName}', E_USER_WARNING);\n" .
                    "}\n" .
                    "?>";
            },
            $source
        );
    }

    /**
     * Convert <x-name attr="…">…</x-name> into PHP include snippets.
     */
    private function parseComponents(string $source): string
    {
        // First handle self-closing components
        $source = preg_replace_callback(
            '/<x-(?!slot:)([a-zA-Z0-9_:-]+)([^>]*)\s*\/\s*>/',
            function (array $m) {
                $name = $m[1];
                $attrStr = $m[2];

                // Parse attributes
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

                // Process component through full template pipeline before including
                return "\n<?php /* self-closing component: {$name} */ ?>\n" .
                    "<?php \$__component_slots = []; /* Component-specific slots */ ?>\n" .
                    "<?php \$__component_attrs = {$attrsCode}; ?>\n" .
                    "<?php \$__component_content = ''; /* No content for self-closing */ ?>\n" .
                    "<?php\n" .
                    "// Process component file through template pipeline\n" .
                    "foreach(['components','layouts','partials'] as \$__ml_dir) {\n" .
                    "    \$__ml_path = base_path('resources/views/'.\$__ml_dir.'/{$name}.ml.php');\n" .
                    "    if (is_file(\$__ml_path)) {\n" .
                    "        // Load component source to extract parameters\n" .
                    "        \$__component_source = file_get_contents(\$__ml_path);\n" .
                    "        \$__parser = new \\MonkeysLegion\\Template\\Parser();\n" .
                    "        \$__component_params = \$__parser->extractComponentParams(\$__component_source);\n" .
                    "        \n" .
                    "        // Create a strictly isolated scope with only declared parameters and passed attributes\n" .
                    "        \$__ml_scope = \\MonkeysLegion\\Template\\VariableScope::getCurrent();\n" .
                    "        \$__ml_scope->createIsolatedScope(\$__component_attrs, \$__component_params);\n" .
                    "        \n" .
                    "        try {\n" .
                    "            // Remove the @param directive from the source to avoid displaying it\n" .
                    "            \$__component_source = \$__parser->removeParamDirectives(\$__component_source);\n" .
                    "            // Parse and compile the component\n" .
                    "            \$__parsed = \$__parser->parse(\$__component_source);\n" .
                    "            \$__compiler = new \\MonkeysLegion\\Template\\Compiler(\$__parser);\n" .
                    "            \$__compiled = \$__compiler->compile(\$__parsed, \$__ml_path);\n" .
                    "            \$__compiled = substr(\$__compiled, strpos(\$__compiled, '?>') + 2);\n" .
                    "            \n" .
                    "            // Extract only the variables from component's scope\n" .
                    "            \$__isolated_data = \$__ml_scope->getCurrentScope();\n" .
                    "            extract(\$__isolated_data);\n" .
                    "            \n" .
                    "            // Set up component environment\n" .
                    "            \$slots = \$__component_slots;\n" .
                    "            \$slotContent = \$__component_content;\n" .
                    "            \n" .
                    "            // Execute the component in isolation\n" .
                    "            eval('?>' . \$__compiled);\n" .
                    "        } finally {\n" .
                    "            \$__ml_scope->popScope();\n" .
                    "        }\n" .
                    "        break;\n" .
                    "    }\n" .
                    "}\n" .
                    "?>\n";
            },
            $source
        );

        // Then handle standard components with a similar approach
        return preg_replace_callback(
            '/<x-(?!slot:)([a-zA-Z0-9_:-]+)([^>]*)>(.*?)<\/x-\\1>/s',
            function (array $m) {
                $name = $m[1];
                $attrStr = $m[2];
                $inner = $m[3];

                // Parse attributes
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

                // Fully parse the inner content recursively
                $innerParsed = $this->parse($inner);

                // Generate PHP snippet with full component processing
                return "\n<?php /* component: {$name} */ ?>\n" .
                    "<?php \$__component_slots = []; /* Component-specific slots */ ?>\n" .
                    "<?php \$__component_attrs = {$attrsCode}; ?>\n" .
                    "<?php ob_start(); ?>\n{$innerParsed}\n<?php \$__component_content = ob_get_clean(); ?>\n" .
                    "<?php\n" .
                    "// Process component file through template pipeline\n" .
                    "foreach(['components','layouts','partials'] as \$__ml_dir) {\n" .
                    "    \$__ml_path = base_path('resources/views/'.\$__ml_dir.'/{$name}.ml.php');\n" .
                    "    if (is_file(\$__ml_path)) {\n" .
                    "        // Load component source to extract parameters\n" .
                    "        \$__component_source = file_get_contents(\$__ml_path);\n" .
                    "        \$__parser = new \\MonkeysLegion\\Template\\Parser();\n" .
                    "        \$__component_params = \$__parser->extractComponentParams(\$__component_source);\n" .
                    "        \n" .
                    "        // Create a strictly isolated scope with only declared parameters and passed attributes\n" .
                    "        \$__ml_scope = \\MonkeysLegion\\Template\\VariableScope::getCurrent();\n" .
                    "        \$__ml_scope->createIsolatedScope(\$__component_attrs, \$__component_params);\n" .
                    "        \n" .
                    "        try {\n" .
                    "            // Remove the @param directive from the source to avoid displaying it\n" .
                    "            \$__component_source = \$__parser->removeParamDirectives(\$__component_source);\n" .
                    "            // Parse and compile the component\n" .
                    "            \$__parsed = \$__parser->parse(\$__component_source);\n" .
                    "            \$__compiler = new \\MonkeysLegion\\Template\\Compiler(\$__parser);\n" .
                    "            \$__compiled = \$__compiler->compile(\$__parsed, \$__ml_path);\n" .
                    "            \$__compiled = substr(\$__compiled, strpos(\$__compiled, '?>') + 2);\n" .
                    "            \n" .
                    "            // Process slots within component\n" .
                    "            \$slots = \$__component_slots;\n" .
                    "            \$slotContent = \$__component_content;\n" .
                    "            \n" .
                    "            // Extract only the variables from component's scope\n" .
                    "            \$__isolated_data = \$__ml_scope->getCurrentScope();\n" .
                    "            extract(\$__isolated_data);\n" .
                    "            \n" .
                    "            // Execute the component in isolation\n" .
                    "            eval('?>' . \$__compiled);\n" .
                    "        } finally {\n" .
                    "            \$__ml_scope->popScope();\n" .
                    "        }\n" .
                    "        break;\n" .
                    "    }\n" .
                    "}\n" .
                    "?>\n";
            },
            $source
        );
    }

    /**
     * Convert @slot('name')…@endslot into PHP closures in \$slots.
     * Ensures nested template syntax is fully parsed.
     */
    private function parseSlots(string $source): string
    {
        return preg_replace_callback(
            '/@slot\(["\']([^"\']+)["\']\)(.*?)@endslot/s',
            function (array $m) {
                $slot = $m[1];
                // Recursively parse the slot content to handle all directives
                $body = $this->parse($m[2]);

                // Create closure that properly processes any template syntax inside slots
                return "\n<?php \$__component_slots = \$__component_slots ?? []; ?>\n" .
                    "<?php \$__component_slots['{$slot}'] = function() use (&\$__ml_scope) { 
                    // Create separate scope for slot content
                    \$__slot_data = \$__ml_scope->getCurrentScope();
                    extract(\$__slot_data);
                    
                    // Output the slot content
                    ob_start();
                    ?>\n{$body}\n<?php 
                    return ob_get_clean();
                }; ?>\n";
            },
            $source
        );
    }

    /**
     * Convert <x-slot:name>…</x-slot:name> into PHP closures in \$slots.
     * Ensures nested template syntax is fully parsed.
     */
    private function parseSlotTags(string $source): string
    {
        return preg_replace_callback(
            '/<x-slot:([a-zA-Z0-9_-]+)([^>]*)>(.*?)<\/x-slot:\\1>/s',
            function (array $m) {
                $slot = $m[1];
                // Recursively parse the slot content
                $body = $this->parse($m[3]);

                // Simplify slot handling to avoid nested eval issues
                return "\n<?php \$slots = \$slots ?? []; \$slots['{$slot}'] = function() { 
                    // Access globals directly inside the closure
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
                // Convert dot notation to path
                $layoutPath = str_replace('.', '/', $layout);

                return "<?php \$__ml_extends = '{$layoutPath}'; ?>";
            },
            $source
        );
    }

    /**
     * Convert @section('name')...@endsection into PHP section definitions.
     * Ensures nested template syntax is fully parsed.
     */
    private function parseSections(string $source): string
    {
        return preg_replace_callback(
            '/@section\(["\']([^"\']+)["\']\)(.*?)@endsection/s',
            function (array $m) {
                $name = $m[1];
                // Recursively parse section content to handle all nested syntax
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
     * Remove @param directives from the template output
     * 
     * @param string $source Template source
     * @return string Template source without @param directives
     */
    public function removeParamDirectives(string $source): string
    {
        // This regex matches @param([...]) directive and removes it from the output
        return preg_replace('/@param\(\s*\[\s*.*?\s*\]\s*\)\s*(\r?\n)?/s', '', $source);
    }
}
