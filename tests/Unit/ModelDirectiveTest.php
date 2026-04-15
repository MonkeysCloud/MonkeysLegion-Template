<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for @model() Razor-style type hint directive.
 */
final class ModelDirectiveTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    private function compile(string $source): string
    {
        return $this->compiler->compile($source, '/tmp/test.ml.php');
    }

    #[Test]
    public function model_generates_phpdoc_var(): void
    {
        $compiled = $this->compile('@model(App\\Entity\\User)');

        $this->assertStringContainsString('@var', $compiled);
        $this->assertStringContainsString('\\App\\Entity\\User', $compiled);
        $this->assertStringContainsString('$model', $compiled);
    }

    #[Test]
    public function model_with_simple_class(): void
    {
        $compiled = $this->compile('@model(User)');

        $this->assertStringContainsString('@var \\User $model', $compiled);
    }

    #[Test]
    public function model_with_deeply_nested_namespace(): void
    {
        $compiled = $this->compile('@model(App\\Domain\\User\\Entities\\UserProfile)');

        $this->assertStringContainsString('\\App\\Domain\\User\\Entities\\UserProfile', $compiled);
    }

    #[Test]
    public function model_preserves_surrounding_content(): void
    {
        $compiled = $this->compile("@model(App\\Entity\\Order)\n<h1>Order #{{ \$model->id }}</h1>");

        $this->assertStringContainsString('@var', $compiled);
        $this->assertStringContainsString('Order #', $compiled);
    }

    #[Test]
    public function model_does_not_generate_runtime_code(): void
    {
        $compiled = $this->compile('@model(App\\Models\\Post)');

        // Should only be a PHP comment, not executable code
        $this->assertStringContainsString('/**', $compiled);
        $this->assertStringContainsString('*/', $compiled);
    }
}
