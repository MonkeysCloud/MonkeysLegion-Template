<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

/**
 * Twig/Jinja2-inspired filter system for MLView templates.
 *
 * Filters are applied to expressions using the pipe syntax:
 *   {{ $name | upper }}
 *   {{ $price | number(2, '.', ',') | prepend('$') }}
 *   {{ $items | pluck('name') | join(', ') }}
 *
 * Custom filters can be registered at runtime via addFilter().
 */
final class FilterRegistry
{
    /** @var array<string, callable> */
    private array $filters = [];

    public function __construct()
    {
        $this->registerBuiltinFilters();
    }

    /**
     * Register a custom filter.
     */
    public function addFilter(string $name, callable $handler): void
    {
        $this->filters[$name] = $handler;
    }

    /**
     * Check if a filter exists.
     */
    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    /**
     * Apply a filter by name with arguments.
     *
     * @param array<int, mixed> $args
     */
    public function apply(string $name, mixed $value, array $args = []): mixed
    {
        if (!isset($this->filters[$name])) {
            throw new \RuntimeException("Template filter [{$name}] is not registered.");
        }

        return call_user_func($this->filters[$name], $value, ...$args);
    }

    /**
     * Get all registered filter names.
     *
     * @return list<string>
     */
    public function getFilterNames(): array
    {
        return array_keys($this->filters);
    }

    /**
     * Generate PHP code to apply a filter chain.
     *
     * Given an expression and a list of filter definitions,
     * generates the PHP code that wraps the value through each filter.
     *
     * @param string $expression The PHP expression to filter
     * @param list<array{name: string, args: string}> $filters
     */
    public function compileFilterChain(string $expression, array $filters): string
    {
        $result = $expression;

        foreach ($filters as $filter) {
            $name = $filter['name'];
            $args = $filter['args'];

            if ($this->isBuiltinCompilable($name)) {
                $result = $this->compileBuiltin($name, $result, $args);
            } else {
                // Runtime filter call via registry
                $argsCode = $args !== '' ? ', ' . $args : '';
                $result = "\$__env->getFilterRegistry()->apply('{$name}', {$result}" .
                    ($argsCode !== '' ? ", [{$argsCode}]" : '') . ')';
            }
        }

        return $result;
    }

    /**
     * Check if a filter can be compiled to raw PHP (no runtime lookup).
     */
    private function isBuiltinCompilable(string $name): bool
    {
        return isset(self::COMPILABLE_FILTERS[$name]);
    }

    /**
     * Compile a built-in filter to raw PHP.
     */
    private function compileBuiltin(string $name, string $expression, string $args): string
    {
        $template = self::COMPILABLE_FILTERS[$name];

        // Replace {expr} with the expression
        $result = str_replace('{expr}', $expression, $template);

        // Replace {rawargs} — args inserted as-is (no comma prefix)
        if (str_contains($result, '{rawargs}')) {
            $result = str_replace('{rawargs}', $args, $result);
        }

        // Replace {args} — extra function arguments (comma-prefixed when present)
        if (str_contains($result, '{args}')) {
            $result = str_replace('{args}', $args !== '' ? ', ' . $args : '', $result);
        }

        return $result;
    }

