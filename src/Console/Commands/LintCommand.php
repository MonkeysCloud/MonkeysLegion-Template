<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Console\Commands;

use MonkeysLegion\Cli\Command;
use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use MonkeysLegion\Template\Lexer\Lexer;
use MonkeysLegion\Template\Loader;
use MonkeysLegion\Template\Parser;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lints template files for syntax errors and missing components/includes.
 *
 * Uses the Lexer for syntax validation (balanced tags, directives)
 * and the Parser for dependency checking.
 */
class LintCommand extends Command
{
    protected string $signature = 'lint {path : Paths to lint (comma separated)}';
    protected string $description = 'Lint template files for missing dependencies';

    public function handle(): int
    {
        $pathsParam = $this->argument('path');
        if (!$pathsParam) {
            $this->error("Please provide a path to lint.");
            return 1;
        }

        $paths = explode(',', $pathsParam);
        $parser = new Parser();
        $lexer = new Lexer();
        
        // We need a Loader to check if views exist.
        // For CLI usage, we assume the first path is the root.
        $loaderRoot = $paths[0];
        $loader = new Loader($loaderRoot, sys_get_temp_dir() . '/ml_lint_cache');
        
        $hasErrors = false;

        foreach ($paths as $path) {
            $path = rtrim($path, '/\\');
            if (!is_dir($path)) {
                $this->error("Directory not found: {$path}");
                continue;
            }

            $this->info("Linting directory: {$path}");
            
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isDir() || !str_ends_with($file->getFilename(), '.ml.php')) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                if ($content === false) continue;

                // Phase 1: Lexer-based syntax validation
                try {
                    $lexer->tokenize($content);
                } catch (TemplateSyntaxException $e) {
                    $this->error("  [{$file->getFilename()}] Syntax error: {$e->getMessage()}");
                    $hasErrors = true;
                    continue; // Skip dependency check if syntax is invalid
                }

                $deps = $parser->getDependencies($content);
                $fileHasErrors = false;

                // Check Components
                foreach ($deps['components'] as $component) {
                    if (str_starts_with($component, 'slot')) continue; // <x-slot:name> is not a component file
                    
                    try {
                        // resolveComponent logic from Renderer... needs to be replicated or exposed?
                        // Renderer::resolveComponent checks 'components', 'layouts', 'partials' prefixes.
                        // We can manually check these via Loader.
                        $found = false;
                        $prefixes = ['components', 'layouts', 'partials'];
                        foreach ($prefixes as $prefix) {
                            try {
                                $loader->getSourcePath($prefix . '.' . str_replace('/', '.', $component));
                                $found = true;
                                break;
                            } catch (RuntimeException) {}
                        }
                        
                        if (!$found) {
                             // Try direct?
                             try {
                                 $loader->getSourcePath(str_replace('/', '.', $component));
                                 $found = true;
                             } catch(RuntimeException) {}
                        }
                        
                        if (!$found) {
                            $this->error("  [" . $file->getFilename() . "] Component not found: <x-{$component}>");
                            $fileHasErrors = true;
                        }
                    } catch (RuntimeException) {
                        $this->error("  [" . $file->getFilename() . "] Component resolve error: <x-{$component}>");
                        $fileHasErrors = true;
                    }
                }

                // Check Includes & Layouts
                $includes = array_merge($deps['includes'], $deps['layouts']);
                foreach ($includes as $include) {
                    try {
                        $loader->getSourcePath($include);
                    } catch (RuntimeException) {
                        $this->error("  [" . $file->getFilename() . "] View not found: '{$include}'");
                        $fileHasErrors = true;
                    }
                }
                
                if ($fileHasErrors) {
                    $hasErrors = true;
                }
            }
        }

        if ($hasErrors) {
            $this->error("Lint found errors.");
            return 1;
        }

        $this->success("No errors found!");
        return 0;
    }
}
