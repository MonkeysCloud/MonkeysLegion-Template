<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

/**
 * Maps compiled PHP line numbers back to original template source locations.
 *
 * Generated during compilation and stored alongside the compiled PHP file.
 * Used by ViewException to produce accurate error reports that reference
 * the original template file and line, not the compiled cache file.
 */
final class SourceMap
{
    /**
     * @param array<int, array{sourcePath: string, sourceLine: int, sourceColumn: int}> $mappings
     *        Key: compiled PHP line number
     *        Value: original source location
     */
    public function __construct(
        private array $mappings = [],
    ) {}

    /**
     * Add a mapping from a compiled line to a source location.
     *
     * @param int    $compiledLine  Line number in the compiled PHP file
     * @param string $sourcePath    Path to the original template file
     * @param int    $sourceLine    Line number in the original template
     * @param int    $sourceColumn  Column number in the original template
     */
    public function addMapping(
        int $compiledLine,
        string $sourcePath,
        int $sourceLine,
        int $sourceColumn = 0,
    ): void {
        $this->mappings[$compiledLine] = [
            'sourcePath'   => $sourcePath,
            'sourceLine'   => $sourceLine,
            'sourceColumn' => $sourceColumn,
        ];
    }

    /**
     * Resolve a compiled PHP line number to the original source location.
     *
     * If no exact mapping exists, finds the nearest preceding mapping.
     *
     * @param int $compiledLine Line number in the compiled PHP file
     * @return array{sourcePath: string, sourceLine: int, sourceColumn: int}|null
     */
    public function resolve(int $compiledLine): ?array
    {
        // Exact match
        if (isset($this->mappings[$compiledLine])) {
            return $this->mappings[$compiledLine];
        }

        // Find the nearest preceding mapping
        $nearestLine = null;
        foreach (array_keys($this->mappings) as $line) {
            if ($line <= $compiledLine) {
                if ($nearestLine === null || $line > $nearestLine) {
                    $nearestLine = $line;
                }
            }
        }

        if ($nearestLine !== null) {
            $mapping = $this->mappings[$nearestLine];
            // Adjust the source line by the offset from the nearest mapping
            $offset = $compiledLine - $nearestLine;
            return [
                'sourcePath'   => $mapping['sourcePath'],
                'sourceLine'   => $mapping['sourceLine'] + $offset,
                'sourceColumn' => $mapping['sourceColumn'],
            ];
        }

        return null;
    }

    /**
     * Get all mappings.
     *
     * @return array<int, array{sourcePath: string, sourceLine: int, sourceColumn: int}>
     */
    public function getMappings(): array
    {
        return $this->mappings;
    }

    /**
     * Serialize the source map for storage alongside the compiled file.
     */
    public function serialize(): string
    {
        return serialize($this->mappings);
    }

    /**
     * Deserialize a source map from stored data.
     */
    public static function deserialize(string $data): self
    {
        /** @var array<int, array{sourcePath: string, sourceLine: int, sourceColumn: int}> $mappings */
        $mappings = unserialize($data, ['allowed_classes' => false]);
        return new self($mappings);
    }

    /**
     * Build a source map by scanning a compiled PHP file for #line markers.
     *
     * Compiled templates contain markers like:
     *   // #line 42 "/path/to/template.ml.php"
     *
     * @param string $compiledSource The compiled PHP source to scan
     */
    public static function fromCompiledSource(string $compiledSource): self
    {
        $map   = new self();
        $lines = explode("\n", $compiledSource);

        foreach ($lines as $index => $line) {
            $compiledLineNum = $index + 1;

            // Match: // #line <num> "<path>"
            if (preg_match('/^\/\/\s*#line\s+(\d+)\s+"([^"]+)"/', trim($line), $m)) {
                $map->addMapping(
                    $compiledLineNum,
                    $m[2],
                    (int) $m[1],
                );
            }
        }

        return $map;
    }

    /**
     * Check if the source map has any mappings.
     */
    public function isEmpty(): bool
    {
        return empty($this->mappings);
    }

    /**
     * Get the number of mappings.
     */
    public function count(): int
    {
        return count($this->mappings);
    }
}
