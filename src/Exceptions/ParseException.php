<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Exceptions;

use Throwable;

/**
 * Exception thrown when the template cannot be parsed correctly.
 */
class ParseException extends \RuntimeException
{
    public function __construct(
        string $message,
        string $filename = 'template.ml.php',
        int $line = 1,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        
        $this->file = $filename;
        $this->line = $line;
    }
}
