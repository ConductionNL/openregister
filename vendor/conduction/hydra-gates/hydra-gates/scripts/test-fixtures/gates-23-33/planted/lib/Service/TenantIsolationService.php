<?php
/**
 * App-local tenant boundary. ADR-022 says consume the OpenRegister
 * Organisation + TenantLifecycleService instead. gate-23 must name this.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Service;

class TenantIsolationService
{

    public function currentTenant(): ?string
    {
        return null;
    }

}
