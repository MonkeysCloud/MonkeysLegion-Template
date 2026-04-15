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
    private bool $enableLinting = true;
    private \MonkeysLegion\Template\Support\DirectiveRegistry $registry;

    /** Cached filter registry (avoid re-instantiation per echo expression) */
    private ?\MonkeysLegion\Template\Support\FilterRegistry $filterRegistry = null;

    /**
     * Precompiled simple directive → PHP replacement map.
     * These are directives that need no argument parsing — just literal substitution.
     * Compiled into a single regex in compileSimpleDirectives() for single-pass efficiency.
     *
     * @var array<string, string>
     */
    private const SIMPLE_DIRECTIVES = [
        '@else'        => '<?php else: ?>',
        '@endif'       => '<?php endif; ?>',
        '@endunless'   => '<?php endif; ?>',
        '@endisset'    => '<?php endif; ?>',
        '@endempty'    => '<?php endif; ?>',
        '@endswitch'   => '<?php endswitch; ?>',
        '@endfor'      => '<?php endfor; ?>',
        '@endwhile'    => '<?php endwhile; ?>',
        '@default'     => '<?php default: ?>',
    ];

    public function __construct(
        private \MonkeysLegion\Template\Contracts\ParserInterface $parser,
        ?\MonkeysLegion\Template\Support\DirectiveRegistry $registry = null
    ) {
        $this->registry = $registry ?? new \MonkeysLegion\Template\Support\DirectiveRegistry();
    }

    /**
     * Get or create the cached FilterRegistry.
     */
    private function getFilterRegistry(): \MonkeysLegion\Template\Support\FilterRegistry
    {
        return $this->filterRegistry ??= new \MonkeysLegion\Template\Support\FilterRegistry();
    }

    public function getRegistry(): \MonkeysLegion\Template\Support\DirectiveRegistry
    {
        return $this->registry;
    }

    public function getParser(): \MonkeysLegion\Template\Contracts\ParserInterface
    {
        return $this->parser;
    }

    public function setStrictMode(bool $enable): void
    {
        $this->strictMode = $enable;
    }

    public function setEnableLinting(bool $enable): void
    {
        $this->enableLinting = $enable;
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

        // 3) New directives - JSON and JavaScript helpers
        if (str_contains($php, '@json')) {
            $php = $this->compileJson($php);
        }
        if (str_contains($php, '@js')) {
            $php = $this->compileJs($php);
        }

        // 3.5) Custom Directives
        $php = $this->compileCustomDirectives($php);

        // -- Competitive Features --
        if (str_contains($php, '@verbatim')) {
            $php = $this->compileVerbatim($php);
        }
        if (str_contains($php, '@inject')) {
            $php = $this->compileInject($php);
        }
        if (str_contains($php, '@push') || str_contains($php, '@stack') || str_contains($php, '@prepend')) {
            $php = $this->compileStack($php);
        }
        if (str_contains($php, '@once')) {
            $php = $this->compileOnce($php);
        }
        if (str_contains($php, '@aware')) {
            $php = $this->compileAware($php);
        }
        if (str_contains($php, '@include')) {
            $php = $this->compileIncludes($php);
        }

        // -- Phase 2: Advanced Directives (with early-exit) --
        if (str_contains($php, '@forelse')) {
            $php = $this->compileForelse($php);
        }
        if (str_contains($php, '@fragment')) {
            $php = $this->compileFragment($php);
        }
        if (str_contains($php, '@teleport')) {
            $php = $this->compileTeleport($php);
        }
        if (str_contains($php, '@can')) {
            $php = $this->compileCan($php);
            $php = $this->compileCannot($php);
        }
        if (str_contains($php, '@hasSection')) {
            $php = $this->compileHasSection($php);
        }
        if (str_contains($php, '@sectionMissing')) {
            $php = $this->compileSectionMissing($php);
        }
        if (str_contains($php, '@production')) {
            $php = $this->compileProduction($php);
        }
        if (str_contains($php, '@session')) {
            $php = $this->compileSession($php);
        }
        if (str_contains($php, '@pushOnce')) {
            $php = $this->compilePushOnce($php);
        }
        if (str_contains($php, '@includeIf')) {
            $php = $this->compileIncludeIf($php);
        }
        if (str_contains($php, '@parent')) {
            $php = $this->compileParent($php);
        }
        if (str_contains($php, '@required')) {
            $php = $this->compileRequired($php);
        }
        if (str_contains($php, '@use')) {
            $php = $this->compileUse($php);
        }
        if (str_contains($php, '@persist')) {
            $php = $this->compilePersist($php);
        }
        if (str_contains($php, '@model')) {
            $php = $this->compileModel($php);
        }
        if (str_contains($php, '@autoescape')) {
            $php = $this->compileAutoescape($php);
        }

        // 4) Helper directives - @class() and @style()
        if (str_contains($php, '@class')) {
            $php = $this->compileClassHelper($php);
        }
        if (str_contains($php, '@style')) {
            $php = $this->compileStyleHelper($php);
        }

        // 5) Form helpers and selection helpers
        if (str_contains($php, '@checked')) {
            $php = $this->compileChecked($php);
        }
        if (str_contains($php, '@selected')) {
            $php = $this->compileSelected($php);
        }
        if (str_contains($php, '@disabled')) {
            $php = $this->compileDisabled($php);
        }
        if (str_contains($php, '@readonly')) {
            $php = $this->compileReadonly($php);
        }

        // 6) CSRF + method spoofing
        if (str_contains($php, '@csrf')) {
            $php = $this->compileCsrf($php);
        }
        if (str_contains($php, '@method')) {
            $php = $this->compileMethod($php);
        }

        // 7) Environment / auth directives
        if (str_contains($php, '@env')) {
            $php = $this->compileEnv($php);
        }
        if (str_contains($php, '@auth')) {
            $php = $this->compileAuth($php);
        }
        if (str_contains($php, '@guest')) {
            $php = $this->compileGuest($php);
        }

        // 8) Validation helpers
        if (str_contains($php, '@error')) {
            $php = $this->compileError($php);
            $php = $this->compileEndError($php);
        }
        if (str_contains($php, '@old')) {
            $php = $this->compileOld($php);
        }

        // 9) Existing custom directives (@upper, @lang)
        if (str_contains($php, '@upper')) {
            $php = $this->compileUpper($php);
        }
        if (str_contains($php, '@lang')) {
            $php = $this->compileLang($php);
        }

        // 10) Template macros (@macro/@endmacro/@call)
        if (str_contains($php, '@macro')) {
            $php = $this->compileMacro($php);
            $php = $this->compileEndMacro($php);
        }
        if (str_contains($php, '@call')) {
            $php = $this->compileCall($php);
        }

        // 10.5) Template-level options (@options)
        if (str_contains($php, '@options')) {
            $php = $this->compileOptions($php);
        }

        // 10.6) Fragment caching (@cache/@endcache)
        if (str_contains($php, '@cache')) {
            $php = $this->compileCache($php);
        }

        // 11) Process expressions in HTML attributes
        $php = $this->compileAttributeExpressions($php);

        // 11) Regular escaped and raw echoes
        if (str_contains($php, '{{')) {
            $php = $this->compileEscapedEchoes($php);
        }
        if (str_contains($php, '{!!')) {
            $php = $this->compileRawEchoes($php);
        }

        // 12) Debugging directives
        if (str_contains($php, '@dump')) {
            $php = $this->compileDump($php);
        }
        if (str_contains($php, '@dd')) {
            $php = $this->compileDd($php);
        }

        // 13) Context-aware escape directive
        if (str_contains($php, '@escape')) {
            $php = $this->compileEscapeDirective($php);
        }

        // 14) Element-level HEEx-style directives (:if, :for, :unless on HTML elements)
        $php = $this->compileElementLevelDirectives($php);

        // 15) Control structures — consolidated with single-pass simple directives
        $php = $this->compileConditionals($php);
        $php = $this->compileConditionalsSugar($php);
        $php = $this->compileLoops($php);
        $php = $this->compileSimpleDirectives($php);

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
        if (!$this->enableLinting || !function_exists('exec')) {
            return;
        }
        
        // We use a temporary file to lint the code safely
        $tmpDir = sys_get_temp_dir();
        $tmpFile = tempnam($tmpDir, 'ml_lint_');
        
        if ($tmpFile === false) {
            return; // Fail gracefully if we can't create a temp file
        }

        try {
            if (file_put_contents($tmpFile, $php) === false) {
                return;
            }

            $output = [];
            $returnVar = 0;
            
            // Use PHP_BINARY to ensure we use the same PHP version as the runner
            $phpBinary = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
            
            // Run php -l (lint)
            exec(escapeshellcmd($phpBinary) . " -l " . escapeshellarg($tmpFile) . " 2>&1", $output, $returnVar);

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
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }


    /**
     * Compile @json() directive
     * Outputs JSON-encoded data
     */
    private function compileJson(string $php): string
    {
        return (string)preg_replace_callback(
            '/@json\s*\(((?>(?:[^()]+|\((?:(?:[^()]+|(?1))*)\)))+)\)/s',
            fn(array $m) => "<?= json_encode({$m[1]}, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>",
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
            '/@js\s*\(((?>(?:[^()]+|\((?:(?:[^()]+|(?1))*)\)))+)\)/s',
            fn(array $m) => "<?= json_encode({$m[1]}, JSON_UNESCAPED_UNICODE) ?>",
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
            '/@class\s*\(((?>(?:[^()]+|\((?:(?:[^()]+|(?1))*)\)))+)\)/s',
            fn(array $m) => "<?= \\MonkeysLegion\\Template\\Support\\AttributeBag::conditional({$m[1]}) ?>",
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
            '/@style\s*\(((?>(?:[^()]+|\((?:(?:[^()]+|(?1))*)\)))+)\)/s',
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
            fn(array $m) => "<?= '<input type=\"hidden\" name=\"_method\" value=\"' . htmlspecialchars(" . var_export($m[1], true) . ", ENT_QUOTES, 'UTF-8') . '\">' ?>",
            $php
        );
    }

    /**
     * Compile the env directive.
     * Check environment — supports single string or array of environments.
     *
     * Usage: @​env('local')
     * Usage: @​env(['local', 'staging'])
     */
    private function compileEnv(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@env\s*\(\s*(.+?)\s*\)(?!\s*:)/',
            function (array $m): string {
                $arg = trim($m[1]);
                $envExpr = "(\$_ENV['APP_ENV'] ?? \$_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?? 'production')";

                // Array syntax: @env(['local', 'staging'])
                if (str_starts_with($arg, '[')) {
                    return "<?php if (in_array({$envExpr}, {$arg}, true)): ?>";
                }

                // Single string: @env('local') or @env("local")
                return "<?php if ({$envExpr} === {$arg}): ?>";
            },
            $php,
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
        $registry = $this->getFilterRegistry();

        return (string)preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            static function (array $m) use ($registry): string {
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
                // Check for filters: $var | filter | filter(arg)
                if (str_contains($expr, '|')) {
                    // Balance-aware pipe splitting: don't split on | inside strings/arrays
                    $filters = self::splitFilterPipeline($expr);
                    $baseExpr = trim((string) array_shift($filters));

                    if (!empty($filters)) {
                        $code = $baseExpr;
                        $skipEscape = false;

                        foreach ($filters as $filterStr) {
                            $filterStr = trim($filterStr);
                            if ($filterStr === '') {
                                continue;
                            }
                            // Parse filter name and optional args: upper, truncate(50, '...')
                            if (preg_match('/^(\w+)(?:\((.*)\))?$/s', $filterStr, $fm)) {
                                $name = $fm[1];
                                $args = $fm[2] ?? '';

                                if ($name === 'raw') {
                                    $skipEscape = true;
                                    continue;
                                }

                                // Built-in compilable filter → raw PHP
                                if ($registry->hasFilter($name) || self::isCompilableFilter($name)) {
                                    $code = $registry->compileFilterChain($code, [
                                        ['name' => $name, 'args' => $args],
                                    ]);
                                } else {
                                    // Runtime filter → call through Renderer's DirectiveRegistry
                                    $argsCode = $args !== '' ? ", {$args}" : '';
                                    $code = "(\$this->getRegistry()->hasFilter('{$name}') " .
                                        "? call_user_func(\$this->getRegistry()->getFilters()['{$name}'], {$code}{$argsCode}) " .
                                        ": \\MonkeysLegion\\Template\\Support\\Escaper::checkStrictRaw('Filter {$name} not found and strict mode enabled'))";
                                }
                            }
                        }

                        if ($skipEscape) {
                            return "<?= {$code} ?>";
                        }
                        return "<?= \\MonkeysLegion\\Template\\Support\\Escaper::html({$code}) ?>";
                    }
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
     * Supports both standalone and inline usage:
     *   `@if(...)`
     *   `@elseif(...)`
     *   `@else`
     *   `@endif`
     */
    private function compileConditionals(string $php): string
    {
        // Recursive pattern for balanced parentheses
        $expr = '\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)';

        // @if (condition)
        $php = (string)preg_replace_callback(
            "/(?<!@)@if\b{$expr}/sx",
            fn($m) => "<?php if ({$m[1]}): ?>",
            $php
        );

        // @elseif (condition)
        $php = (string)preg_replace_callback(
            "/(?<!@)@elseif\b{$expr}/sx",
            fn($m) => "<?php elseif ({$m[1]}): ?>",
            $php
        );

        // @else and @endif handled by compileSimpleDirectives()

        return $php;
    }

    /**
     * Compile conditional sugars: @unless, @isset, @empty, @switch, @case
     * Simple closing directives (@endunless, @endisset, etc.) handled by compileSimpleDirectives()
     */
    private function compileConditionalsSugar(string $php): string
    {
        // Recursive pattern for balanced parentheses
        $expr = '\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)';

        // @unless(cond) -> if (! (cond))
        $php = (string)preg_replace('/^[ \t]*@unless\s*\((.*)\)\s*$/m', '<?php if (! ($1)): ?>', $php);

        // @isset(var) -> if (isset(var))
        $php = (string)preg_replace('/^[ \t]*@isset\s*\((.*)\)\s*$/m', '<?php if (isset($1)): ?>', $php);

        // @empty(var) -> if (empty(var))
        $php = (string)preg_replace('/^[ \t]*@empty\s*\((.*)\)\s*$/m', '<?php if (empty($1)): ?>', $php);

        // @switch($var)
        $php = (string)preg_replace('/^[ \t]*@switch\s*\((.*)\)\s*$/m', '<?php switch($1): ?>', $php);

        // @case('val') - must close PHP tag so HTML content can be rendered
        $php = (string)preg_replace('/^[ \t]*@case\s*\((.*)\)\s*$/m', '<?php case $1: ?>', $php);

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
        // We need to pop loop and tick before ending? No, we tick at end of loop body for next iteration?
        // Actually, if we tick at end, the loop check happens, then loop starts.
        // Wait, foreach structure: foreach(...) { BODY }
        // We want $loop->iteration to increment.
        // If we put tick() at end of BODY:
        // Iter 1: index 0. End of body: index becomes 1.
        // Iter 2 starts: index is 1. Correct.
        $php = (string)preg_replace('/@endforeach\b/', "<?php \$this->getLastLoop()?->tick(); endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); ?>", $php);

        // @for
        $php = (string)preg_replace($forPattern, "<?php for ($1): ?>", $php);

        // @while
        $php = (string)preg_replace($whilePattern, "<?php while ($1): ?>", $php);

        // @breakIf($cond) / @continueIf($cond)
        $php = (string)preg_replace_callback(
            '/\@breakIf\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/x',
            fn(array $m) => "<?php if ({$m[1]}) break; ?>",
            $php,
        );
        $php = (string)preg_replace_callback(
            '/\@continueIf\s*\(\s*( (?: [^()]+ | (\( (?: [^()]+ | (?2) )* \)) )* )\s*\)/x',
            fn(array $m) => "<?php if ({$m[1]}) continue; ?>",
            $php,
        );

        // @endfor, @endwhile, @break, @continue handled by compileSimpleDirectives()

        return $php;
    }

    /**
     * Single-pass replacement for all no-argument directives.
     *
     * Consolidates 11+ individual preg_replace calls into one regex with an
     * alternation group. Each directive maps to a constant PHP output string
     * via the SIMPLE_DIRECTIVES constant.
     */
    private function compileSimpleDirectives(string $php): string
    {
        // Build alternation: @else|@endif|@endunless|...
        $directives = self::SIMPLE_DIRECTIVES;

        // Add directives not in the constant (loop-related, handled here for perf)
        $directives['@break']    = '<?php break; ?>';
        $directives['@continue'] = '<?php continue; ?>';

        $escapedKeys = array_map(
            static fn(string $d): string => preg_quote(ltrim($d, '@'), '/'),
            array_keys($directives),
        );

        // Pattern: match @directive at line boundaries with optional whitespace
        $pattern = '/^[ \t]*@(' . implode('|', $escapedKeys) . ')\s*$/m';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($directives): string {
                return $directives['@' . $m[1]] ?? $m[0];
            },
            $php,
        );
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
            '/(?<!@)@stack\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?= \$this->yieldPush('{$m[1]}') ?>",
            $php
        );

        // @push('name')
        $php = (string)preg_replace_callback(
            '/(?<!@)@push\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?php \$this->startPush('{$m[1]}'); ?>",
            $php
        );
        $php = (string)preg_replace('/(?<!@)@endpush\b/', "<?php \$this->stopPush(); ?>", $php);

        // @prepend('name')
        $php = (string)preg_replace_callback(
            '/(?<!@)@prepend\([\'"](.+?)[\'"]\)/',
            fn($m) => "<?php \$this->startPrepend('{$m[1]}'); ?>",
            $php
        );
        $php = (string)preg_replace('/(?<!@)@endprepend\b/', "<?php \$this->stopPrepend(); ?>", $php);

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
            '/@aware\s*\(((?>(?:[^()]+|\((?:(?:[^()]+|(?1))*)\)))+)\)/s',
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

        // @includeFirst(['view1', 'view2'], ['data']) — try views in order, render first that exists
        $php = (string)preg_replace_callback(
            '/@includeFirst\s*\(\s*(\[.+?\])\s*(?:,\s*(.+?))?\s*\)/',
            function (array $m): string {
                $views = $m[1];
                $data = isset($m[2]) && trim($m[2]) !== '' ? $m[2] : '[]';
                return "<?php foreach ({$views} as \$__firstView) { "
                    . "try { echo \$this->render(\$__firstView, {$data}); break; } "
                    . "catch (\\RuntimeException) {} } "
                    . "unset(\$__firstView); ?>";
            },
            $php,
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
     * Split expression on pipe `|` characters, respecting string and bracket nesting.
     *
     * Ensures pipes inside strings, arrays, or parentheses are not split.
     *
     * @return list<string>
     */
    private static function splitFilterPipeline(string $expr): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($expr);

        for ($i = 0; $i < $len; $i++) {
            $char = $expr[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $current .= $char . $expr[++$i];
                continue;
            }

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            }

            if (!$inSingle && !$inDouble) {
                if ($char === '(' || $char === '[') {
                    $depth++;
                } elseif ($char === ')' || $char === ']') {
                    $depth--;
                }

                if ($char === '|' && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Check if a filter name is a built-in compilable filter.
     */
    private static function isCompilableFilter(string $name): bool
    {
        static $registry = null;
        $registry ??= new \MonkeysLegion\Template\Support\FilterRegistry();
        return $registry->hasFilter($name);
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

    // =========================================================================
    // Phase 2: Advanced Directives
    // =========================================================================

    /**
     * Compile `@forelse`($items as $item) ... @empty ... @endforelse
     */
    private function compileForelse(string $php): string
    {
        $pattern = '/@forelse\s*\(((?>[^()]+|(?R))*)\)/';

        $php = (string)preg_replace_callback($pattern, function (array $m): string {
            $expression = $m[1];
            if (preg_match('/^(.*)\s+as\s+(.*)$/i', $expression, $parts)) {
                $iterable = $parts[1];
                return "<?php \$__forelseData = {$iterable}; \$__forelseEmpty = true; " .
                    "\$this->addLoop(\$__forelseData); " .
                    "foreach(\$__forelseData as {$parts[2]}): " .
                    "\$__forelseEmpty = false; \$loop = \$this->getLastLoop(); ?>";
            }
            return "<?php foreach({$expression}): ?>";
        }, $php);

        // Only match bare @empty (no args) — the forelse "else" clause.
        // @empty($var) is a standalone conditional handled by compileConditionalsSugar.
        $php = (string)preg_replace(
            '/@empty(?!\s*\()/',
            '<?php $this->getLastLoop()?->tick(); endforeach; $this->popLoop(); $loop = $this->getLastLoop(); if ($__forelseEmpty): ?>',
            $php,
        );

        $php = (string)preg_replace('/@endforelse\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile `@fragment`('name') ... @endfragment — HTMX partial rendering
     */
    private function compileFragment(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@fragment\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php \$__fragmentName = '{$m[1]}'; ob_start(); ?>",
            $php,
        );

        $php = (string)preg_replace(
            '/@endfragment\b/',
            '<?php $__fragmentContent = ob_get_clean(); ' .
            'if ($this->isHtmxRequest() && ($this->getRequest()?->getHeaderLine(\'HX-Target\') === $__fragmentName ' .
            '|| $this->getRequest()?->getHeaderLine(\'HX-Fragment\') === $__fragmentName)) { ' .
            'echo $__fragmentContent; return; } echo $__fragmentContent; ?>',
            $php,
        );

        return $php;
    }

    /**
     * Compile `@teleport`('selector') ... @endteleport
     */
    private function compileTeleport(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@teleport\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php ob_start(); /* teleport:{$m[1]} */ ?>",
            $php,
        );

        $php = (string)preg_replace(
            '/@endteleport\b/',
            '<?php $__teleportContent = ob_get_clean(); echo "<!-- teleport -->" . $__teleportContent . "<!-- /teleport -->"; ?>',
            $php,
        );

        return $php;
    }

    /**
     * Compile `@can`('ability', $model) ... @endcan
     */
    private function compileCan(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@can\s*\(\s*[\'"]([^"\']+)[\'"]\s*(?:,\s*(.+?))?\s*\)/',
            function (array $m): string {
                $ability = $m[1];
                $model = isset($m[2]) ? ', ' . $m[2] : '';
                return "<?php if (function_exists('auth') && auth()->can('{$ability}'{$model})): ?>";
            },
            $php,
        );

        $php = (string)preg_replace('/@endcan\b/', '<?php endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@cannot`('ability', $model) ... @endcannot
     */
    private function compileCannot(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@cannot\s*\(\s*[\'"]([^"\']+)[\'"]\s*(?:,\s*(.+?))?\s*\)/',
            function (array $m): string {
                $ability = $m[1];
                $model = isset($m[2]) ? ', ' . $m[2] : '';
                return "<?php if (function_exists('auth') && auth()->cannot('{$ability}'{$model})): ?>";
            },
            $php,
        );

        $php = (string)preg_replace('/@endcannot\b/', '<?php endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@hasSection`('name') ... @endhasSection
     */
    private function compileHasSection(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@hasSection\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php if (isset(\$__sections['{$m[1]}'])): ?>",
            $php,
        );

        $php = (string)preg_replace('/@endhasSection\b/', '<?php endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@sectionMissing`('name') ... @endsectionMissing
     */
    private function compileSectionMissing(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@sectionMissing\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php if (!isset(\$__sections['{$m[1]}'])): ?>",
            $php,
        );

        $php = (string)preg_replace('/@endsectionMissing\b/', '<?php endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@production` ... @endproduction
     */
    private function compileProduction(string $php): string
    {
        $php = (string)preg_replace(
            '/@production\b/',
            "<?php if (function_exists('app_env') && app_env() === 'production'): ?>",
            $php,
        );

        $php = (string)preg_replace('/@endproduction\b/', '<?php endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@session`('key') ... @endsession — check session and inject $value
     */
    private function compileSession(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@session\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php if (function_exists('session') && session()->has('{$m[1]}')): " .
                "\$value = session()->get('{$m[1]}'); ?>",
            $php,
        );

        $php = (string)preg_replace('/@endsession\b/', '<?php endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@pushOnce`('stack') ... @endPushOnce
     */
    private function compilePushOnce(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@pushOnce\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php if (\$this->addOnceHash('push_once_{$m[1]}_' . md5(__FILE__ . __LINE__))): \$this->startPush('{$m[1]}'); ?>",
            $php,
        );

        $php = (string)preg_replace('/@endPushOnce\b/', '<?php $this->stopPush(); endif; ?>', $php);
        return $php;
    }

    /**
     * Compile `@includeIf`('view', [...]) — include only if view exists
     */
    private function compileIncludeIf(string $php): string
    {
        return (string)preg_replace_callback(
            '/@includeIf\s*\(\s*[\'"]([^"\']+)[\'"]\s*(?:,\s*((?>[^()]+|\((?:(?:[^()]+|(?1))*)\))+))?\s*\)/',
            function (array $m): string {
                $view = $m[1];
                $data = isset($m[2]) ? ', ' . $m[2] : '';
                return "<?php if (\$this->viewExists('{$view}')) { echo \$this->render('{$view}'{$data}); } ?>";
            },
            $php,
        );
    }

    /**
     * Compile `@parent` — render parent section content
     */
    private function compileParent(string $php): string
    {
        return (string)preg_replace('/@parent\b/', '@@parent_placeholder@@', $php);
    }

    /**
     * Compile `@required`($condition) — conditional HTML required attribute
     */
    private function compileRequired(string $php): string
    {
        return (string)preg_replace(
            '/@required\s*\(\s*(.+?)\s*\)/',
            '<?php if ($1): ?> required<?php endif; ?>',
            $php,
        );
    }

    /**
     * Compile `@use`(ClassName, 'Alias') — import PHP class in template
     */
    private function compileUse(string $php): string
    {
        return (string)preg_replace_callback(
            '/@use\s*\(\s*[\'"]([^"\']+)[\'"]\s*(?:,\s*[\'"]([^"\']+)[\'"]\s*)?\)/',
            function (array $m): string {
                $class = $m[1];
                $alias = isset($m[2]) ? $m[2] : basename(str_replace('\\', '/', $class));
                return "<?php use {$class} as {$alias}; ?>";
            },
            $php,
        );
    }

    /**
     * Compile `@persist`('id') ... @endpersist — HTMX morph-merge container
     */
    private function compilePersist(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@persist\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<div id=\"persist-{$m[1]}\" data-persist>",
            $php,
        );

        $php = (string)preg_replace('/@endpersist\b/', '</div>', $php);
        return $php;
    }

    /**
     * Compile `@model`(App\Entity\User) — PHPDoc type hint for IDE autocompletion
     */
    private function compileModel(string $php): string
    {
        return (string)preg_replace_callback(
            '/@model\s*\(\s*([A-Za-z0-9_\\\\]+)\s*\)/',
            fn(array $m) => "<?php /** @var \\{$m[1]} \$model */ ?>",
            $php,
        );
    }

    /**
     * Compile `@autoescape`('context') ... @endautoescape — context-specific escaping
     */
    private function compileAutoescape(string $php): string
    {
        $php = (string)preg_replace_callback(
            '/@autoescape\s*\(\s*[\'"]([^"\']+)[\'"]\s*\)/',
            fn(array $m) => "<?php \$__previousEscapeContext = \$__escapeContext ?? 'html'; \$__escapeContext = '{$m[1]}'; ?>",
            $php,
        );

        $php = (string)preg_replace(
            '/@endautoescape\b/',
            '<?php $__escapeContext = $__previousEscapeContext ?? \'html\'; ?>',
            $php,
        );

        return $php;
    }

    // =========================================================================
    // Phase 2: Element-Level HEEx Directives
    // =========================================================================

    /**
     * Compile @macro('name', $param1, $param2) → define a reusable template snippet.
     */
    private function compileMacro(string $php): string
    {
        return (string) preg_replace(
            "/@macro\s*\(\s*'([^']+)'\s*(?:,\s*(.*?))?\)/s",
            '<?php $__macros[\'$1\'] = function($2) { ob_start(); ?>',
            $php,
        );
    }

    /**
     * Compile @endmacro → end macro definition.
     */
    private function compileEndMacro(string $php): string
    {
        return str_replace('@endmacro', '<?php return ob_get_clean(); }; ?>', $php);
    }

    /**
     * Compile @call('name', $arg1, $arg2) → invoke a defined macro.
     */
    private function compileCall(string $php): string
    {
        return (string) preg_replace(
            "/@call\s*\(\s*'([^']+)'\s*(?:,\s*(.*?))?\)/s",
            '<?= isset($__macros[\'$1\']) ? $__macros[\'$1\']($2) : \'\' ?>',
            $php,
        );
    }

    /**
     * Compile @options([...]) → template-level configuration.
     *
     * Extracts options and stores them as template metadata.
     * Currently supports: strict, autoescape, layout.
     */
    private function compileOptions(string $php): string
    {
        return (string) preg_replace_callback(
            "/@options\s*\(\s*(\[.*?\])\s*\)/s",
            function (array $m): string {
                return "<?php \$__templateOptions = {$m[1]}; ?>";
            },
            $php,
        );
    }

    /**
     * Compile cache/endcache directives.
     *
     * When $__ml_cache (PSR-16) is available, the block output is cached.
     * When no cache is set, the block renders normally with no overhead.
     *
     * Syntax examples:
     *   cache('sidebar-' . $userId, 300)       - key + TTL in seconds
     *   cache('static-nav')                      - key only (no TTL = null)
     *   endcache
     */
    private function compileCache(string $php): string
    {
        // @cache('key') or @cache('key', ttl)
        $php = (string) preg_replace_callback(
            '/@cache\s*\(\s*((?>[^()]+|\((?:(?:[^()]+|(?1)))*\))+)\s*\)/s',
            function (array $m): string {
                $args = trim($m[1]);
                // Split on first comma not inside parens
                $parts = preg_split('/,(?![^(]*\))/', $args, 2);
                if ($parts === false) {
                    $parts = [$args];
                }
                $key = trim($parts[0]);
                $ttl = isset($parts[1]) ? trim($parts[1]) : 'null';

                return "<?php \$__cacheTtl = {$ttl}; \$__cacheKey = 'ml_frag:' . ({$key});\n"
                    . "\$__cacheHit = isset(\$__ml_cache) ? \$__ml_cache->get(\$__cacheKey) : null;\n"
                    . "if (\$__cacheHit !== null): echo \$__cacheHit; else: ob_start(); ?>";
            },
            $php,
        );

        // @endcache — closes the if/else block
        return (string) preg_replace(
            '/@endcache\b/',
            "<?php \$__cacheContent = ob_get_clean();\n"
            . "if (isset(\$__ml_cache) && \$__cacheContent !== false) { \$__ml_cache->set(\$__cacheKey, \$__cacheContent, \$__cacheTtl ?? null); }\n"
            . "if (\$__cacheContent !== false) { echo \$__cacheContent; } endif; ?>",
            $php,
        );
    }

    /**
     * Compile element-level `:if`, `:for`, `:unless` directives.
     *
     * Transforms:
     *   `<div :if="$show">content</div>` → `<?php if ($show): ?><div>content</div><?php endif; ?>`
     *   `<li :for="$items as $item">{{ $item }}</li>` → foreach wrapping
     *   `<div :unless="$hidden">content</div>` → `<?php if (!($hidden)): ?><div>...</div><?php endif; ?>`
     */
    private function compileElementLevelDirectives(string $php): string
    {
        // Strategy: match the full tag with :directive attribute anywhere,
        // capture the parts before and after the directive attribute separately.
        // The directive attribute can appear first, last, or in the middle.

        // Helper to build the replacement callback
        $makeIfCallback = fn(string $prefix) => function (array $m) use ($prefix): string {
            $tag = $m[1];
            $allAttrs = $m[2];
            $condition = $m[3];
            $content = $m[4];
            // Remove the :if/unless attr from the attributes string
            $cleanAttrs = (string) preg_replace('/\s+:(?:if|unless)="[^"]*"/', '', $allAttrs);
            return "<?php if ({$prefix}{$condition}): ?><{$tag}{$cleanAttrs}>{$content}</{$tag}><?php endif; ?>";
        };

        $makeSelfClosingIfCallback = fn(string $prefix) => function (array $m) use ($prefix): string {
            $tag = $m[1];
            $allAttrs = $m[2];
            $condition = $m[3];
            $cleanAttrs = (string) preg_replace('/\s+:(?:if|unless)="[^"]*"/', '', $allAttrs);
            return "<?php if ({$prefix}{$condition}): ?><{$tag}{$cleanAttrs} /><?php endif; ?>";
        };

        // :if — opening + closing tag pairs
        $php = (string) preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*?)?)\s+:if="([^"]+)"((?:\s+[^>]*?)?)>(.*?)<\/\1>/s',
            function (array $m): string {
                $tag = $m[1];
                $before = $m[2];
                $cond = $m[3];
                $after = $m[4];
                return "<?php if ({$cond}): ?><{$tag}{$before}{$after}>{$m[5]}</{$tag}><?php endif; ?>";
            },
            $php,
        );

        // :if — self-closing tags
        $php = (string) preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*?)?)\s+:if="([^"]+)"((?:\s+[^>]*?)?)\s*\/>/s',
            function (array $m): string {
                $tag = $m[1];
                $before = $m[2];
                $cond = $m[3];
                $after = $m[4];
                return "<?php if ({$cond}): ?><{$tag}{$before}{$after} /><?php endif; ?>";
            },
            $php,
        );

        // :unless — opening + closing tag pairs
        $php = (string) preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*?)?)\s+:unless="([^"]+)"((?:\s+[^>]*?)?)>(.*?)<\/\1>/s',
            function (array $m): string {
                $tag = $m[1];
                $before = $m[2];
                $cond = $m[3];
                $after = $m[4];
                return "<?php if (!({$cond})): ?><{$tag}{$before}{$after}>{$m[5]}</{$tag}><?php endif; ?>";
            },
            $php,
        );

        // :unless — self-closing tags
        $php = (string) preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*?)?)\s+:unless="([^"]+)"((?:\s+[^>]*?)?)\s*\/>/s',
            function (array $m): string {
                $tag = $m[1];
                $before = $m[2];
                $cond = $m[3];
                $after = $m[4];
                return "<?php if (!({$cond})): ?><{$tag}{$before}{$after} /><?php endif; ?>";
            },
            $php,
        );

        // :for — opening + closing tag pairs
        $php = (string) preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*?)?)\s+:for="([^"]+)"((?:\s+[^>]*?)?)>(.*?)<\/\1>/s',
            function (array $m): string {
                $expr = $m[3];
                if (preg_match('/^(.*)\s+as\s+(.*)$/i', $expr, $parts)) {
                    return "<?php \$__currentLoopData = {$parts[1]}; \$this->addLoop(\$__currentLoopData); " .
                        "foreach(\$__currentLoopData as {$parts[2]}): \$loop = \$this->getLastLoop(); ?>" .
                        "<{$m[1]}{$m[2]}{$m[4]}>{$m[5]}</{$m[1]}>" .
                        "<?php \$this->getLastLoop()->tick(); endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); ?>";
                }
                return $m[0];
            },
            $php,
        );

        // :for — self-closing tags
        $php = (string) preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*?)?)\s+:for="([^"]+)"((?:\s+[^>]*?)?)\s*\/>/s',
            function (array $m): string {
                $expr = $m[3];
                if (preg_match('/^(.*)\s+as\s+(.*)$/i', $expr, $parts)) {
                    return "<?php \$__currentLoopData = {$parts[1]}; \$this->addLoop(\$__currentLoopData); " .
                        "foreach(\$__currentLoopData as {$parts[2]}): \$loop = \$this->getLastLoop(); ?>" .
                        "<{$m[1]}{$m[2]}{$m[4]} />" .
                        "<?php \$this->getLastLoop()->tick(); endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); ?>";
                }
                return $m[0];
            },
            $php,
        );

        return $php;
    }
}
