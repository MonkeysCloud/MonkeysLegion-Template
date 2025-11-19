<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

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
class Compiler
{
    public function __construct(private Parser $parser) {}

    /**
     * @param string $source Raw template input
     * @param string $path   Absolute path of original file (for debug)
     * @return string        PHP source ready to be cached & included
     */
    public function compile(string $source, string $path): string
    {
        // 0) Pre-process code examples to protect template syntax
        $source = $this->preProcessCodeExamples($source);

        // 1) Run parser (handles <x-*>, @slot, @props, layouts, etc.)
        $php = $this->parser->parse($source);

        // 2) REMOVE Blade-style comments BEFORE we touch {{ }} or {!! !!}
        //    This prevents {{-- ... --}} from being treated as an echo
        //    and generating invalid PHP like "-- Meta Tags --".
        $php = $this->compileComments($php);
        $php = $this->compilePhpBlocks($php);

        // 3) New directives - JSON and JavaScript helpers
        $php = $this->compileJson($php);
        $php = $this->compileJs($php);

        // 4) Helper directives - @class() and @style()
        $php = $this->compileClassHelper($php);
        $php = $this->compileStyleHelper($php);

        // 5) Form helpers and selection helpers
        $php = $this->compileChecked($php);
        $php = $this->compileSelected($php);
        $php = $this->compileDisabled($php);
        $php = $this->compileReadonly($php);

        // 6) CSRF + method spoofing
        $php = $this->compileCsrf($php);
        $php = $this->compileMethod($php);

        // 7) Environment / auth directives
        $php = $this->compileEnv($php);
        $php = $this->compileAuth($php);
        $php = $this->compileGuest($php);

        // 8) Validation helpers
        $php = $this->compileError($php);
        $php = $this->compileEndError($php);
        $php = $this->compileOld($php);

        // 9) Existing custom directives (@upper, @lang)
        $php = $this->compileUpper($php);
        $php = $this->compileLang($php);

        // 10) Process expressions in HTML attributes
        $php = $this->compileAttributeExpressions($php);

        // 11) Regular escaped and raw echoes
        $php = $this->compileEscapedEchoes($php);
        $php = $this->compileRawEchoes($php);

        // 12) Debugging directives
        $php = $this->compileDump($php);
        $php = $this->compileDd($php);

        // 13) Control structures
        $php = $this->compileConditionals($php);
        $php = $this->compileLoops($php);

        // 14) Clean up excessive newlines
        $php = preg_replace('/\n{3,}/', "\n\n", $php);
        $php = trim($php);

        // 15) Post-process to restore protected code examples
        $php = $this->postProcessCodeExamples($php);

        $useHeader = "use MonkeysLegion\\Template\\Support\\AttributeBag;\n";

        // If the compiled output starts with a PHP open tag,
        // insert the `use` statement right after it.
        if (str_starts_with($php, '<?php')) {
            $php = preg_replace(
                '/^<\?php(\s*)/i',
                "<?php$1{$useHeader}",
                $php,
                1
            );
        } else {
            // Otherwise, prepend a PHP block with the use statement
            $php = "<?php\n{$useHeader}?>\n" . $php;
        }

        return $php;
    }


