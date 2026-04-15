<?php

namespace MonkeysLegion\DI\Contracts;

interface ServiceProviderInterface
{
    public function register(\MonkeysLegion\DI\ContainerBuilder $builder): void;
}
