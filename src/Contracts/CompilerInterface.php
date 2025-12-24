<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Contracts;

interface CompilerInterface
{
    /**
     * Compile the source code into PHP.
     *
     * @param string $source
     * @param string $path
     * @return string
     */
    public function compile(string $source, string $path): string;
}
