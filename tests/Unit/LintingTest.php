<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use MonkeysLegion\Template\Exceptions\ParseException;
use PHPUnit\Framework\TestCase;

class LintingTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    public function testLintingCanBeDisabled(): void
    {
        $this->compiler->setEnableLinting(false);

        // This would generate invalid PHP code (syntax error) if it were compiled
        // We use a custom directive to inject raw broken PHP
        $this->compiler->getRegistry()->addDirective('broken', function() {
            return "<?php this is broken; ?>";
        });

        $source = "@broken()";
        
        // Should NOT throw exception because linting is disabled
        $php = $this->compiler->compile($source, 'test.ml.php');
        
        $this->assertStringContainsString('this is broken', $php);
    }

    public function testLintingCatchesUnclosedDirectives(): void
    {
        $this->compiler->setEnableLinting(true);

        $source = "@if(\$x)\nContent\n";
        
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unclosed directive: @if');
        
        $this->compiler->compile($source, 'test.ml.php');
    }
}