    /**
     * Filters that compile to raw PHP for zero runtime overhead.
     *
     * @var array<string, string>
     */
    private const array COMPILABLE_FILTERS = [
        // String filters
        'upper'      => 'strtoupper({expr})',
        'lower'      => 'strtolower({expr})',
        'capitalize' => 'ucfirst({expr})',
        'title'      => 'ucwords({expr})',
        'trim'       => 'trim({expr}{args})',
        'ltrim'      => 'ltrim({expr}{args})',
        'rtrim'      => 'rtrim({expr}{args})',
        'nl2br'      => 'nl2br({expr})',
        'wordwrap'   => 'wordwrap({expr}{args})',
        'length'     => 'strlen({expr})',
        'reverse'    => 'strrev({expr})',
        'repeat'     => 'str_repeat({expr}{args})',
        'replace'    => 'str_replace({rawargs}, {expr})',
        'split'      => 'explode({rawargs}, {expr})',
        'slug'       => 'preg_replace(\'/[^a-z0-9]+/\', \'-\', strtolower(trim({expr})))',
        'studly'     => 'str_replace(\' \', \'\', ucwords(str_replace([\'-\', \'_\'], \' \', {expr})))',
        'camel'      => 'lcfirst(str_replace(\' \', \'\', ucwords(str_replace([\'-\', \'_\'], \' \', {expr}))))',
        'snake'      => 'strtolower(preg_replace(\'/[A-Z]/\', \'_$0\', lcfirst({expr})))',

        // Escaping
        'e'          => 'htmlspecialchars((string)({expr}), ENT_QUOTES, \'UTF-8\', false)',
        'raw'        => '{expr}',
        'escape'     => '\\MonkeysLegion\\Template\\Support\\Escaper::escape({expr}{args})',

        // Formatting
        'number'     => 'number_format((float)({expr}){args})',
        'date'       => '(({expr}) instanceof \\DateTimeInterface ? ({expr})->format({rawargs}) : date({rawargs}, is_numeric({expr}) ? (int)({expr}) : strtotime((string)({expr}))))',
        'bytes'      => '\\MonkeysLegion\\Template\\Support\\FilterRegistry::formatBytes({expr}{args})',

        // Array
        'join'       => 'implode({rawargs}, (array)({expr}))',
        'first'      => '(is_array({expr}) ? reset({expr}) : {expr})',
        'last'       => '(is_array({expr}) ? end({expr}) : {expr})',
        'count'      => 'count((array)({expr}))',
        'sort'       => '(function($a) { sort($a); return $a; })((array)({expr}))',
        'keys'       => 'array_keys((array)({expr}))',
        'values'     => 'array_values((array)({expr}))',
        'unique'     => 'array_unique((array)({expr}))',
        'flatten'    => 'array_merge(...array_map(fn($v) => (array)$v, (array)({expr})))',
        'chunk'      => 'array_chunk((array)({expr}){args})',
        'slice'      => 'array_slice((array)({expr}){args})',
        'pluck'      => 'array_column((array)({expr}){args})',

        // Encoding
        'json'       => 'json_encode({expr}{args})',
        'json_pretty' => 'json_encode({expr}, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)',
        'url_encode' => 'rawurlencode((string)({expr}))',
        'base64'     => 'base64_encode((string)({expr}))',
        'base64_decode' => 'base64_decode((string)({expr}))',
        'md5'        => 'md5((string)({expr}))',
        'sha256'     => 'hash(\'sha256\', (string)({expr}))',

        // Type casting
        'int'        => '(int)({expr})',
        'float'      => '(float)({expr})',
        'string'     => '(string)({expr})',
        'bool'       => '(bool)({expr})',
        'array'      => '(array)({expr})',

        // Fallback
        'default'    => '(({expr}) ?? ({rawargs}))',
        'fallback'   => '(({expr}) !== \'\' && ({expr}) !== null ? ({expr}) : ({rawargs}))',

        // String manipulation
        'truncate'   => '\\MonkeysLegion\\Template\\Support\\FilterRegistry::truncate({expr}{args})',
        'ascii'      => '\\MonkeysLegion\\Template\\Support\\FilterRegistry::ascii({expr})',
        'abs'        => 'abs({expr})',
        'ceil'       => 'ceil({expr})',
        'floor'      => 'floor({expr})',
        'round'      => 'round({expr}{args})',
        'max'        => 'max({expr}{args})',
        'min'        => 'min({expr}{args})',
    ];

    /**
     * Register runtime-only filters (non-compilable).
     */
    private function registerBuiltinFilters(): void
    {
        // sort_by — array sorting by key
        $this->filters['sort_by'] = function (mixed $value, string $key): array {
            $arr = (array) $value;
            usort($arr, fn($a, $b) => ($a[$key] ?? '') <=> ($b[$key] ?? ''));
            return $arr;
        };

        // where — filter array by key/value
        $this->filters['where'] = function (mixed $value, string $key, mixed $match): array {
            return array_filter((array) $value, fn($item) => ($item[$key] ?? null) === $match);
        };

        // map — apply callback to array
        $this->filters['map'] = function (mixed $value, callable $callback): array {
            return array_map($callback, (array) $value);
        };

        // relative_time — human-readable time difference
        $this->filters['relative_time'] = function (mixed $value): string {
            $timestamp = $value instanceof \DateTimeInterface
                ? $value->getTimestamp()
                : (is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: time()));
            $diff = time() - $timestamp;

            if ($diff < 60) {
                return 'just now';
            }
            if ($diff < 3600) {
                return (int) ($diff / 60) . 'm ago';
            }
            if ($diff < 86400) {
                return (int) ($diff / 3600) . 'h ago';
            }
            if ($diff < 2592000) {
                return (int) ($diff / 86400) . 'd ago';
            }

            return date('M j, Y', $timestamp);
        };

        // currency — format as currency
        $this->filters['currency'] = function (mixed $value, string $symbol = '$', int $decimals = 2): string {
            return $symbol . number_format((float) $value, $decimals);
        };
    }

    // -- Static helpers called from compilable filters --

    /**
     * Format bytes into human-readable size.
     */
    public static function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? (int) floor(log((float) $bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }

    /**
     * Truncate string with suffix.
     */
    public static function truncate(string $value, int $length = 100, string $end = '...'): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . $end;
    }

    /**
     * Convert string to ASCII (transliterate).
     */
    public static function ascii(string $value): string
    {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        return $transliterated !== false ? $transliterated : $value;
    }
}
