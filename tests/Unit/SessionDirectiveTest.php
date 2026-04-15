<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dedicated tests for @session / @endsession directive.
 */
final class SessionDirectiveTest extends TestCase
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
    public function session_checks_existence(): void
    {
        $compiled = $this->compile("@session('flash')\n{{ \$value }}\n@endsession");

        $this->assertStringContainsString("session()->has('flash')", $compiled);
    }

    #[Test]
    public function session_injects_value_variable(): void
    {
        $compiled = $this->compile("@session('message')\nMsg: {{ \$value }}\n@endsession");

        $this->assertStringContainsString("session()->get('message')", $compiled);
        $this->assertStringContainsString('$value', $compiled);
    }

    #[Test]
    public function session_closes_with_endif(): void
    {
        $compiled = $this->compile("@session('key')\nContent\n@endsession");

        $this->assertStringContainsString('endif;', $compiled);
    }

    #[Test]
    public function session_checks_function_exists(): void
    {
        $compiled = $this->compile("@session('test')\nOK\n@endsession");

        $this->assertStringContainsString("function_exists('session')", $compiled);
    }
}
