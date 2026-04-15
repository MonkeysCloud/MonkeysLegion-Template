<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown at parse/compile time when a template contains invalid syntax.
 *
 * This exception is thrown BEFORE rendering, allowing developers to catch
 * template errors early with precise line/column information and source context.
 */
final class TemplateSyntaxException extends RuntimeException
{
    /** Number of context lines to show above and below the error line */
    private const int CONTEXT_LINES = 3;

    /**
     * @param string         $errorMessage   Human-readable error description
     * @param string         $templatePath   Absolute path to the template file
     * @param int            $templateLine   1-based line number where the error occurred
     * @param int            $templateColumn 1-based column number where the error occurred
     * @param string         $source         Full template source code (for snippet extraction)
     * @param Throwable|null $previous       Previous exception if wrapping
     */
    public function __construct(
        private readonly string $errorMessage,
        public readonly string $templatePath,
        public readonly int $templateLine,
        public readonly int $templateColumn = 0,
        private readonly string $source = '',
        ?Throwable $previous = null,
    ) {
        $formatted = $this->buildFormattedMessage();

        parent::__construct($formatted, 0, $previous);

        // Override the file and line for stack trace accuracy
        $this->file = $templatePath;
        $this->line = $templateLine;
    }

    /**
     * Get the raw error message without source context.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Get the template name (basename of the path).
     */
    public function getTemplateName(): string
    {
        return basename($this->templatePath);
    }

    /**
     * Get the source snippet around the error line with a pointer.
     *
     * @return string Formatted snippet with line numbers and pointer
     */
    public function getSnippet(): string
    {
        if ($this->source === '') {
            return '';
        }

        $lines      = explode("\n", $this->source);
        $totalLines = count($lines);
        $startLine  = max(1, $this->templateLine - self::CONTEXT_LINES);
        $endLine    = min($totalLines, $this->templateLine + self::CONTEXT_LINES);
        $gutterWidth = strlen((string) $endLine);

        $snippet = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineContent = $lines[$i - 1] ?? '';
            $lineNum     = str_pad((string) $i, $gutterWidth, ' ', STR_PAD_LEFT);

            if ($i === $this->templateLine) {
                $snippet[] = "> {$lineNum} | {$lineContent}";

                // Add column pointer if we have column info
                if ($this->templateColumn > 0) {
                    $pointerPad = str_repeat(' ', $gutterWidth + 4 + $this->templateColumn - 1);
                    $snippet[]  = "{$pointerPad}^";
                }
            } else {
                $snippet[] = "  {$lineNum} | {$lineContent}";
            }
        }

        return implode("\n", $snippet);
    }

    /**
     * Build a complete formatted message with source context.
     */
    private function buildFormattedMessage(): string
    {
        $templateName = $this->getTemplateName();
        $location     = "line {$this->templateLine}";

        if ($this->templateColumn > 0) {
            $location .= ", column {$this->templateColumn}";
        }

        $message = "Template syntax error in \"{$templateName}\" on {$location}:\n";

        $snippet = $this->getSnippet();
        if ($snippet !== '') {
            $message .= "\n{$snippet}\n";
        }

        $message .= "\n{$this->errorMessage}";

        return $message;
    }
}
