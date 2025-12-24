<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Contracts;

interface ParserInterface
{
    /**
     * Parse the template source.
     *
     * @param string $source
     * @return string
     */
    public function parse(string $source): string;

    /**
     * Extract component parameters/props from source.
     *
     * @param string $source
     * @return array<string, mixed>
     */
    public function extractComponentParams(string $source): array;

    /**
     * Remove @props/@param directives from the source.
     *
     * @param string $source
     * @return string
     */
    public function removePropsDirectives(string $source): string;
}
