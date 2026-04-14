<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Exceptions;

use ErrorException;
use Throwable;

class ViewException extends ErrorException
{
    public function __construct(
        string $message,
        int $code = 0,
        int $severity = 1,
        string $filename = __FILE__,
        int $line = __LINE__,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $severity, $filename, $line, $previous);
    }

    /**
     * Report the exception slightly differently if needed.
     */
    public function render(): string
    {
        return $this->getMessage();
    }
}
