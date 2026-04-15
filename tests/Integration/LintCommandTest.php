<?php

namespace Tests\Integration;

require_once __DIR__ . '/../Stubs/MonkeysLegion/Cli/Command.php';

use MonkeysLegion\Template\Console\Commands\LintCommand;
use PHPUnit\Framework\TestCase;

class LintCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ml_lint_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/views');
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tmpDir);
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        $files = array_diff($items, ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanup("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testLintSuccess(): void
    {
        // Valid component
        file_put_contents($this->tmpDir . '/views/button.ml.php', 'BUTTON');
        // View using it
        file_put_contents($this->tmpDir . '/views/form.ml.php', '<x-button>Click</x-button>');
        
        $cmd = new LintCommand();
        $cmd->setArgument('path', $this->tmpDir . '/views');
        
        $exitCode = $cmd->handle();
        
        $this->assertEquals(0, $exitCode);
        $this->assertContainsSuccess($cmd->getOutput(), 'No errors found');
    }

    public function testLintMissingComponent(): void
    {
        file_put_contents($this->tmpDir . '/views/broken.ml.php', '<x-missing-btn />');
        
        $cmd = new LintCommand();
        $cmd->setArgument('path', $this->tmpDir . '/views');
        
        $exitCode = $cmd->handle();
        
        $this->assertEquals(1, $exitCode);
        $this->assertContainsError($cmd->getOutput(), 'Component not found: <x-missing-btn>');
    }

    public function testLintMissingInclude(): void
    {
        file_put_contents($this->tmpDir . '/views/broken_inc.ml.php', "@include('missing.view')");
        
        $cmd = new LintCommand();
        $cmd->setArgument('path', $this->tmpDir . '/views');
        
        $exitCode = $cmd->handle();
        
        $this->assertEquals(1, $exitCode);
        $this->assertContainsError($cmd->getOutput(), "View not found: 'missing.view'");
    }

    /**
     * @param list<string> $output
     */
    private function assertContainsSuccess(array $output, string $needle): void
    {
        $found = false;
        foreach ($output as $line) {
            if (str_contains($line, '[SUCCESS]') && str_contains($line, $needle)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Failed to find success message containing '{$needle}' in output: " . implode("\n", $output));
    }

    /**
     * @param list<string> $output
     */
    private function assertContainsError(array $output, string $needle): void
    {
        $found = false;
        foreach ($output as $line) {
            if (str_contains($line, '[ERROR]') && str_contains($line, $needle)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Failed to find error message containing '{$needle}' in output: " . implode("\n", $output));
    }
}
