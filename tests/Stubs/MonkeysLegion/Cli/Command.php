<?php

namespace MonkeysLegion\Cli;

abstract class Command
{
    /** @var array<string, mixed> */
    protected array $arguments = [];

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var list<string> */
    protected array $output = [];

    public function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    public function option(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    public function info(string $line): void
    {
        $this->output[] = '[INFO] ' . $line;
    }

    public function error(string $line): void
    {
        $this->output[] = '[ERROR] ' . $line;
    }

    public function success(string $line): void
    {
        $this->output[] = '[SUCCESS] ' . $line;
    }

    // verification helper
    public function setArgument(string $key, mixed $value): void
    {
        $this->arguments[$key] = $value;
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }

    /**
     * @return list<string>
     */
    public function getOutput(): array
    {
        return $this->output;
    }
}
