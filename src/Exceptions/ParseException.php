<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Exceptions;

use Throwable;

/**
 * Exception thrown when the template cannot be parsed correctly.
 */
class ParseException extends \ErrorException
{
    public function __construct(
        string $message,
        string $filename = 'template.ml.php',
        int $line = 1,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, 1, $filename, $line, $previous);
    }
}
