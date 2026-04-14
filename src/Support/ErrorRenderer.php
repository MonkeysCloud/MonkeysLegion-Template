<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Support;

use MonkeysLegion\Template\Exceptions\TemplateSyntaxException;
use MonkeysLegion\Template\Exceptions\ViewException;
use Throwable;

/**
 * Rich HTML error renderer for template errors in development mode.
 *
 * Shows: source file, line, column, snippet, compiled PHP, stack trace.
 * Inspired by Laravel Ignition / Symfony error pages.
 */
final class ErrorRenderer
{
    private bool $devMode;

    public function __construct(bool $devMode = false)
    {
        $this->devMode = $devMode;
    }

    /**
     * Check if dev mode is enabled.
     */
    public function isDevMode(): bool
    {
        return $this->devMode;
    }

    /**
     * Set dev mode flag.
     */
    public function setDevMode(bool $devMode): void
    {
        $this->devMode = $devMode;
    }

    /**
     * Render a rich error page for a template exception.
     */
    public function render(Throwable $exception): string
    {
        if (!$this->devMode) {
            return $this->renderProductionError($exception);
        }

        return $this->renderDevError($exception);
    }

    /**
     * Render a minimal production error (no sensitive details).
     */
    public function renderProductionError(Throwable $exception): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               display: flex; justify-content: center; align-items: center;
               min-height: 100vh; margin: 0; background: #f8fafc; color: #334155; }
        .error-card { text-align: center; max-width: 480px; padding: 2rem; }
        h1 { font-size: 4rem; color: #e11d48; margin: 0; }
        p { color: #64748b; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="error-card">
        <h1>500</h1>
        <p>An error occurred while rendering the page. Please try again later.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render a rich development error page with full details.
     */
    public function renderDevError(Throwable $exception): string
    {
        $title = $this->getExceptionTitle($exception);
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();
        $snippet = $this->getSourceSnippet($exception);
        $stackTrace = $this->formatStackTrace($exception);
        $exceptionClass = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'JetBrains Mono', 'Fira Code', monospace;
               background: #0f172a; color: #e2e8f0; line-height: 1.6; }
        .header { background: linear-gradient(135deg, #dc2626, #be123c);
                   padding: 2rem; color: white; }
        .header h1 { font-size: 1.25rem; font-weight: 600; opacity: 0.9; }
        .header .message { font-size: 1.5rem; margin-top: 0.5rem; word-break: break-word; }
        .header .meta { font-size: 0.875rem; margin-top: 1rem; opacity: 0.8; }
        .section { padding: 1.5rem 2rem; border-bottom: 1px solid #1e293b; }
        .section-title { font-size: 0.75rem; text-transform: uppercase;
                          letter-spacing: 0.1em; color: #94a3b8; margin-bottom: 1rem; }
        .snippet { background: #1e293b; border-radius: 8px; overflow-x: auto; padding: 1rem; }
        .snippet .line { display: flex; font-size: 0.8125rem; }
        .snippet .line-num { width: 3rem; color: #64748b; text-align: right;
                              padding-right: 1rem; flex-shrink: 0; user-select: none; }
        .snippet .line-code { white-space: pre; }
        .snippet .line.error { background: rgba(220, 38, 38, 0.2); border-left: 3px solid #dc2626; }
        .stack { font-size: 0.8125rem; }
        .stack .frame { padding: 0.5rem 0; border-bottom: 1px solid #1e293b; }
        .stack .frame-file { color: #60a5fa; }
        .stack .frame-line { color: #fbbf24; }
        .stack .frame-func { color: #a78bfa; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{$exceptionClass}</h1>
        <div class="message">{$message}</div>
        <div class="meta">{$file} : line {$line}</div>
    </div>
    <div class="section">
        <div class="section-title">Source</div>
        <div class="snippet">{$snippet}</div>
    </div>
    <div class="section">
        <div class="section-title">Stack Trace</div>
        <div class="stack">{$stackTrace}</div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get the error page title based on exception type.
     */
    private function getExceptionTitle(Throwable $exception): string
    {
        if ($exception instanceof TemplateSyntaxException) {
            return 'Template Syntax Error';
        }
        if ($exception instanceof ViewException) {
            return 'View Error';
        }
        return 'Template Error';
    }

    /**
     * Extract a source code snippet around the error line.
     */
    public function getSourceSnippet(Throwable $exception, int $contextLines = 5): string
    {
        $file = $exception->getFile();
        $errorLine = $exception->getLine();

        if (!is_file($file)) {
            return '<div class="line"><span class="line-code">Source file not available</span></div>';
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return '<div class="line"><span class="line-code">Unable to read source</span></div>';
        }

        $lines = explode("\n", $source);
        $start = max(0, $errorLine - $contextLines - 1);
        $end = min(count($lines), $errorLine + $contextLines);

        $html = '';
        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $lineContent = htmlspecialchars($lines[$i] ?? '', ENT_QUOTES, 'UTF-8');
            $isError = $lineNum === $errorLine ? ' error' : '';

            $html .= "<div class=\"line{$isError}\">"
                . "<span class=\"line-num\">{$lineNum}</span>"
                . "<span class=\"line-code\">{$lineContent}</span>"
                . "</div>";
        }

        return $html;
    }

    /**
     * Format the stack trace as HTML.
     */
    public function formatStackTrace(Throwable $exception): string
    {
        $html = '';
        foreach ($exception->getTrace() as $i => $frame) {
            $file = htmlspecialchars($frame['file'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'];
            $func = htmlspecialchars($class . $type . $function, ENT_QUOTES, 'UTF-8');

            $html .= "<div class=\"frame\">"
                . "<span class=\"frame-file\">{$file}</span>"
                . " : <span class=\"frame-line\">{$line}</span>"
                . " &mdash; <span class=\"frame-func\">{$func}</span>"
                . "</div>";
        }

        return $html;
    }
}
