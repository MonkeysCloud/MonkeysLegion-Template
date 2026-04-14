<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Support\FilterRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FilterRegistry — Phase 2 filter system.
 */
#[CoversClass(FilterRegistry::class)]
final class FilterRegistryTest extends TestCase
{
    private FilterRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new FilterRegistry();
    }

    // =========================================================================
    // Registration & Lookup
    // =========================================================================

    #[Test]
    public function has_builtin_filters(): void
    {
        $names = $this->registry->getFilterNames();

        $this->assertContains('sort_by', $names);
        $this->assertContains('where', $names);
        $this->assertContains('relative_time', $names);
        $this->assertContains('currency', $names);
    }

    #[Test]
    public function can_register_custom_filter(): void
    {
        $this->registry->addFilter('reverse_words', function (string $value): string {
            return implode(' ', array_reverse(explode(' ', $value)));
        });

        $this->assertTrue($this->registry->hasFilter('reverse_words'));
        $result = $this->registry->apply('reverse_words', 'hello world');
        $this->assertSame('world hello', $result);
    }

    #[Test]
    public function apply_throws_for_unknown_filter(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template filter [nonexistent] is not registered.');

        $this->registry->apply('nonexistent', 'value');
    }

    // =========================================================================
    // Runtime Filters
    // =========================================================================

    #[Test]
    public function sort_by_filter(): void
    {
        $data = [
            ['name' => 'Charlie'],
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ];

        /** @var list<array{name: string}> $result */
        $result = $this->registry->apply('sort_by', $data, ['name']);

        $this->assertSame('Alice', $result[0]['name']);
        $this->assertSame('Bob', $result[1]['name']);
        $this->assertSame('Charlie', $result[2]['name']);
    }

    #[Test]
    public function where_filter(): void
    {
        $data = [
            ['status' => 'active', 'name' => 'A'],
            ['status' => 'inactive', 'name' => 'B'],
            ['status' => 'active', 'name' => 'C'],
        ];

        /** @var array<int, array{status: string, name: string}> $result */
        $result = $this->registry->apply('where', $data, ['status', 'active']);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function currency_filter(): void
    {
        $result = $this->registry->apply('currency', 42.5);
        $this->assertSame('$42.50', $result);

        $result = $this->registry->apply('currency', 1234.56, ['€', 2]);
        $this->assertSame('€1,234.56', $result);
    }

    // =========================================================================
    // Compile-Time Filter Chain
    // =========================================================================

    #[Test]
    public function compiles_single_filter(): void
    {
        $result = $this->registry->compileFilterChain('$name', [
            ['name' => 'upper', 'args' => ''],
        ]);

        $this->assertSame('strtoupper($name)', $result);
    }

    #[Test]
    public function compiles_chained_filters(): void
    {
        $result = $this->registry->compileFilterChain('$name', [
            ['name' => 'trim', 'args' => ''],
            ['name' => 'upper', 'args' => ''],
        ]);

        $this->assertSame('strtoupper(trim($name))', $result);
    }

    #[Test]
    public function compiles_filter_with_args(): void
    {
        $result = $this->registry->compileFilterChain('$price', [
            ['name' => 'number', 'args' => '2'],
        ]);

        $this->assertSame('number_format((float)($price), 2)', $result);
    }

    #[Test]
    public function compiles_default_filter(): void
    {
        $result = $this->registry->compileFilterChain('$name', [
            ['name' => 'default', 'args' => "'Anonymous'"],
        ]);

        $this->assertSame("((\$name) ?? ('Anonymous'))", $result);
    }

    #[Test]
    public function compiles_json_filter(): void
    {
        $result = $this->registry->compileFilterChain('$data', [
            ['name' => 'json', 'args' => ''],
        ]);

        $this->assertSame('json_encode($data)', $result);
    }

    #[Test]
    public function compiles_json_pretty_filter(): void
    {
        $result = $this->registry->compileFilterChain('$data', [
            ['name' => 'json_pretty', 'args' => ''],
        ]);

        $this->assertStringContainsString('JSON_PRETTY_PRINT', $result);
    }

    #[Test]
    public function compiles_type_cast_filters(): void
    {
        $this->assertSame('(int)($val)', $this->registry->compileFilterChain('$val', [
            ['name' => 'int', 'args' => ''],
        ]));

        $this->assertSame('(float)($val)', $this->registry->compileFilterChain('$val', [
            ['name' => 'float', 'args' => ''],
        ]));

        $this->assertSame('(string)($val)', $this->registry->compileFilterChain('$val', [
            ['name' => 'string', 'args' => ''],
        ]));
    }

    // =========================================================================
    // Static Helpers
    // =========================================================================

    #[Test]
    public function formatBytes_formats_correctly(): void
    {
        $this->assertSame('0 B', FilterRegistry::formatBytes(0));
        $this->assertSame('1 KB', FilterRegistry::formatBytes(1024));
        $this->assertSame('1.5 KB', FilterRegistry::formatBytes(1536));
        $this->assertSame('1 MB', FilterRegistry::formatBytes(1048576));
    }

    #[Test]
    public function truncate_truncates_with_suffix(): void
    {
        $this->assertSame('Hello...', FilterRegistry::truncate('Hello World', 5));
        $this->assertSame('Hello World', FilterRegistry::truncate('Hello World', 100));
        $this->assertSame('He~~', FilterRegistry::truncate('Hello', 2, '~~'));
    }

    #[Test]
    public function ascii_transliterates(): void
    {
        // Only test if intl extension is available
        if (!function_exists('transliterator_transliterate')) {
            $this->markTestSkipped('intl extension required for ascii filter.');
        }

        $this->assertSame('cafe', FilterRegistry::ascii('café'));
        $this->assertSame('uber', FilterRegistry::ascii('über'));
    }

    // =========================================================================
    // Array Filters (compile-time)
    // =========================================================================

    #[Test]
    public function compiles_array_filters(): void
    {
        $join = $this->registry->compileFilterChain('$items', [
            ['name' => 'join', 'args' => "', '"],
        ]);
        $this->assertStringContainsString("implode(', ', (array)(\$items))", $join);

        $first = $this->registry->compileFilterChain('$items', [
            ['name' => 'first', 'args' => ''],
        ]);
        $this->assertStringContainsString('reset', $first);

        $count = $this->registry->compileFilterChain('$items', [
            ['name' => 'count', 'args' => ''],
        ]);
        $this->assertStringContainsString('count', $count);

        $keys = $this->registry->compileFilterChain('$items', [
            ['name' => 'keys', 'args' => ''],
        ]);
        $this->assertStringContainsString('array_keys', $keys);
    }

    // =========================================================================
    // String Filters (compile-time)
    // =========================================================================

    #[Test]
    public function compiles_string_filters(): void
    {
        $slug = $this->registry->compileFilterChain('$title', [
            ['name' => 'slug', 'args' => ''],
        ]);
        $this->assertStringContainsString('preg_replace', $slug);
        $this->assertStringContainsString('strtolower', $slug);

        $nl2br = $this->registry->compileFilterChain('$text', [
            ['name' => 'nl2br', 'args' => ''],
        ]);
        $this->assertSame('nl2br($text)', $nl2br);

        $length = $this->registry->compileFilterChain('$str', [
            ['name' => 'length', 'args' => ''],
        ]);
        $this->assertSame('strlen($str)', $length);
    }

    // =========================================================================
    // Encoding Filters (compile-time)
    // =========================================================================

    #[Test]
    public function compiles_encoding_filters(): void
    {
        $md5 = $this->registry->compileFilterChain('$email', [
            ['name' => 'md5', 'args' => ''],
        ]);
        $this->assertSame("md5((string)(\$email))", $md5);

        $base64 = $this->registry->compileFilterChain('$data', [
            ['name' => 'base64', 'args' => ''],
        ]);
        $this->assertSame('base64_encode((string)($data))', $base64);

        $urlEncode = $this->registry->compileFilterChain('$str', [
            ['name' => 'url_encode', 'args' => ''],
        ]);
        $this->assertSame('rawurlencode((string)($str))', $urlEncode);
    }
}
