<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\Template\Contracts\CompilerInterface;
use MonkeysLegion\Template\Exceptions\ParseException;

/**
 * Enhanced Compiler for MLView templates.
 *
 * New Features:
 * - @json() for JSON output
 * - @js() for JavaScript-safe strings
 * - @class() helper for conditional classes
 * - @style() for conditional inline styles
 * - @checked/@selected/@disabled/@readonly form helpers
 * - @dd() dump and die
 * - @method() for HTTP method spoofing
 * - @csrf for CSRF tokens
 * - @env() environment checks
 * - @auth/@guest authentication checks
 * - @error() validation error display
 * - @old() old input values
 * - Better @dump with formatting
 *
 * Flow:
 *  1. Parser handles components/slots/layout → plain directives
 *  2. Pre-process code examples to protect syntax
 *  3. Apply new directives
 *  4. Apply custom directives (@upper, @lang, etc.)
 *  5. Convert {{ }} and {!! !!} echoes
 *  6. Apply control structures
 *  7. Post-process code examples
 *  8. Prepend PHP header
 */
class Compiler implements CompilerInterface
{
    private bool $strictMode = false;
    private \MonkeysLegion\Template\Support\DirectiveRegistry $registry;

    public function __construct(
        private \MonkeysLegion\Template\Contracts\ParserInterface $parser,
        ?\MonkeysLegion\Template\Support\DirectiveRegistry $registry = null
    ) {
        $this->registry = $registry ?? new \MonkeysLegion\Template\Support\DirectiveRegistry();
    }

    public function getRegistry(): \MonkeysLegion\Template\Support\DirectiveRegistry
    {
        return $this->registry;
    }

    public function setStrictMode(bool $enable): void
    {
        $this->strictMode = $enable;
    }

    /**
     * @param string $source Raw template input
     * @param string $path   Absolute path of original file (for debug)
     * @return string        PHP source ready to be cached & included
     */
    public function compile(string $source, string $path): string
    {
        // 0) Basic syntax validation
        $this->validateDirectiveBalance($source, $path);

        // 0.5) Pre-process code examples to protect template syntax
        $source = $this->preProcessCodeExamples($source);

        // 1) Run parser (handles <x-*>, @slot, @props, layouts, etc.)
        try {
            $php = $this->parser->parse($source);
        } catch (ParseException $e) {
            // If the parser threw a ParseException without a specific path, add it here
            if ($e->getFile() === 'template.ml.php') {
                throw new ParseException($e->getMessage(), $path, $e->getLine());
            }
            throw $e;
        }

        // 2) REMOVE Blade-style comments BEFORE we touch {{ }} or {!! !!}
        $php = $this->compileComments($php);
        $php = $this->compilePhpBlocks($php);

        // ... intermediate steps ...
        $php = $this->compileJson($php);
        $php = $this->compileJs($php);
        $php = $this->compileCustomDirectives($php);
        $php = $this->compileVerbatim($php);
        $php = $this->compileInject($php);
        $php = $this->compileStack($php);
        $php = $this->compileOnce($php);
        $php = $this->compileAware($php);
        $php = $this->compileIncludes($php);
        $php = $this->compileClassHelper($php);
        $php = $this->compileStyleHelper($php);
        $php = $this->compileChecked($php);
        $php = $this->compileSelected($php);
        $php = $this->compileDisabled($php);
        $php = $this->compileReadonly($php);
        $php = $this->compileCsrf($php);
        $php = $this->compileMethod($php);
        $php = $this->compileEnv($php);
        $php = $this->compileAuth($php);
        $php = $this->compileGuest($php);
        $php = $this->compileError($php);
        $php = $this->compileEndError($php);
        $php = $this->compileOld($php);
        $php = $this->compileUpper($php);
        $php = $this->compileLang($php);
        $php = $this->compileAttributeExpressions($php);
        $php = $this->compileEscapedEchoes($php);
        $php = $this->compileRawEchoes($php);
        $php = $this->compileDump($php);
        $php = $this->compileDd($php);
        $php = $this->compileEscapeDirective($php);
        $php = $this->compileConditionals($php);
        $php = $this->compileConditionalsSugar($php);
        $php = $this->compileLoops($php);

        // 14) Clean up excessive newlines
        // Disabled to maintain 1:1 line mapping
        // $php = (string)preg_replace('/\n{3,}/', "\n\n", $php);
        // $php = trim($php);

        // 15) Post-process to restore protected code examples
        $php = $this->postProcessCodeExamples($php);

        $pathHeader = "/**PATH {$path} ENDPATH**/";
        $useHeader = "use MonkeysLegion\\Template\\Support\\AttributeBag;";

        $fullHeader = "<?php\n" .
                      "{$pathHeader}\n" .
                      "{$useHeader}\n" .
                      "\n"; // Line 4 reserved

        // If the content starts strictly with <?php, we merge it with our header
        // Otherwise, we close the header to avoid double <?php or syntax errors
        if (str_starts_with($php, '<?php')) {
            $php = (string)preg_replace('/^<\?php(\s*)/i', '', $php);
        } else {
            // Replace the last newline with a closing tag + newline to keep 4 lines
            $fullHeader = substr($fullHeader, 0, -1) . "?>\n";
        }

        $compiled = $fullHeader . $php;
        
        // 16) Post-compilation syntax validation
        $this->validateCompiledSyntax($compiled, $path);

        return $compiled;
    }

