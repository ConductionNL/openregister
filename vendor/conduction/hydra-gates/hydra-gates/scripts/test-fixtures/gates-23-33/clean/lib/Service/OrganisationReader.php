<?php
/**
 * Consumes the OpenRegister Organisation abstraction rather than growing an
 * app-local tenant boundary.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction
 */

declare(strict_types=1);

namespace OCA\GatePlant\Service;

class OrganisationReader
{

    public function currentTenant(): ?string
    {
        return null;
    }

}
