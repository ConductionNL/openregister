<?php
/**
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Listener;

class RegisterLeafListener
{

    public function handle(): void
    {
        $descriptor = new LeafDescriptor(
            id: 'gateplant-agent',
            renderMode: 'mount'
        );
    }

}
