<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Contracts\ParserInterface;

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
class Parser implements ParserInterface
{
    public function parse(string $source): string
    {
        // 0. Validate structure before parsing
        $this->validateStructure($source);

        // 1. Pre-process: remove props
        $source = $this->removePropsDirectives($source);

        // Main iterative loop to handle nested structures without recursion bloat
        $previousSource = '';
        $iterationCount = 0;
        $maxIterations = 10;

        while ($source !== $previousSource && $iterationCount < $maxIterations) {
            $previousSource = $source;
            $iterationCount++;

            $source = $this->parseClassDirective($source);
            $source = $this->parseSlotTags($source);
            $source = $this->parseSlots($source);
            $source = $this->parseExtends($source);
            $source = $this->parseSections($source);
            $source = $this->parseYields($source);
            $source = $this->parseIncludes($source);
            $source = $this->parseComponents($source);
        }

        return (string)$source;
    }


    /**
     * Validate the structural integrity of the template (tags, slots, etc.)
     * @throws Exceptions\ParseException
     */
    private function validateStructure(string $source): void
    {
        // 1) Component Tag Balance
        // Match <x-name... excluding self-closing <x-name.../>
        preg_match_all('/<x-([a-zA-Z0-9_:.-]+)(?:[^>]*)(?<!\/)>/s', $source, $openTags, PREG_OFFSET_CAPTURE);
        preg_match_all('/<\/x-([a-zA-Z0-9_:.-]+)>/s', $source, $closeTags, PREG_OFFSET_CAPTURE);

        if (\count($openTags[0]) !== \count($closeTags[0])) {
            $offset = \count($openTags[0]) > \count($closeTags[0]) 
                ? (end($openTags[0])[1] ?? 0) 
                : (end($closeTags[0])[1] ?? 0);
            $line = \count(explode("\n", substr($source, 0, (int)$offset)));

            throw new \MonkeysLegion\Template\Exceptions\ParseException(
                "Mismatched component tags. Found " . count($openTags[0]) . " open and " . count($closeTags[0]) . " close tags.",
                'template.ml.php',
                $line
            );
        }

        // 2) Slot Directive Balance (@slot/@endslot)
        preg_match_all('/\@slot\b/s', $source, $openSlots, PREG_OFFSET_CAPTURE);
        preg_match_all('/\@endslot\b/s', $source, $closeSlots, PREG_OFFSET_CAPTURE);

        if (\count($openSlots[0]) !== \count($closeSlots[0])) {
            $offset = \count($openSlots[0]) > \count($closeSlots[0]) 
                ? (end($openSlots[0])[1] ?? 0) 
                : (end($closeSlots[0])[1] ?? 0);
            $line = \count(explode("\n", substr($source, 0, (int)$offset)));

            throw new \MonkeysLegion\Template\Exceptions\ParseException(
                "Mismatched @slot directives. Found " . count($openSlots[0]) . " @slot and " . count($closeSlots[0]) . " @endslot.",
                'template.ml.php',
                $line
            );
        }
    }

    /**
     * Parse :class directive for conditional CSS classes
     *
     * Syntax: :class="['btn', 'active' => $isActive, $variant]"
     */
    private function parseClassDirective(string $source): string
    {
        return (string)preg_replace_callback(
            '/:class\s*=\s*"([^"]+)"/s',
            function (array $m) {
                $expression = $m[1];
                $newlines = substr_count($m[0], "\n");
                // Remove any curly braces if present
                $expression = preg_replace('/^\{\{|\}\}$/', '', trim($expression));

                return 'class="<?= \\MonkeysLegion\\Template\\Support\\AttributeBag::conditional('
                    . $expression . ') ?>"' . str_repeat("\n", $newlines);
            },
            $source
        );
    }

    /**
     * Extract parameter declarations from component source
     * Supports both @props and @param syntax for backward compatibility
     *
     * @param string $source Component source code
     * @return array<string, mixed> Parameter declarations with default values
     */
    public function extractComponentParams(string $source): array
    {
        $params = [];

        // Try new @props syntax first: @props(['title' => 'Default', 'active' => false])
        if (preg_match('/@props\s*\(\s*\[\s*(.*?)\s*\]\s*\)/s', $source, $match)) {
            $paramString = $match[1];
            $params = $this->parseParamString($paramString);
        } elseif (preg_match('/@param\s*\(\s*\[\s*(.*?)\s*\]\s*\)/s', $source, $match)) {
            // Fall back to old @param syntax for backward compatibility
            $paramString = $match[1];
            $params = $this->parseParamString($paramString);
        }

        return $params;
    }

