<?php

declare(strict_types=1);

namespace MonkeysLegion\Template;

use MonkeysLegion\DI\Contracts\ServiceProviderInterface;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Template\Contracts\ParserInterface;
use MonkeysLegion\Template\Contracts\CompilerInterface;
use MonkeysLegion\Template\Contracts\LoaderInterface;

/**
 * Registers Template package interface bindings.
 */
final class TemplateServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->bind(ParserInterface::class, Parser::class);
        $builder->bind(CompilerInterface::class, Compiler::class);
        $builder->bind(LoaderInterface::class, Loader::class);
    }
}