    /**
     * Perform a syntax check on the compiled PHP code.
     * Throws ParseException if syntax is invalid.
     */
    private function validateCompiledSyntax(string $php, string $path): void
    {
        // Skip validation if we don't have shell access or if we want to be fast?
        // Actually, for compilation it's worth it.
        
        // We use a temporary file to lint the code safely
        $tmpFile = tempnam(sys_get_temp_dir(), 'ml_lint_');
        file_put_contents($tmpFile, $php);

        $output = [];
        $returnVar = 0;
        // Run php -l (lint)
        exec("php -l " . escapeshellarg($tmpFile) . " 2>&1", $output, $returnVar);
        
        unlink($tmpFile);

        if ($returnVar !== 0) {
            $message = $output[0] ?? 'Unknown PHP syntax error';
            
            // Extract line number from "Errors parsing ... on line X"
            if (preg_match('/on line (\d+)/', $message, $m)) {
                $errorLine = (int)$m[1];
                // Map back using our fixed 4-line header
                $originalLine = max(1, $errorLine - 4);
                
                throw new ParseException(
                    "PHP Syntax Error: " . $message . " in " . basename($path),
                    $path,
                    $originalLine
                );
            }

            throw new ParseException(
                "PHP Syntax Error: " . $message . " in " . basename($path),
                $path,
                1
            );
        }
    }


