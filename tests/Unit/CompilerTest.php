<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Parser;
use PHPUnit\Framework\TestCase;

class CompilerTest extends TestCase
{
    private Compiler $compiler;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
        $this->compiler = new Compiler($this->parser);
    }

    public function testItCompilesEchoes(): void
    {
        $source = 'Hello {{ $name }}';
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        $this->assertStringContainsString("<?= \MonkeysLegion\Template\Support\Escaper::html(\$name) ?>", $compiled);
    }

    public function testItCompilesRawEchoes(): void
    {
        $source = 'Hello {!! $name !!}';
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        $this->assertStringContainsString("<?= \$name ?? '' ?>", $compiled);
    }

    public function testItCompilesAttributeEchoes(): void
    {
        $source = '<div class="{{ $class }}">';
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        // Compiler preserves whitespace in attributes
        $this->assertStringContainsString('class="<?= \MonkeysLegion\Template\Support\Escaper::attr( $class ) ?>"', $compiled);
    }

    public function testItCompilesConditionals(): void
    {
        $source = <<<'EOT'
@if($check)
    Yes
@elseif($other)
    Maybe
@else
    No
@endif
EOT;
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        // Compiler adds 'use MonkeysLegion...' at the top, so we check for presence of control structures.
        // Also handling potential newline differences or whitespace in the regex replacement in Compiler.php
        $this->assertStringContainsString('if ($check):', $compiled);
        $this->assertStringContainsString('elseif ($other):', $compiled);
        $this->assertStringContainsString('else:', $compiled);
        $this->assertStringContainsString('endif;', $compiled);
    }

    public function testItCompilesForeach(): void
    {
        $source = <<<'EOT'
@foreach($items as $item)
    {{ $item }}
@endforeach
EOT;
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        $this->assertStringContainsString('foreach($__currentLoopData as $item):', $compiled);
        $this->assertStringContainsString('$this->getLastLoop()->tick(); endforeach;', $compiled);
    }

    public function testItCompilesJsonDirective(): void
    {
        $source = "@json(\$data)";
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        $this->assertStringContainsString("json_encode(\$data, JSON_HEX_TAG", $compiled);
    }

    public function testItCompilesClassHelper(): void
    {
        $source = "@class(['btn', 'active' => true])";
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        $this->assertStringContainsString("AttributeBag::conditional(['btn', 'active' => true])", $compiled);
    }

    public function testItCompilesEmpty(): void
    {
        $source = "@empty(\$list)\nEmpty\n@endempty";
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        // Check exact output to debug syntax error
        // Note: Compiler injects headers, so we check availability of control structure
        $this->assertStringContainsString('if (empty($list)):', $compiled);
    }

    public function testItCompilesNestedParentheses(): void
    {
        $source = <<<'EOT'
@foreach($request->getHeaders() as $k => $v)
    {{ $k }}: {{ $v }}
@endforeach

<input type="checkbox" @checked($user->hasRole('admin', ['super']))>
EOT;
        $compiled = $this->compiler->compile($source, '/tmp/test.ml.php');

        $this->assertStringContainsString('foreach($__currentLoopData as $k => $v):', $compiled);
        $this->assertStringContainsString('$this->getLastLoop()->tick(); endforeach;', $compiled);
        $this->assertStringContainsString("<?= (\$user->hasRole('admin', ['super'])) ? 'checked' : '' ?>", $compiled);
    }
}
