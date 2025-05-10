<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use RuntimeException;

/**
 * Executes compiled PHP templates and captures their output.
 */
class Renderer
{
    /**
     * Render a compiled template file with provided data.
     *
     * @param string $compiledPath Full path to the compiled PHP template.
     * @param array  $data         Variables to extract into the template scope.
     * @return string              Rendered template output.
     * @throws RuntimeException   If the compiled file does not exist.
     */
    public function render(string $compiledPath, array $data = []): string
    {
        if (! is_file($compiledPath)) {
            throw new RuntimeException("Compiled template not found: {$compiledPath}");
        }

        // Extract variables into local scope, skipping conflicts
        extract($data, EXTR_SKIP);

        // Start output buffering
        ob_start();

        // Include the compiled template (it will echo content)
        include $compiledPath;

        // Get buffer contents and clean
        return (string) ob_get_clean();
    }
}