    /**
     * Compile @json() directive
     * Outputs JSON-encoded data
     */
    private function compileJson(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@json\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/xs',
            fn(array $m) => "<?= json_encode({$m[1]}, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>" . str_repeat("\n", substr_count($m[0], "\n")),
            $php
        );
    }

    /**
     * Compile @js() directive
     * Outputs JavaScript-safe string (escapes quotes and newlines)
     */
    private function compileJs(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@js\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/xs',
            fn(array $m) => "<?= json_encode({$m[1]}, JSON_UNESCAPED_UNICODE) ?>" . str_repeat("\n", substr_count($m[0], "\n")),
            $php
        );
    }

    /**
     * Compile @class() helper
     * Alternative syntax for conditional classes
     * `@class(['btn', 'active' => $isActive])`
     */
    private function compileClassHelper(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@class\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/xs',
            fn(array $m) => "<?= \\MonkeysLegion\\Template\\Support\\AttributeBag::conditional({$m[1]}) ?>" . str_repeat("\n", substr_count($m[0], "\n")),
            $php
        );
    }

    /**
     * Compile @style() helper
     * Conditional inline styles
     * `@style(['display: block', 'color: red' => $hasError])`
     */
    private function compileStyleHelper(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@style\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/xs',
            function (array $m) {
                return "<?php \$__styles = []; " .
                    "foreach ({$m[1]} as \$__k => \$__v) { " .
                    "if (is_int(\$__k) && \$__v) \$__styles[] = \$__v; " .
                    "elseif (\$__v) \$__styles[] = \$__k; " .
                    "} echo implode('; ', \$__styles); ?>" . str_repeat("\n", substr_count($m[0], "\n"));
            },
            $php
        );
    }

    /**
     * Compile @checked() directive
     * Outputs 'checked' if condition is true
     */
    private function compileChecked(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@checked\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/x',
            fn(array $m) => "<?= ({$m[1]}) ? 'checked' : '' ?>",
            $php
        );
    }

    /**
     * Compile @selected() directive
     * Outputs 'selected' if condition is true
     */
    private function compileSelected(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@selected\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/x',
            fn(array $m) => "<?= ({$m[1]}) ? 'selected' : '' ?>",
            $php
        );
    }

    /**
     * Compile @disabled() directive
     * Outputs 'disabled' if condition is true
     */
    private function compileDisabled(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@disabled\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/x',
            fn(array $m) => "<?= ({$m[1]}) ? 'disabled' : '' ?>",
            $php
        );
    }

    /**
     * Compile @readonly() directive
     * Outputs 'readonly' if condition is true
     */
    private function compileReadonly(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@readonly\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/xs',
            fn(array $m) => "<?= ({$m[1]}) ? 'readonly' : '' ?>" . str_repeat("\n", substr_count($m[0], "\n")),
            $php
        );
    }

    /**
     * Compile @csrf directive
     * Outputs CSRF token field (customize for your framework)
     */
    private function compileCsrf(string $php): string
    {
        return (string)preg_replace(
            '/@csrf\b/',
            "<?php if (function_exists('csrf_field')) { echo csrf_field(); } " .
            "elseif (isset(\$_SESSION['csrf_token'])) { " .
            "echo '<input type=\"hidden\" name=\"_token\" value=\"' . htmlspecialchars(\$_SESSION['csrf_token']) . '\">'; " .
            "} ?>",
            $php
        );
    }

    /**
     * Compile @method() directive
     * Outputs hidden method field for HTTP method spoofing
     */
    private function compileMethod(string $php): string
    {
        return (string)preg_replace_callback(
            '/@method\(["\'](.+?)["\']\)/',
            fn(array $m) => "<?= '<input type=\"hidden\" name=\"_method\" value=\"{$m[1]}\">' ?>",
            $php
        );
    }

    /**
     * Compile @env() directive
     * Check environment
     */
    private function compileEnv(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@env\(["\'](.+?)["\']\)/',
            fn(array $m) =>
            "<?php if ((\$_ENV['APP_ENV'] ?? \$_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') === '{$m[1]}'): ?>",
            $php
        );

        $php = (string)preg_replace('/@endenv\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile @auth directive
     * Check if user is authenticated (customize for your framework)
     */
    private function compileAuth(string $php): string
    {
        $php = (string)preg_replace(
            '/@auth\b/',
            "<?php if (function_exists('auth') && auth()->check()): ?>",
            $php
        );

        $php = (string)preg_replace('/@endauth\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile @guest directive
     * Check if user is not authenticated
     */
    private function compileGuest(string $php): string
    {
        $php = (string)preg_replace(
            '/@guest\b/',
            "<?php if (!function_exists('auth') || !auth()->check()): ?>",
            $php
        );

        $php = (string)preg_replace('/@endguest\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile @error() directive
     * Display validation error for a field
     */
    private function compileError(string $php): string
    {
        return (string)preg_replace_callback(
            '/@error\(["\'](.+?)["\']\)/',
            function (array $m) {
                $field = $m[1];
                return "<?php if (isset(\$errors) && \$errors->has('{$field}')): " .
                    "\$__error_message = \$errors->first('{$field}'); ?>";
            },
            $php
        );
    }

    /**
     * Compile @enderror directive
     */
    private function compileEndError(string $php): string
    {
        return (string)preg_replace('/@enderror\b/', '<?php endif; ?>', $php);
    }

    /**
     * Compile @old() directive
     * Get old input value
     */
    private function compileOld(string $php): string
    {
        return (string)preg_replace_callback(
            '/@old\(["\'](.+?)["\'](?:\s*,\s*(.+?))?\)/',
            function (array $m) {
                $field = $m[1];
                $default = $m[2] ?? "''";
                return "<?= htmlspecialchars((string)(function_exists('old') ? old('{$field}', {$default}) : " .
                    "(\$_POST['{$field}'] ?? {$default})), ENT_QUOTES, 'UTF-8') ?>";
            },
            $php
        );
    }

    /**
     * Compile @upper() directive
     */
    private function compileUpper(string $php): string
    {
        return (string)preg_replace_callback(
            '/@upper\(([^)]+)\)/',
            fn(array $m) => "<?= htmlspecialchars(strtoupper((string)({$m[1]} ?? '')), ENT_QUOTES, 'UTF-8') ?>",
            $php
        );
    }

    /**
     * Compile @lang() directive
     */
    private function compileLang(string $php): string
    {
        return (string)preg_replace_callback(
            "/@lang\((?:'|\")(.+?)(?:'|\")(?:\s*,\s*(\[[^\]]*\]))?\)/",
            function (array $m) {
                $key = $m[1];
                $replace = $m[2] ?? '[]';
                return "<?= trans('{$key}', {$replace}) ?>";
            },
            $php
        );
    }

    /**
     * Process expressions in HTML attributes
     */
    private function compileAttributeExpressions(string $php): string
    {
        // Supporting loose syntax: { { ... } } or { ! ! ... ! ! }
        $pattern = '/<([^>]+?)(\s+[a-zA-Z0-9_:-]+\s*=\s*["\'])([^"\']*?)(\{\s*\{\s*|\{\s*!\s*!\s*)(.*?)(\s*\}\s*\}|\s*!\s*!\s*\})([^"\']*?)(["\'])/s';

        return (string)preg_replace_callback(
            $pattern,
            function (array $m) {
                $tagStart = $m[1];
                $attrStart = $m[2];
                $beforeExpr = $m[3];
                $exprOpen = $m[4];
                $exprContent = $m[5];
                $exprClose = $m[6];
                $afterExpr = $m[7];
                $attrClose = $m[8];

                // Determine if escaped by checking for absence of '!' in the opener
                $isEscaped = !str_contains($exprOpen, '!');

                $phpOutput = $isEscaped
                    ? "<?= \\MonkeysLegion\\Template\\Support\\Escaper::attr({$exprContent}) ?>"
                    : "<?= {$exprContent} ?? '' ?>";

                return '<' . $tagStart . $attrStart . $beforeExpr . $phpOutput . $afterExpr . $attrClose;
            },
            $php
        );
    }

    /**
     * Compile escaped echoes {{ }}.
     *
     * - Normal variables: escaped with htmlspecialchars
     *      {{ $title }} → <?= htmlspecialchars((string)($title ?? ''), ENT_QUOTES, 'UTF-8') ?>
     *
     * - Slots ($slots / $slot): RAW HTML
     *      {{ $slots->header }} → <?= (string)($slots->header ?? '') ?>
     *      {{ $slot }}          → <?= (string)($slot ?? '') ?>
     *
     * - Attribute bags ($attrs / $attributes...): RAW HTML
     *      {{ $attrs->merge([...]) }} → <?= (string)($attrs->merge([...]) ?? '') ?>
     */
    private function compileEscapedEchoes(string $php): string
    {
        // Support { { ... } }
        return (string)preg_replace_callback(
            '/\{\s*\{\s*(.+?)\s*\}\s*\}/s',
            static function (array $m): string {
                $expr = trim($m[1]);

                // 1) Slots: raw HTML
                if (preg_match('/^\$slots->[A-Za-z_][A-Za-z0-9_]*$/', $expr)) {
                    // e.g. {{ $slots->header }}
                    return "<?= (string)({$expr} ?? '') ?>";
                }

                if ($expr === '$slot') {
                    // e.g. {{ $slot }}
                    return "<?= (string)({$expr} ?? '') ?>";
                }

                // 2) Attribute bags: $attrs, $attributes, etc. → raw HTML
                if (preg_match('/^\$(attrs|attributes)\b/', $expr)) {
                    // e.g. {{ $attrs->merge(['class' => 'navbar']) }}
                    return "<?= (string)({$expr} ?? '') ?>";
                }

                // 3) Everything else: escaped safely
                // Check for filters: $var | filter
                if (str_contains($expr, '|')) {
                    $parts = explode('|', $expr);
                    $first = array_shift($parts);
                    $code = trim($first);

                    // Simple parser for filters
                    // Format: {{ "hello" | upper | limit:5 }}
                    // Note: This simple split breaks if '|' is inside strings/arrays. 
                    // For robust filter parsing a lexer is needed. We will do a basic one for now or regex.
                    // Let's assume user is careful or we improve regex.

                    // Actually, let's use a smarter loop or just regex for each filter
                    // But we already exploded.

                    foreach ($parts as $part) {
                        $part = trim($part);
                        if (empty($part)) continue;

                        // Check if it has arguments: limit:5 or limit(5)
                        // Supporting limit:5 syntax like Twig/Liquid? Or limit(5)?
                        // Let's support Twig-like `filterName` or `filterName(...)`.
                        // Actually the user requirements said "Twig-like filters". Twig uses `| filter(arg)`.

                        // Let's assume strict function call syntax or just name.
                        // $var | upper => strtoupper($var)
                        // If custom filter: $registry->filters['upper']($var)

                        // To inject into PHP:
                        // We need to resolve the callable NAME.
                        // But custom filters are in the registry, not global functions.
                        // So we generate: $this->registry->getFilters()['name']($value, ...args)

                        // Parse name and args
                        if (preg_match('/^(\w+)(?:\((.*)\))?$/', $part, $fm)) {
                            $name = $fm[1];
                            $args = $fm[2] ?? '';

                            $code = "(\$this->getRegistry()->hasFilter('{$name}') " .
                                "? call_user_func(\$this->getRegistry()->getFilters()['{$name}'], {$code}" . ($args ? ", $args" : "") . ") " .
                                ": \\MonkeysLegion\\Template\\Support\\Escaper::checkStrictRaw('Filter {$name} not found and strict mode enabled'))";
                        }
                    }

                    return "<?= \\MonkeysLegion\\Template\\Support\\Escaper::html({$code}) ?>";
                }

                return "<?= \\MonkeysLegion\\Template\\Support\\Escaper::html({$expr}) ?>";
            },
            $php
        );
    }

    /**
     * Compile raw echoes {!! !!}
     */
    private function compileRawEchoes(string $php): string
    {
        // Support { ! ! ... ! ! }
        return (string)preg_replace_callback(
            '/\{\s*!\s*!\s*(.+?)\s*!\s*!\s*\}/s',
            function (array $m): string {
                $content = $m[1];
                if ($this->strictMode) {
                    return "<?= \\MonkeysLegion\\Template\\Support\\Escaper::checkStrictRaw({$content}) ?>";
                }
                return "<?= {$content} ?? '' ?>";
            },
            $php
        );
    }

    /**
     * Enhanced @dump() directive with better formatting
     */
    private function compileDump(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@dump\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/sx',
            function (array $m) {
                return "<?php " .
                    "echo '<div style=\"background:#f5f5f5;border:1px solid #ddd;padding:1rem;margin:1rem 0;border-radius:4px;\">';" .
                    "echo '<pre style=\"margin:0;font-family:monospace;font-size:14px;\">';" .
                    "var_dump({$m[1]});" .
                    "echo '</pre></div>'; " .
                    "?>";
            },
            $php
        );
    }

    /**
     * Compile @dd() directive (dump and die)
     */
    private function compileDd(string $php): string
    {
        return (string)preg_replace_callback(
            '/\@dd\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/sx',
            function (array $m) {
                return "<?php " .
                    "echo '<div style=\"background:#f5f5f5;border:1px solid #ddd;padding:1rem;margin:1rem 0;border-radius:4px;\">';" .
                    "echo '<h3 style=\"margin:0 0 0.5rem;color:#c00;\">Dump and Die</h3>';" .
                    "echo '<pre style=\"margin:0;font-family:monospace;font-size:14px;\">';" .
                    "var_dump({$m[1]});" .
                    "echo '</pre></div>'; " .
                    "exit(1); " .
                    "?>";
            },
            $php
        );
    }

    /**
     * Compile control structures (if, elseif, else, endif).
     *
     * We only compile directives that appear standalone on their own line:
     *   `@if(...)`
     *   `@elseif(...)`
     *   `@else`
     *   `@endif`
     *
     * This avoids the nested-parentheses bug and stray ")" characters.
     */
    private function compileConditionals(string $php): string
    {
        // Recursive pattern for balanced parentheses
        $expr = '\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)';

        // @if (condition)
        $php = (string)preg_replace_callback(
            "/@if\b{$expr}/sx",
            fn($m) => "<?php if ({$m[1]}): ?>",
            $php
        );

        // @elseif (condition)
        $php = (string)preg_replace_callback(
            "/@elseif\b{$expr}/sx",
            fn($m) => "<?php elseif ({$m[1]}): ?>",
            $php
        );

        // @else
        $php = (string)preg_replace('/@else\b/', '<?php else: ?>', $php);

        // @endif
        $php = (string)preg_replace('/@endif\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile conditional sugars: @unless, @isset, @empty, @switch, @case, @default
     */
    private function compileConditionalsSugar(string $php): string
    {
        // Recursive pattern for balanced parentheses
        $expr = '\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)';

        // @unless(cond) -> if (! (cond))
        $php = (string)preg_replace_callback("/@unless\b{$expr}/sx", fn($m) => "<?php if (! ({$m[1]})): ?>", $php);
        $php = (string)preg_replace('/@endunless\b/', '<?php endif; ?>', $php);

        // @isset(var) -> if (isset(var))
        $php = (string)preg_replace_callback("/@isset\b{$expr}/sx", fn($m) => "<?php if (isset({$m[1]})): ?>", $php);
        $php = (string)preg_replace('/@endisset\b/', '<?php endif; ?>', $php);

        // @empty(var) -> if (empty(var))
        $php = (string)preg_replace_callback("/@empty\b{$expr}/sx", fn($m) => "<?php if (empty({$m[1]})): ?>", $php);
        $php = (string)preg_replace('/@endempty\b/', '<?php endif; ?>', $php);

        // @switch($var)
        $php = (string)preg_replace_callback("/\s*@switch\b{$expr}/sx", fn($m) => "<?php switch({$m[1]}): ?>", $php);
        $php = (string)preg_replace('/@endswitch\b/', '<?php endswitch; ?>', $php);

        // @case('val') - strip leading whitespace to avoid T_INLINE_HTML
        $php = (string)preg_replace('/\s*@case\b\s*\((.*?)\)/s', '<?php case $1: ?>', $php);
        $php = (string)preg_replace('/\s*@default\b/', '<?php default: ?>', $php);

        // @break - must be in PHP mode
        $php = (string)preg_replace('/@break\b/', '<?php break; ?>', $php);

        return $php;
    }

    /**
     * Compile loops (foreach, for, while) with $loop variable support
     */
    private function compileLoops(string $php): string
    {
        // Support nested parentheses in loop headers
        // Use negative lookbehind (?<!@) to ignore escaped @@directives
        $foreachPattern = '/(?<!@)\@foreach\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/sx';
        $forPattern     = '/(?<!@)\@for\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/sx';
        $whilePattern   = '/(?<!@)\@while\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/sx';

        // @foreach with $loop variable
        $php = (string)preg_replace_callback($foreachPattern, function ($m) {
            $expression = $m[1];
            $newlines = substr_count($m[0], "\n");
            
            if (preg_match('/^(.*)\s+as\s+(.*)$/is', $expression, $parts)) {
                $iterable = $parts[1];
                return "<?php \$__currentLoopData = {$iterable}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as {$parts[2]}): \$loop = \$this->getLastLoop(); ?>" . str_repeat("\n", $newlines);
            }
            return "<?php foreach({$expression}): ?>" . str_repeat("\n", $newlines);
        }, $php);

        // End foreach
        $php = (string)preg_replace_callback('/(?<!@)@endforeach\b/s', function($m) {
            return "<?php \$this->getLastLoop()->tick(); endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); ?>" . str_repeat("\n", substr_count($m[0], "\n"));
        }, $php);

        // @for
        $php = (string)preg_replace_callback($forPattern, function($m) {
            return "<?php for ({$m[1]}): ?>" . str_repeat("\n", substr_count($m[0], "\n"));
        }, $php);
        
        $php = (string)preg_replace_callback('/@endfor\b/s', function($m) {
            return "<?php endfor; ?>" . str_repeat("\n", substr_count($m[0], "\n"));
        }, $php);

        // @while
        $php = (string)preg_replace_callback($whilePattern, function($m) {
            return "<?php while ({$m[1]}): ?>" . str_repeat("\n", substr_count($m[0], "\n"));
        }, $php);
        
        $php = (string)preg_replace_callback('/@endwhile\b/s', function($m) {
            return "<?php endwhile; ?>" . str_repeat("\n", substr_count($m[0], "\n"));
        }, $php);

        // Loop control
        $php = (string)preg_replace('/@break\b/', '<?php break; ?>', $php);
        $php = (string)preg_replace('/@continue\b/', '<?php continue; ?>', $php);

        return $php;
    }

    /**
     * Directive: inject('service', 'App\Service')
     */
    private function compileInject(string $php): string
    {
        return (string)preg_replace_callback(
            '/@inject\(\s*[\'"](.+?)[\'"]\s*,\s*[\'"](.+?)[\'"]\s*\)/',
            function ($m) {
                // $1 = variable name (e.g. 'metrics')
                // $2 = service class/id (e.g. 'App\Services\Metrics')
                // We presume user has a container or we use 'new' if simple?
                // For now, let's assume a generic `app()` helper or `Container::getInstance()->make()`.
                // If neither exists, we might fallback to `new $class`.
                // Let's assume `resolve($class)` or provided $__container?
                // Safest default for this package: try global function `app()`, then `resolve()`, then `new`.
                return "<?php \${$m[1]} = function_exists('app') ? app('{$m[2]}') : (function_exists('resolve') ? resolve('{$m[2]}') : new \\{$m[2]}()); ?>";
            },
            $php
        );
    }

    /**
     * Directive: stack('js'), push('js'), prepend('js')
     */
    private function compileStack(string $php): string
    {
        // @stack('name') -> yieldPush('name')
        $php = (string)preg_replace_callback(
            '/@stack\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?= \$this->yieldPush('{$m[1]}') ?>",
            $php
        );

        // @push('name')
        $php = (string)preg_replace_callback(
            '/@push\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?php \$this->startPush('{$m[1]}'); ?>",
            $php
        );
        $php = (string)preg_replace('/@endpush\b/', "<?php \$this->stopPush(); ?>", $php);

        // @prepend('name')
        $php = (string)preg_replace_callback(
            '/@prepend\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?php \$this->startPrepend('{$m[1]}'); ?>",
            $php
        );
        $php = (string)preg_replace('/@endprepend\b/', "<?php \$this->stopPrepend(); ?>", $php);

        return $php;
    }

    /**
     * Directive: once ... endonce
     */
    private function compileOnce(string $php): string
    {
        return (string)preg_replace_callback(
            '/@once(.*?)@endonce/s',
            function ($m) {
                // We need a unique hash for the content or location.
                // Since this is compile time, we can use md5 of the content + random salt?
                // But wait, the content might be dynamic.
                // The hash should be based on the location in the file (idempotency by call site).
                // Or simply md5($m[1]). If two @once blocks have identical content, should they both run?
                // Usually @once means "run this block only once per request".
                // If I have 2 components with same @once code, it should only run once TOTAL.
                $hash = md5($m[1]);
                return "<?php if (\$this->addOnceHash('{$hash}')): ?>{$m[1]}<?php endif; ?>";
            },
            $php
        );
    }

    /**
     * Directive: verbatim ... endverbatim
     */
    private function compileVerbatim(string $php): string
    {
        return (string)preg_replace_callback(
            '/@verbatim(.*?)@endverbatim/s',
            function ($m) {
                $content = $m[1];

                // Protect {{ }}
                $content = str_replace('{{', '___MLVIEW_OPEN_CURLY___', $content);
                $content = str_replace('}}', '___MLVIEW_CLOSE_CURLY___', $content);

                // Protect {!! !!}
                $content = str_replace('{!!', '___MLVIEW_OPEN_RAW___', $content);
                $content = str_replace('!!}', '___MLVIEW_CLOSE_RAW___', $content);

                // Protect {{-- --}}
                $content = str_replace('{{--', '___MLVIEW_COMMENT_OPEN___', $content);
                $content = str_replace('--}}', '___MLVIEW_COMMENT_CLOSE___', $content);

                // Protect @ directives
                $content = (string)preg_replace('/@([a-zA-Z0-9_]+)/', '___MLVIEW_AT___$1', $content);

                return $content;
            },
            $php
        );
    }

    /**
     * Directive: aware(['key' => 'default'])
     */
    private function compileAware(string $php): string
    {
        // @aware(['color' => 'gray'])
        // We want to inject these variables from PARENT scopes if missing in current.
        // This is tricky during compilation because scoping is runtime.
        // But we generate runtime code:
        // foreach ($defaults as $k => $v) $k = $scope->getAware($k, $v);

        return (string)preg_replace_callback(
            '/@aware\((.+?)\)/',
            function ($m) {
                return "<?php foreach ({$m[1]} as \$__k => \$__v) { \$\$__k = \MonkeysLegion\Template\VariableScope::getCurrent()->getAware(\$__k, \$__v); } ?>";
            },
            $php
        );
    }

    /**
     * @includeWhen, @includeUnless, @includeFirst, @each
     */
    private function compileIncludes(string $php): string
    {
        // @includeWhen($bool, 'view', ['data'])
        $php = (string)preg_replace_callback(
            '/@includeWhen\((.+?),\s*[\'"](.+?)[\'"](?:,\s*(.+?))?\)/',
            fn($m) => "<?php if({$m[1]}) echo \$this->render('{$m[2]}', " . ($m[3] ?? '[]') . "); ?>",
            $php
        );

        // @includeUnless($bool, 'view', ['data'])
        $php = (string)preg_replace_callback(
            '/@includeUnless\((.+?),\s*[\'"](.+?)[\'"](?:,\s*(.+?))?\)/',
            fn($m) => "<?php if(!({$m[1]})) echo \$this->render('{$m[2]}', " . ($m[3] ?? '[]') . "); ?>",
            $php
        );

        // @each('view', $collection, 'variable', 'emptyView')
        $php = (string)preg_replace_callback(
            '/@each\([\'"](.+?)[\'"]\s*,\s*(.+?)\s*,\s*[\'"](.+?)[\'"](?:\s*,\s*[\'"](.+?)[\'"])?\)/',
            function ($m) {
                $view = $m[1];
                $iterable = $m[2];
                $var = $m[3];
                $empty = $m[4] ?? 'null';

                // We loop and render.
                return "<?php 
                    \$__empty = true;
                    foreach({$iterable} as \$__item) {
                        \$__empty = false;
                        echo \$this->render('{$view}', ['{$var}' => \$__item]);
                    }
                    if (\$__empty && '{$empty}' !== 'null') {
                        echo \$this->render('{$empty}');
                    }
                ?>";
            },
            $php
        );

        return $php;
    }

    /**
     * Compile comments {{-- comment --}}
     */
    private function compileComments(string $php): string
    {
        return (string)preg_replace('/\{\{--.*?--\}\}/s', '', $php);
    }

    /**
     * Compile `@php ... @endphp` blocks and inline `@php(...)`
     *
     * Example:
     *   `@php`
     *       $x = 1;
     *       dump($x);
     *   `@endphp`
     *
     *   `@php($foo = 'bar')`
     */
    private function compilePhpBlocks(string $php): string
    {
        // Handle inline @php(...)
        $php = (string)preg_replace_callback(
            '/@php\s*\((.*?)\)/s',
            fn(array $m) => "<?php {$m[1]}; ?>",
            $php
        );

        // Replace @php with opening PHP tag (support block syntax)
        $php = (string)preg_replace('/@php\b/', '<?php', $php);

        // Replace @endphp with closing PHP tag (support block syntax)
        $php = (string)preg_replace('/@endphp\b/', '?>', $php);

        return $php;
    }

    /**
     * Pre-process code blocks to protect template syntax
     */
    private function preProcessCodeExamples(string $source): string
    {
        // First process explicit escapes (@@ to represent literal @)
        $source = (string)preg_replace('/@@([a-zA-Z0-9_]+)/', '___MLVIEW_ESCAPED_AT___$1', $source);

        // Process code blocks
        $source = (string)preg_replace_callback(
            '/<code[^>]*>(.*?)<\/code>/s',
            function (array $matches) {
                $code = $matches[1];

                // Capture attributes
                $attributes = '';
                if (preg_match('/<code([^>]*)>/', $matches[0], $attrMatches)) {
                    $attributes = $attrMatches[1];
                }

                // Protect double-escaped @
                $code = (string)preg_replace('/@@([a-zA-Z0-9_]+)/', '___MLVIEW_DOUBLE_AT___$1', $code);

                // Replace template syntax
                $code = str_replace('{{', '___MLVIEW_OPEN_CURLY___', $code);
                $code = str_replace('}}', '___MLVIEW_CLOSE_CURLY___', $code);
                $code = str_replace('{!!', '___MLVIEW_OPEN_RAW___', $code);
                $code = str_replace('!!}', '___MLVIEW_CLOSE_RAW___', $code);
                $code = str_replace('{{--', '___MLVIEW_COMMENT_OPEN___', $code);
                $code = str_replace('--}}', '___MLVIEW_COMMENT_CLOSE___', $code);

                // Protect all @ directives
                $directives = [
                    'props', 'param', 'extends', 'section', 'endsection', 'yield',
                    'slot', 'endslot', 'dump', 'dd', 'if', 'elseif', 'else', 'endif',
                    'foreach', 'endforeach', 'for', 'endfor', 'while', 'endwhile',
                    'lang', 'upper', 'include', 'php', 'break', 'continue',
                    'json', 'js', 'class', 'style', 'checked', 'selected', 'disabled', 'readonly',
                    'csrf', 'method', 'env', 'endenv', 'auth', 'endauth', 'guest', 'endguest',
                    'error', 'enderror', 'old'
                ];

                foreach ($directives as $directive) {
                    $code = (string)preg_replace('/@' . $directive . '\b/', '___MLVIEW_DIRECTIVE___' . $directive, $code);
                }

                // Generic @ protection
                $code = (string)preg_replace('/@([a-zA-Z0-9_]+)/', '___MLVIEW_AT___$1', $code);

                return '<code' . $attributes . '>' . $code . '</code>';
            },
            $source
        );

        return (string)$source;
    }

    /**
     * Post-process to restore protected template syntax
     */
    private function postProcessCodeExamples(string $php): string
    {
        // Restore directives
        $php = str_replace('___MLVIEW_DIRECTIVE___', '@', $php);

        // Restore other placeholders
        $php = str_replace('___MLVIEW_OPEN_CURLY___', '{{', $php);
        $php = str_replace('___MLVIEW_CLOSE_CURLY___', '}}', $php);
        $php = str_replace('___MLVIEW_OPEN_RAW___', '{!!', $php);
        $php = str_replace('___MLVIEW_CLOSE_RAW___', '!!}', $php);
        $php = str_replace('___MLVIEW_COMMENT_OPEN___', '{{--', $php);
        $php = str_replace('___MLVIEW_COMMENT_CLOSE___', '--}}', $php);
        $php = str_replace('___MLVIEW_AT___', '@', $php);
        $php = str_replace('___MLVIEW_DOUBLE_AT___', '@@', $php);
        $php = str_replace('___MLVIEW_ESCAPED_AT___', '@', $php);

        return $php;
    }
    /**
     * Compile custom directives registered in the Registry
     */
    private function compileCustomDirectives(string $php): string
    {
        foreach ($this->registry->getDirectives() as $name => $handler) {
            /*
             * Match @name(...) with recursive parenthesis support.
             */
            $pattern = "/\@{$name}\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/sx";

            $php = (string)preg_replace_callback($pattern, function($m) use ($handler) {
                return call_user_func($handler, $m[1]);
            }, $php);
        }
        return $php;
    }

    /**
     * Compile @escape('type', val) directive
     */
    private function compileEscapeDirective(string $php): string
    {
        return (string)preg_replace_callback(
            '/@escape\(\s*[\'"](.+?)[\'"]\s*,\s*(.+?)\s*\)/s',
            fn(array $m) => "<?= " . \MonkeysLegion\Template\Support\Escaper::class . "::escape('" . $m[1] . "', " . $m[2] . ") ?>",
            $php
        );
    }

    /**
     * Verify that control structure directives are properly balanced.
     * @throws ParseException if an unclosed directive is detected
     */
    private function validateDirectiveBalance(string $source, string $path): void
    {
        $pairs = [
            'if' => 'endif', 'unless' => 'endunless', 'foreach' => 'endforeach',
            'for' => 'endfor', 'while' => 'endwhile', 'isset' => 'endisset',
            'empty' => 'endempty', 'switch' => 'endswitch', 'verbatim' => 'endverbatim',
            'once' => 'endonce', 'push' => 'endpush', 'prepend' => 'endprepend',
            'section' => 'endsection', 'error' => 'enderror',
        ];

        foreach ($pairs as $open => $close) {
            // Updated regexes to ensure we only count "well-formed" directives with parentheses where required
            if ($open === 'section') {
                $openRegex = '/(?<!@)@section\b(?!\s*\(.*?,)/';
            } elseif (in_array($open, ['verbatim', 'once'])) {
                 // Directives NOT requiring parentheses
                 $openRegex = '/(?<!@)@' . $open . '\b/';
            } else {
                 // Directives requiring parentheses: if, unless, foreach, for, while, isset, empty, switch, push, prepend, error
                 $openRegex = '/(?<!@)@' . $open . '\b\s*\(/';
            }
            
            $closeRegex = '/(?<!@)@' . $close . '\b/';
            
            preg_match_all($openRegex, $source, $openMatches, PREG_OFFSET_CAPTURE);
            preg_match_all($closeRegex, $source, $closeMatches, PREG_OFFSET_CAPTURE);
            
            $openCount = count($openMatches[0]);
            $closeCount = count($closeMatches[0]);
            
            if ($openCount !== $closeCount) {
                $dir = ($openCount > $closeCount) ? $open : $close;
                $match = ($openCount > $closeCount) ? $close : $open;
                $offset = ($openCount > $closeCount) ? (empty($openMatches[0]) ? 0 : end($openMatches[0])[1]) : (empty($closeMatches[0]) ? 0 : end($closeMatches[0])[1]);
                $line = count(explode("\n", substr($source, 0, $offset)));
                
                throw new ParseException(
                    "Unexpected @{$dir} directive without matching @{$match} in " . basename($path),
                    $path,
                    $line
                );
            }
        }
    }
}