    /**
     * Compile @json() directive
     * Outputs JSON-encoded data
     */
    private function compileJson(string $php): string
    {
        return preg_replace_callback(
            '/@json\((.+?)\)/',
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
        return preg_replace_callback(
            '/@js\((.+?)\)/',
            fn(array $m) => "<?= json_encode({$m[1]}, JSON_UNESCAPED_UNICODE) ?>",
            $php
        );
    }

    /**
     * Compile @class() helper
     * Alternative syntax for conditional classes
     * @class(['btn', 'active' => $isActive])
     */
    private function compileClassHelper(string $php): string
    {
        return preg_replace_callback(
            '/@class\((.+?)\)/',
            fn(array $m) => "<?= \\MonkeysLegion\\Template\\Support\\AttributeBag::conditional({$m[1]}) ?>",
            $php
        );
    }

    /**
     * Compile @style() helper
     * Conditional inline styles
     * @style(['display: block', 'color: red' => $hasError])
     */
    private function compileStyleHelper(string $php): string
    {
        return preg_replace_callback(
            '/@style\((.+?)\)/',
            function(array $m) {
                return "<?php \$__styles = []; " .
                    "foreach ({$m[1]} as \$__k => \$__v) { " .
                    "if (is_int(\$__k) && \$__v) \$__styles[] = \$__v; " .
                    "elseif (\$__v) \$__styles[] = \$__k; " .
                    "} echo implode('; ', \$__styles); ?>";
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
        return preg_replace_callback(
            '/@checked\((.+?)\)/',
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
        return preg_replace_callback(
            '/@selected\((.+?)\)/',
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
        return preg_replace_callback(
            '/@disabled\((.+?)\)/',
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
        return preg_replace_callback(
            '/@readonly\((.+?)\)/',
            fn(array $m) => "<?= ({$m[1]}) ? 'readonly' : '' ?>",
            $php
        );
    }

    /**
     * Compile @csrf directive
     * Outputs CSRF token field (customize for your framework)
     */
    private function compileCsrf(string $php): string
    {
        return preg_replace(
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
        return preg_replace_callback(
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
        // @env('production')
        $php = preg_replace_callback(
            '/@env\(["\'](.+?)["\']\)/',
            fn(array $m) => "<?php if ((getenv('APP_ENV') ?? 'production') === '{$m[1]}'): ?>",
            $php
        );

        // @endenv
        $php = preg_replace('/@endenv\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile @auth directive
     * Check if user is authenticated (customize for your framework)
     */
    private function compileAuth(string $php): string
    {
        $php = preg_replace(
            '/@auth\b/',
            "<?php if (function_exists('auth') && auth()->check()): ?>",
            $php
        );

        $php = preg_replace('/@endauth\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile @guest directive
     * Check if user is not authenticated
     */
    private function compileGuest(string $php): string
    {
        $php = preg_replace(
            '/@guest\b/',
            "<?php if (!function_exists('auth') || !auth()->check()): ?>",
            $php
        );

        $php = preg_replace('/@endguest\b/', '<?php endif; ?>', $php);

        return $php;
    }

    /**
     * Compile @error() directive
     * Display validation error for a field
     */
    private function compileError(string $php): string
    {
        return preg_replace_callback(
            '/@error\(["\'](.+?)["\']\)/',
            function(array $m) {
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
        return preg_replace('/@enderror\b/', '<?php endif; ?>', $php);
    }

    /**
     * Compile @old() directive
     * Get old input value
     */
    private function compileOld(string $php): string
    {
        return preg_replace_callback(
            '/@old\(["\'](.+?)["\'](?:\s*,\s*(.+?))?\)/',
            function(array $m) {
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
        return preg_replace_callback(
            '/@upper\(([^)]+)\)/',
            fn(array $m) => "\n<?= htmlspecialchars(strtoupper((string)({$m[1]} ?? '')), ENT_QUOTES, 'UTF-8') ?>\n",
            $php
        );
    }

    /**
     * Compile @lang() directive
     */
    private function compileLang(string $php): string
    {
        return preg_replace_callback(
            "/@lang\((?:'|\")(.+?)(?:'|\")(?:\s*,\s*(\[[^\]]*\]))?\)/",
            function (array $m) {
                $key = $m[1];
                $replace = $m[2] ?? '[]';
                return "\n<?= trans('{$key}', {$replace}) ?>\n";
            },
            $php
        );
    }

    /**
     * Process expressions in HTML attributes
     */
    private function compileAttributeExpressions(string $php): string
    {
        return preg_replace_callback(
            '/<([^>]+?)(\s+[a-zA-Z0-9_:-]+\s*=\s*["\'])([^"\']*?)(\{\{|\{!!)(.*?)(\}\}|!!\})([^"\']*?)(["\'])/s',
            function (array $m) {
                $tagStart = $m[1];
                $attrStart = $m[2];
                $beforeExpr = $m[3];
                $exprOpen = $m[4];
                $exprContent = $m[5];
                $exprClose = $m[6];
                $afterExpr = $m[7];
                $attrClose = $m[8];

                $isEscaped = $exprOpen === '{{';

                $phpOutput = $isEscaped
                    ? "<?= htmlspecialchars((string)({$exprContent} ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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
        return preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
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
                return "<?= htmlspecialchars((string)({$expr} ?? ''), ENT_QUOTES, 'UTF-8') ?>";
            },
            $php
        );
    }

    /**
     * Compile raw echoes {!! !!}
     */
    private function compileRawEchoes(string $php): string
    {
        return preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            static function (array $m): string {
                return "<?= {$m[1]} ?? '' ?>";
            },
            $php
        );
    }

    /**
     * Enhanced @dump() directive with better formatting
     */
    private function compileDump(string $php): string
    {
        return preg_replace_callback(
            '/@dump\(\s*((?>[^()]+|(?R))*)\s*\)/s',
            function(array $m) {
                return "\n<?php " .
                    "echo '<div style=\"background:#f5f5f5;border:1px solid #ddd;padding:1rem;margin:1rem 0;border-radius:4px;\">';" .
                    "echo '<pre style=\"margin:0;font-family:monospace;font-size:14px;\">';" .
                    "var_dump({$m[1]});" .
                    "echo '</pre></div>'; " .
                    "?>\n";
            },
            $php
        );
    }

    /**
     * Compile @dd() directive (dump and die)
     */
    private function compileDd(string $php): string
    {
        return preg_replace_callback(
            '/@dd\(\s*((?>[^()]+|(?R))*)\s*\)/s',
            function(array $m) {
                return "\n<?php " .
                    "echo '<div style=\"background:#f5f5f5;border:1px solid #ddd;padding:1rem;margin:1rem 0;border-radius:4px;\">';" .
                    "echo '<h3 style=\"margin:0 0 0.5rem;color:#c00;\">Dump and Die</h3>';" .
                    "echo '<pre style=\"margin:0;font-family:monospace;font-size:14px;\">';" .
                    "var_dump({$m[1]});" .
                    "echo '</pre></div>'; " .
                    "exit(1); " .
                    "?>\n";
            },
            $php
        );
    }

    /**
     * Compile control structures (if, elseif, else, endif).
     *
     * We only compile directives that appear standalone on their own line:
     *   @if(...)
     *   @elseif(...)
     *   @else
     *   @endif
     *
     * This avoids the nested-parentheses bug and stray ")" characters.
     */
    private function compileConditionals(string $php): string
    {
        // @if (condition)
        // @if($slots->has('head'))
        $php = preg_replace(
            '/^[ \t]*@if\s*\((.*)\)\s*$/m',
            '<?php if ($1): ?>',
            $php
        );

        // @elseif (condition)
        $php = preg_replace(
            '/^[ \t]*@elseif\s*\((.*)\)\s*$/m',
            '<?php elseif ($1): ?>',
            $php
        );

        // @else
        $php = preg_replace(
            '/^[ \t]*@else\s*$/m',
            '<?php else: ?>',
            $php
        );

        // @endif
        $php = preg_replace(
            '/^[ \t]*@endif\s*$/m',
            '<?php endif; ?>',
            $php
        );

        return $php;
    }

    /**
     * Compile loops (foreach, for, while)
     */
    private function compileLoops(string $php): string
    {
        // Support nested parentheses in loop headers
        $foreachPattern = '/@foreach\s*\(((?>[^()]+|(?R))*)\)/';
        $forPattern     = '/@for\s*\(((?>[^()]+|(?R))*)\)/';
        $whilePattern   = '/@while\s*\(((?>[^()]+|(?R))*)\)/';

        // @foreach
        $php = preg_replace($foreachPattern, "\n<?php foreach ($1): ?>\n", $php);
        $php = preg_replace('/@endforeach\b/', "\n<?php endforeach; ?>\n", $php);

        // @for
        $php = preg_replace($forPattern, "\n<?php for ($1): ?>\n", $php);
        $php = preg_replace('/@endfor\b/', "\n<?php endfor; ?>\n", $php);

        // @while
        $php = preg_replace($whilePattern, "\n<?php while ($1): ?>\n", $php);
        $php = preg_replace('/@endwhile\b/', "\n<?php endwhile; ?>\n", $php);

        // Loop control
        $php = preg_replace('/@break\b/', '<?php break; ?>', $php);
        $php = preg_replace('/@continue\b/', '<?php continue; ?>', $php);

        return $php;
    }

    /**
     * Compile comments {{-- comment --}}
     */
    private function compileComments(string $php): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $php);
    }

    /**
     * Compile @php ... @endphp blocks and inline @php(...)
     *
     * Example:
     *   @php
     *       $x = 1;
     *       dump($x);
     *   @endphp
     *
     *   @php($foo = 'bar')
     */
    private function compilePhpBlocks(string $php): string
    {
        // Replace @php with opening PHP tag
        $php = preg_replace('/^[ \t]*@php\s*$/m', '<?php', $php);

        // Replace @endphp with closing PHP tag
        $php = preg_replace('/^[ \t]*@endphp\s*$/m', '?>', $php);

        return $php;
    }

    /**
     * Pre-process code blocks to protect template syntax
     */
    private function preProcessCodeExamples(string $source): string
    {
        // First process explicit escapes (@@ to represent literal @)
        $source = preg_replace('/@@([a-zA-Z0-9_]+)/', '___MLVIEW_ESCAPED_AT___$1', $source);

        // Process code blocks
        $source = preg_replace_callback(
            '/<code[^>]*>(.*?)<\/code>/s',
            function (array $matches) {
                $code = $matches[1];

                // Capture attributes
                $attributes = '';
                if (preg_match('/<code([^>]*)>/', $matches[0], $attrMatches)) {
                    $attributes = $attrMatches[1];
                }

                // Protect double-escaped @
                $code = preg_replace('/@@([a-zA-Z0-9_]+)/', '___MLVIEW_DOUBLE_AT___$1', $code);

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
                    $code = preg_replace('/@' . $directive . '\b/', '___MLVIEW_DIRECTIVE___' . $directive, $code);
                }

                // Generic @ protection
                $code = preg_replace('/@([a-zA-Z0-9_]+)/', '___MLVIEW_AT___$1', $code);

                return '<code' . $attributes . '>' . $code . '</code>';
            },
            $source
        );

        return $source;
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
}