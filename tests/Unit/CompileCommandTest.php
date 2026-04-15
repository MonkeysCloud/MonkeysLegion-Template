<?php

declare(strict_types=1);

namespace Tests\Unit;

use MonkeysLegion\Template\Console\Commands\CompileCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the view:compile and view:clear commands.
 */
final class CompileCommandTest extends TestCase
{
    private string $tempViewDir;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->tempViewDir = sys_get_temp_dir() . '/ml_compile_test_views_' . uniqid();
        $this->tempCacheDir = sys_get_temp_dir() . '/ml_compile_test_cache_' . uniqid();
        mkdir($this->tempViewDir, 0755, true);
        mkdir($this->tempCacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up
        $this->removeDir($this->tempViewDir);
        $this->removeDir($this->tempCacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createCompileCommand(): CompileCommand
    {
        return new CompileCommand();
    }

    #[Test]
    public function compiles_valid_templates(): void
    {
        file_put_contents(
            $this->tempViewDir . '/hello.ml.php',
            '<h1>{{ $name }}</h1>',
        );

        $command = $this->createCompileCommand();
        $result = $command->compileAll($this->tempViewDir, $this->tempCacheDir);

        $this->assertSame(0, $result);

        // Should have created a compiled file
        $files = glob($this->tempCacheDir . '/*.php') ?: [];
        $this->assertNotEmpty($files);
    }

    #[Test]
    public function compiled_output_is_valid_php(): void
    {
        file_put_contents(
            $this->tempViewDir . '/valid.ml.php',
            '@if($show)<p>{{ $name }}</p>@endif',
        );

        $command = $this->createCompileCommand();
        $command->compileAll($this->tempViewDir, $this->tempCacheDir);

        $files = glob($this->tempCacheDir . '/*.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content);
            $this->assertStringContainsString('<?php', $content);
        }
    }

    #[Test]
    public function compiles_multiple_templates(): void
    {
        file_put_contents($this->tempViewDir . '/page1.ml.php', '<h1>Page 1</h1>');
        file_put_contents($this->tempViewDir . '/page2.ml.php', '<h2>Page 2</h2>');
        file_put_contents($this->tempViewDir . '/page3.ml.php', '<h3>Page 3</h3>');

        $command = $this->createCompileCommand();
        $result = $command->compileAll($this->tempViewDir, $this->tempCacheDir);

        $this->assertSame(0, $result);

        $files = glob($this->tempCacheDir . '/*.php') ?: [];
        $this->assertCount(3, $files);
    }

    #[Test]
    public function compiles_templates_in_subdirectories(): void
    {
        mkdir($this->tempViewDir . '/layouts', 0755, true);
        file_put_contents($this->tempViewDir . '/layouts/app.ml.php', '<html>{{ $slot }}</html>');

        $command = $this->createCompileCommand();
        $result = $command->compileAll($this->tempViewDir, $this->tempCacheDir);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function ignores_non_template_files(): void
    {
        file_put_contents($this->tempViewDir . '/readme.md', '# Readme');
        file_put_contents($this->tempViewDir . '/style.css', 'body {}');
        file_put_contents($this->tempViewDir . '/actual.ml.php', '<p>Content</p>');

        $command = $this->createCompileCommand();
        $command->compileAll($this->tempViewDir, $this->tempCacheDir);

        // Only the .ml.php file should be compiled
        $files = glob($this->tempCacheDir . '/*.php') ?: [];
        $this->assertCount(1, $files);
    }

    #[Test]
    public function clear_cache_removes_files(): void
    {
        // Create some compiled files
        file_put_contents($this->tempCacheDir . '/compiled1.php', '<?php echo "1"; ?>');
        file_put_contents($this->tempCacheDir . '/compiled2.php', '<?php echo "2"; ?>');

        $command = $this->createCompileCommand();
        $result = $command->clearCache($this->tempCacheDir);

        $this->assertSame(0, $result);

        $files = glob($this->tempCacheDir . '/*.php') ?: [];
        $this->assertEmpty($files);
    }

    #[Test]
    public function clear_cache_handles_empty_dir(): void
    {
        $command = $this->createCompileCommand();
        $result = $command->clearCache($this->tempCacheDir);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function clear_cache_handles_nonexistent_dir(): void
    {
        $command = $this->createCompileCommand();
        $result = $command->clearCache('/nonexistent/path' . uniqid());

        $this->assertSame(0, $result);
    }
}
