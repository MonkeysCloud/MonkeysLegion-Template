<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Console\Commands;

use MonkeysLegion\Cli\Command;
use MonkeysLegion\Template\Compiler;
use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use MonkeysLegion\Template\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Precompile and manage template cache.
 *
 * Subcommands:
 *   view:compile — precompile all templates
 *   view:clear  — clear compiled cache
 */
class CompileCommand extends Command
{
    protected string $signature = 'view:compile {path : Template root directory} {--cache= : Cache directory}';
    protected string $description = 'Precompile all template files for production';

    private ?Parser $parser = null;
    private ?Compiler $compiler = null;

    private function getParser(): Parser
    {
        return $this->parser ??= new Parser();
    }

    private function getCompiler(): Compiler
    {
        return $this->compiler ??= new Compiler($this->getParser());
    }

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!$path || !is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return 1;
        }

        $cacheDir = $this->option('cache') ?? sys_get_temp_dir() . '/ml_compiled_views';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        return $this->compileAll($path, $cacheDir);
    }

    /**
     * Compile all templates in the given directory.
     */
    public function compileAll(string $path, string $cacheDir): int
    {
        $compiled = 0;
        $errors = 0;
        $errorDetails = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir() || !str_ends_with($file->getFilename(), '.ml.php')) {
                continue;
            }

            $filePath = $file->getPathname();
            $content = file_get_contents($filePath);
            if ($content === false) {
                $errorDetails[] = ['file' => $filePath, 'error' => 'Failed to read file'];
                $errors++;
                continue;
            }

            try {
                $parsed = $this->getParser()->parse($content);
                $php = $this->getCompiler()->compile($parsed, $filePath);

                $cacheFile = $this->getCacheFilePath($filePath, $cacheDir);
                file_put_contents($cacheFile, $php);

                $compiled++;
            } catch (TemplateSyntaxException $e) {
                $errorDetails[] = [
                    'file' => $filePath,
                    'line' => $e->templateLine,
                    'error' => $e->getErrorMessage(),
                ];
                $errors++;
            } catch (\Throwable $e) {
                $errorDetails[] = [
                    'file' => $filePath,
                    'error' => $e->getMessage(),
                ];
                $errors++;
            }
        }

        if (!empty($errorDetails)) {
            foreach ($errorDetails as $detail) {
                $loc = isset($detail['line']) ? " (line {$detail['line']})" : '';
                $this->error("  ✗ {$detail['file']}{$loc}: {$detail['error']}");
            }
        }

        if ($errors > 0) {
            $this->error("Compiled {$compiled} templates with {$errors} error(s).");
            return 1;
        }

        $this->success("Successfully compiled {$compiled} templates.");
        return 0;
    }

    /**
     * Clear all compiled templates.
     */
    public function clearCache(string $cacheDir): int
    {
        if (!is_dir($cacheDir)) {
            $this->info('Cache directory does not exist.');
            return 0;
        }

        $files = glob($cacheDir . '/*.php');
        if ($files === false) {
            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        $this->success("Cleared {$count} compiled template(s).");
        return 0;
    }

    /**
     * Get the cache file path for a template.
     */
    private function getCacheFilePath(string $templatePath, string $cacheDir): string
    {
        $hash = md5($templatePath);
        $name = pathinfo($templatePath, PATHINFO_FILENAME);
        return $cacheDir . DIRECTORY_SEPARATOR . $name . '_' . $hash . '.php';
    }
}
