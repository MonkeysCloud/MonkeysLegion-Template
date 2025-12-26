<?php

namespace Tests\Integration;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\TestCase;

class VerbatimTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(new Parser());
    }

    public function testVerbatimProtectsRawEchoesAndDirectives()
    {
        $source = <<<'EOT'
@verbatim
    Hello {{ name }}
    Raw {!! raw !!}
    @if(true)
        Content
    @endif
@endverbatim
EOT;

        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        // It should NOT compile {{ name }}
        $this->assertStringNotContainsString('htmlspecialchars', $compiled);
        $this->assertStringContainsString('{{ name }}', $compiled);

        // It should NOT compile {!! raw !!}
        $this->assertStringNotContainsString('<?= raw', $compiled);
        $this->assertStringContainsString('{!! raw !!}', $compiled);

        // It should NOT compile @if
        $this->assertStringNotContainsString('<?php if(true):', $compiled);
        $this->assertStringContainsString('@if(true)', $compiled);
    }
}
