<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Contracts;

interface LoaderInterface
{
    /**
     * Get the full filesystem path for a given template source.
     *
     * @param string $name
     * @return string
     */
    public function getSourcePath(string $name): string;
}
