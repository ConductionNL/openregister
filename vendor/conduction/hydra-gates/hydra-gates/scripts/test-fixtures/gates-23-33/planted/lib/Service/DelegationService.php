<?php
/**
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Service;

class DelegationService
{

    /**
     * Phantom cross-app RPC — the registry has no getLeaf(). ADR-041 rule A.
     *
     * @return mixed
     */
    public function delegate(): mixed
    {
        return $this->registry->getLeaf('docudesk.reportGenerator');
    }

}
