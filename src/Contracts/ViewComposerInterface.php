<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Contracts;

/**
 * Contract for view composers that inject shared data into templates.
 */
interface ViewComposerInterface
{
    /**
     * Compose data for the view.
     *
     * @param \MonkeysLegion\Template\ViewData $view
     */
    public function compose(\MonkeysLegion\Template\ViewData $view): void;
}
