<?php

namespace MonkeysLegion\Cli;

abstract class Command
{
    protected array $arguments = [];
    protected array $output = [];

    public function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
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

    public function getOutput(): array
    {
        return $this->output;
    }
}
