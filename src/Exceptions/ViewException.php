<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Exceptions;

use MonkeysLegion\Template\SourceMap;
use RuntimeException;
use Throwable;

/**
 * Runtime view exception with source map support for accurate error reporting.
 *
 * Thrown during template rendering when a PHP error occurs in compiled output.
 * Uses the source map to translate compiled PHP line numbers back to the
 * original template source file and line.
 */
class ViewException extends RuntimeException
{
    /** @var string|null Original template name that caused the error */
    private ?string $templateName = null;

    /** @var SourceMap|null Source map for compiled→source line translation */
    private ?SourceMap $sourceMap = null;

    /** @var int|null Resolved source line from the original template */
    private ?int $sourceLine = null;

    /** @var string|null Resolved source file path from the original template */
    private ?string $sourceFile = null;

    /** @phpstan-ignore-next-line constructor.unusedParameter */
    public function __construct(
        string $message,
        int $code = 0,
        int $severity = 1,
        string $filename = __FILE__,
        int $line = __LINE__,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $currCode, $previous);

        $this->file = $filename;
        $this->line = $line;
    }

    /**
     * Set the original template name for error context.
     */
    public function setTemplateName(string $name): self
    {
        $this->templateName = $name;
        return $this;
    }

    /**
     * Get the original template name.
     */
    public function getTemplateName(): ?string
    {
        return $this->templateName;
    }

    /**
     * Attach a source map for line translation.
     */
    public function setSourceMap(SourceMap $sourceMap): self
    {
        $this->sourceMap = $sourceMap;
        $this->resolveSourceLocation();
        return $this;
    }

    /**
     * Get the source map.
     */
    public function getSourceMap(): ?SourceMap
    {
        return $this->sourceMap;
    }

    /**
     * Get the resolved source file path (from source map translation).
     */
    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }

    /**
     * Get the resolved source line number (from source map translation).
     */
    public function getSourceLine(): ?int
    {
        return $this->sourceLine;
    }

    /**
     * Render a human-readable error report.
     */
    public function render(): string
    {
        $parts = [];

        if ($this->templateName !== null) {
            $parts[] = "View: {$this->templateName}";
        }

        if ($this->sourceFile !== null && $this->sourceLine !== null) {
            $parts[] = "Source: {$this->sourceFile} on line {$this->sourceLine}";
        }

        $parts[] = "Error: {$this->getMessage()}";

        return implode("\n", $parts);
    }

    /**
     * Resolve the original source location using the source map.
     */
    private function resolveSourceLocation(): void
    {
        if ($this->sourceMap === null) {
            return;
        }

        $mapping = $this->sourceMap->resolve($this->line);

        if ($mapping !== null) {
            $this->sourceFile = $mapping['sourcePath'];
            $this->sourceLine = $mapping['sourceLine'];

            // Override file/line so stack traces show the original source
            $this->file = $mapping['sourcePath'];
            $this->line = $mapping['sourceLine'];
        }
    }
}
