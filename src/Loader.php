<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Responsible for locating raw template files by name.
 */
class Loader
{
    private string $basePath;
    private string $extension;

    /**
     * @param string $basePath   Directory where templates reside
     * @param string $extension  File extension, e.g. '.ml.php'
     */
    public function __construct(string $basePath, string $extension = '.ml.php')
    {
        $this->basePath  = rtrim($basePath, '/\\');
        $this->extension = $extension;
    }

    /**
     * Get the full filesystem path for a given template name.
     * E.g. 'users.index' → '/path/to/views/users/index.ml.php'
     *
     * @param string $name  Dot-notated template name
     * @return string       Full file path
     * @throws RuntimeException if the file does not exist
     */
    public function getPath(string $name): string
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name) . $this->extension;
        $path     = $this->basePath . DIRECTORY_SEPARATOR . $relative;

        if (! is_file($path)) {
            throw new RuntimeException("Template not found: {$path}");
        }

        return $path;
    }

    /**
     * Retrieve the configured base directory for templates.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}