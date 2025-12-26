<?php

namespace Tests\Integration;

use Tests\TestCase;

class ExtensibilityTest extends TestCase
{
    public function testCustomDirective()
    {
        // Define a custom directive @datetime($timestamp)
        $this->compiler->getRegistry()->addDirective('datetime', function ($expression) {
            return "<?php echo date('Y-m-d H:i:s', {$expression}); ?>";
        });

        $this->createView('custom_directive', 'Time: @datetime(1672531200)');
        
        $output = $this->renderer->render('custom_directive');
        // 2023-01-01 00:00:00 UTC (assuming UTC or system time, let's match format mostly)
        $this->assertStringContainsString('2023-01-01', $output);
    }

    public function testCustomFilter()
    {
        // Define a filter 'shout' -> strtoupper
        $this->compiler->getRegistry()->addFilter('shout', function ($value) {
            return strtoupper($value);
        });

        // Using pipe syntax
        $this->createView('custom_filter', 'Hello {{ "world" | shout }}');

        $output = $this->renderer->render('custom_filter');
        $this->assertEquals('Hello WORLD', $output);
    }

    public function testFilterWithArguments()
    {
        // Define 'limit' filter
        $this->compiler->getRegistry()->addFilter('limit', function ($value, $limit) {
            return substr($value, 0, $limit);
        });

        $this->createView('filter_args', '{{ "hello world" | limit(5) }}');
        
        $output = $this->renderer->render('filter_args');
        $this->assertEquals('hello', $output);
    }
    
    public function testChainedFilters()
    {
        $this->compiler->getRegistry()->addFilter('lower', fn($v) => strtolower($v));
        $this->compiler->getRegistry()->addFilter('ucfirst', fn($v) => ucfirst($v));
        
        $this->createView('chain', '{{ "HELLO WORLD" | lower | ucfirst }}');
        
        $output = $this->renderer->render('chain');
        $this->assertEquals('Hello world', $output);
    }

    public function testUnknownFilterStrictFallback()
    {
        // Strict mode on: unknown filter should error if we implemented that logic check
        // The current implementation calls checkStrictRaw if filter not found in registry (?) 
        // Wait, my logic for missing filter fallback was:
        // if registry has filter -> call it
        // else -> checkStrictRaw("Filter ... not found")
        // But the checkStrictRaw triggers warning only if strict mode is ON.
        
        $this->compiler->setStrictMode(true);
        $this->createView('unknown_filter', '{{ "foo" | unknown }}');
        
        $caught = false;
        set_error_handler(function ($errno, $errstr) use (&$caught) {
            if (str_contains($errstr, 'Filter unknown not found')) {
                $caught = true;
            }
            return true;
        });
        
        try {
            $this->renderer->render('unknown_filter');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($caught, 'Warning not triggered for unknown filter in strict mode');
    }
}
