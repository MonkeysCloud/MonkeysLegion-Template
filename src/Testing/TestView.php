<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Testing;

use PHPUnit\Framework\Assert;

/**
 * Wraps rendered view output with fluent assertion methods.
 *
 * Usage:
 *   $view = TestView::fromRendered('home', '<h1>Hello</h1>', ['title' => 'Home']);
 *   $view->assertSee('Hello')->assertDontSee('Goodbye');
 */
final class TestView
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $name,
        private readonly string $output,
        private readonly array $data = [],
    ) {}

    /**
     * Create a TestView from rendered output.
     *
     * @param array<string, mixed> $data
     */
    public static function fromRendered(string $name, string $output, array $data = []): self
    {
        return new self($name, $output, $data);
    }

    /**
     * Assert the rendered output contains the given text.
     */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString(
            $text,
            $this->output,
            "Failed asserting that view [{$this->name}] contains '{$text}'.",
        );
        return $this;
    }

    /**
     * Assert the rendered output does NOT contain the given text.
     */
    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString(
            $text,
            $this->output,
            "Failed asserting that view [{$this->name}] does not contain '{$text}'.",
        );
        return $this;
    }

    /**
     * Assert the given texts appear in the output in the specified order.
     *
     * @param list<string> $texts
     */
    public function assertSeeInOrder(array $texts): self
    {
        $lastPos = -1;
        foreach ($texts as $text) {
            $pos = strpos($this->output, $text, max(0, $lastPos));
            Assert::assertNotFalse(
                $pos,
                "Failed asserting that view [{$this->name}] contains '{$text}' after position {$lastPos}.",
            );
            $lastPos = $pos + strlen($text);
        }
        return $this;
    }

    /**
     * Assert the output contains the given raw HTML string.
     */
    public function assertSeeHtml(string $html): self
    {
        Assert::assertStringContainsString(
            $html,
            $this->output,
            "Failed asserting that view [{$this->name}] contains HTML '{$html}'.",
        );
        return $this;
    }

    /**
     * Assert the output does NOT contain the given raw HTML string.
     */
    public function assertDontSeeHtml(string $html): self
    {
        Assert::assertStringNotContainsString(
            $html,
            $this->output,
            "Failed asserting that view [{$this->name}] does not contain HTML '{$html}'.",
        );
        return $this;
    }

    /**
     * Assert a data key exists (and optionally matches a value).
     */
    public function assertHasData(string $key, mixed $value = null): self
    {
        Assert::assertArrayHasKey(
            $key,
            $this->data,
            "Failed asserting that view [{$this->name}] has data key '{$key}'.",
        );
        if ($value !== null) {
            Assert::assertSame(
                $value,
                $this->data[$key],
                "Failed asserting that view [{$this->name}] data '{$key}' matches expected value.",
            );
        }
        return $this;
    }

    /**
     * Assert the view is the expected view name.
     */
    public function assertViewIs(string $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->name,
            "Failed asserting that view is '{$expected}', got '{$this->name}'.",
        );
        return $this;
    }

    /**
     * Get the rendered output.
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * Get the view name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the view data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