    /**
     * Parse parameter string into key-value array
     *
     * @param string $paramString
     * @return array<string, mixed>
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
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if ($value === 'null') {
            return null;
        }

        // Handle empty arrays
        if ($value === '[]') {
            return [];
        }

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
     * `@include('name')`
     * `@include('name', ['var' => $value])`
     */
    private function parseIncludes(string $source): string
    {
        return (string)preg_replace_callback(
            '/@include\(\s*["\']([^"\']+)["\']\s*(?:,\s*(\[[^\]]+\]))?\s*\)/s',
            function (array $m) {
                $viewName = $m[1];
                $data = $m[2] ?? '[]';
                $newlines = substr_count($m[0], "\n");

                // Use $this->render() to handle includes via the Renderer.
                // We merge the current scope data with the specific include data to support variable inheritance.
                return "<?php echo \$this->render('{$viewName}', array_merge(\\MonkeysLegion\\Template\\VariableScope::getCurrent()->getCurrentScope(), {$data}), true); ?>" . str_repeat("\n", $newlines);
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
        $source = (string)preg_replace_callback(
            '/<x-(?!slot:)([a-zA-Z0-9_:.-]+)([^>]*)\s*\/\s*>/s',
            function (array $m) {
                $openLines = substr_count($m[0], "\n");
                return $this->buildComponentCode($m[1], $m[2], '', true, $openLines, 0);
            },
            $source
        );

        // Then handle components with content
        // We use a regex that captures the tags and body
        $source = (string)preg_replace_callback(
            '/(<x-([a-zA-Z0-9_:.-]+)([^>]*)>)(.*?)(<\/x-\2>)/s',
            function (array $m) {
                // $m[1] is the opening tag
                // $m[2] is the name
                // $m[3] is the attributes
                // $m[4] is the inner content
                // $m[5] is the closing tag
                $openLines = substr_count($m[1], "\n");
                $closeLines = substr_count($m[5], "\n");
                return $this->buildComponentCode($m[2], $m[3], $m[4], false, $openLines, $closeLines);
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
        bool $selfClosing,
        int $openTagLines = 0,
        int $closeTagLines = 0
    ): string {
        // Parse attributes into associative array
        $attrs = $this->parseAttributes($attrStr);

        // Normalize colon-prefixed keys again just in case
        $normalized = [];
        foreach ($attrs as $k => $v) {
            if ($k !== '' && $k[0] === ':') {
                $attrName = substr($k, 1);
                // If the value is a quoted string (from var_export), strip quotes
                $expr = (is_string($v) && preg_match("/^'(.*)'$/s", $v, $m)) ? $m[1] : $v;
                $normalized[$attrName] = $expr;
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

        // Inner content is not parsed here; the main Parser loop will handle nesting
        $innerParsed = $inner;

        $setupReplacement = "<?php /* Component: {$name} */ \$__component_attrs = {$attrsCode}; \$__component_content = ''; " .
                 ($selfClosing ? "echo \$this->renderComponent(\$this->resolveComponent('{$name}'), \$__component_attrs + ['slots' => \\MonkeysLegion\\Template\\Support\\SlotCollection::fromArray(['__default' => ''])]); " : "ob_start(); ") .
                 "?>";
        
        $setup = $setupReplacement . str_repeat("\n", $openTagLines);

        if ($selfClosing) {
            return $setup;
        }

        $footerReplacement = "<?php \$__component_content = ob_get_clean(); \$__component_data = \$__component_attrs; " .
                  "\$__component_data['slots'] = \\MonkeysLegion\\Template\\Support\\SlotCollection::fromArray(array_merge(\$__component_slots ?? [], ['__default' => \$__component_content])); " .
                  "echo \$this->renderComponent(\$this->resolveComponent('{$name}'), \$__component_data); " .
                  "unset(\$__component_slots); ?>";
        
        $footer = $footerReplacement . str_repeat("\n", $closeTagLines);

        return $setup . $innerParsed . $footer;
    }

    /**
     * Parse HTML attributes from string into array
     * Handles both static and dynamic attributes
     *
     * @return array<string, mixed>
     */
    private function parseAttributes(string $attrStr): array
    {
        $attrs = [];

        // 1) key="value" pairs (including colon-bound like :tags="[...]")
        if (
            preg_match_all(
                '/([:@a-zA-Z0-9_:-]+)\s*=\s*"([^"]*)"/s',
                $attrStr,
                $matches,
                PREG_SET_ORDER
            )
        ) {
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
     */
    private function parseSlots(string $source): string
    {
        return (string)preg_replace_callback(
            '/(@slot\s*\(\s*["\']([^"\']+)["\']\s*\))(.*?)(\@endslot)/s',
            function (array $m) {
                $slot = $m[2];
                $body = $m[3];

                $openTag = "<?php \$__component_slots = \$__component_slots ?? []; \$__component_slots['{$slot}'] = function() use (&\$__ml_scope) { " .
                    "\$__slot_data = \$__ml_scope->getCurrentScope(); extract(\$__slot_data); ob_start(); ?>" . str_repeat("\n", substr_count($m[1], "\n"));
                
                $closeTag = "<?php return ob_get_clean(); }; ?>" . str_repeat("\n", substr_count($m[4], "\n"));

                return $openTag . $body . $closeTag;
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
        return (string)preg_replace_callback(
            '/(<x-slot:([a-zA-Z0-9_-]+)([^>]*)>)(.*?)(<\/x-slot:\2>)/s',
            function (array $m) {
                $slot = $m[2];
                $body = $m[4]; // Do not call $this->parse() here
                $openLines = substr_count($m[1], "\n");
                $closeLines = substr_count($m[5], "\n");

                $openTag = "<?php \$__component_slots = \$__component_slots ?? []; \$__component_slots['{$slot}'] = function() { " .
                    "if (isset(\$GLOBALS['__data']) && is_array(\$GLOBALS['__data'])) { extract(\$GLOBALS['__data'], EXTR_SKIP); } ob_start(); ?> " . str_repeat("\n", $openLines);
                
                $closeTag = "<?php return ob_get_clean(); }; ?>" . str_repeat("\n", $closeLines);

                return $openTag . $body . $closeTag;
            },
            $source
        );
    }

    /**
     * Convert @extends('name') into PHP inheritance logic.
     */
    private function parseExtends(string $source): string
    {
        return (string)preg_replace_callback(
            '/@extends\s*\(\s*["\']([^"\']+)["\']\s*\)/s',
            function (array $m) {
                $layout = $m[1];
                $layoutPath = str_replace('.', '/', $layout);
                $newlines = substr_count($m[0], "\n");
                return "<?php \$__ml_extends = '{$layoutPath}'; ?>" . str_repeat("\n", $newlines);
            },
            $source
        );
    }

    /**
     * Parse section directives
     */
    private function parseSections(string $source): string
    {
        // 1. Shorthand: @section('name', 'content')
        $source = (string)preg_replace_callback(
            '/@section\s*\(\s*["\']([^"\']+)["\']\s*,\s*["\']([^"\']*)["\']\s*\)/s',
            function (array $m) {
                $name = $m[1];
                $content = $m[2];
                $newlines = substr_count($m[0], "\n");
                return "<?php \$__ml_sections = \$__ml_sections ?? []; \$__ml_sections['{$name}'] = '{$content}'; ?>" . str_repeat("\n", $newlines);
            },
            $source
        );

        // 2. Block: @section('name')...@endsection
        // We capture the opening tag, the content, and the closing tag separately to preserve lines
        return (string)preg_replace_callback(
            '/(@section\s*\(\s*["\']([^"\']+)["\']\s*\))(.*?)(\@endsection)/s',
            function (array $m) {
                $name = $m[2];
                $content = $m[3]; // We don't call parse() here, the outer loop will handle nested things
                $openLines = substr_count($m[1], "\n");
                $closeLines = substr_count($m[4], "\n");
                
                return "<?php \$__ml_sections = \$__ml_sections ?? []; ob_start(); ?>" . str_repeat("\n", $openLines) . 
                       $content . 
                       "<?php \$__ml_sections['{$name}'] = ob_get_clean(); ?>" . str_repeat("\n", $closeLines);
            },
            $source
        );
    }

    /**
     * Convert @yield('name', 'default') into PHP
     */
    private function parseYields(string $source): string
    {
        return (string)preg_replace_callback(
            '/@yield\s*\(\s*["\']([^"\']+)["\']\s*(?:,\s*["\']([^"\']*)["\']\s*)?\)/s',
            function (array $m) {
                $name = $m[1];
                $default = $m[2] ?? '';
                $newlines = substr_count($m[0], "\n");
                return "<?php echo \$__ml_sections['{$name}'] ?? '{$default}'; ?>" . str_repeat("\n", $newlines);
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
        // Remove @props and @param directives while preserving newlines for accurate line mapping
        $source = (string)preg_replace_callback('/@props\s*\(\s*\[\s*.*?\s*\]\s*\)/s', function($m) {
            return '<?php ?>' . str_repeat("\n", substr_count($m[0], "\n"));
        }, $source);

        $source = (string)preg_replace_callback('/@param\s*\(\s*\[\s*.*?\s*\]\s*\)/s', function($m) {
            return '<?php ?>' . str_repeat("\n", substr_count($m[0], "\n"));
        }, $source);

        return $source;
    }
    /**
     * Extract dependencies (components, includes, layouts) from the source.
     * Useful for static analysis / linting.
     *
     * @param string $source
     * @return array{components: string[], includes: string[], layouts: string[]}
     */
    public function getDependencies(string $source): array
    {
        $dependencies = [
            'components' => [],
            'includes' => [],
            'layouts' => [],
        ];

        // 1. Components <x-name ...>
        if (preg_match_all('/<x-([a-zA-Z0-9_:.-]+)/', $source, $matches)) {
            $dependencies['components'] = array_unique($matches[1]);
        }

        // 2. Includes @include('name')
        if (preg_match_all('/@include\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches)) {
            $dependencies['includes'] = array_unique($matches[1]);
        }

        // 3. Layouts @extends('name')
        if (preg_match_all('/@extends\(\s*[\'"]([^\'"]+)[\'"]\)/', $source, $matches)) {
            $dependencies['layouts'] = array_unique($matches[1]);
        }

        return $dependencies;
    }
}
