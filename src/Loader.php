<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Responsible for locating raw template files and compiled cache files by name.
 */
class Loader
{
    private string $sourcePath;
    private string $cachePath;
    private string $templateExtension;

    /**
     * @param string $sourcePath         Directory where raw templates reside
     * @param string $cachePath          Directory where compiled templates are stored
     * @param string $templateExtension  Extension of raw template files (default ".ml.php")
     */
    public function __construct(
        string $sourcePath,
        string $cachePath,
        string $templateExtension = '.ml.php'
    ) {
        $this->sourcePath        = rtrim($sourcePath, '/\\');
        $this->cachePath         = rtrim($cachePath, '/\\');
        $this->templateExtension = $templateExtension;
    }

    /**
     * Get the full filesystem path for a given template source.
     * E.g. 'users.index' → '/views/users/index.ml.php'
     *
     * @param string $name  Dot-notated template name
     * @return string       Full file path
     * @throws RuntimeException if the file does not exist
     */
    public function getSourcePath(string $name): string
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name)
            . $this->templateExtension;
        $path = $this->sourcePath . DIRECTORY_SEPARATOR . $relative;

        if (! is_file($path)) {
            throw new RuntimeException("Template source not found: {$path}");
        }

        return $path;
    }

    /**
     * Get the full filesystem path for the compiled template.
     * E.g. 'users.index' → '/cache/users/index.php'
     *
     * @param string $name  Dot-notated template name
     * @return string       Compiled file path (may not yet exist)
     */
    public function getCompiledPath(string $name): string
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.php';
        return $this->cachePath . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * (Optional) Create the compiled directory if missing.
     *
     * @param string $path  File path to ensure directory exists for
     */
    public function ensureCompiledDir(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
