<?php
/**
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Service;

use OCP\EventDispatcher\IEventDispatcher;

class DelegationService
{

    public function __construct(private readonly IEventDispatcher $dispatcher)
    {
    }

    /**
     * ADR-041 recipe: a typed event, not a registry RPC.
     *
     * @return void
     */
    public function delegate(): void
    {
        $this->dispatcher->dispatchTyped(new ReportRequestedEvent());
    }

}
