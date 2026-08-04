<?php

/**
 * OpenRegister AppHost — FoundationUnavailableException
 *
 * Thrown when an AppHost consumable needs OpenRegister's foundation services
 * (ConfigurationService, RegisterResolverService, ObjectService, …) and they
 * cannot be resolved — OpenRegister is disabled, absent, or its container
 * bindings are not available to the consuming (leaf) app.
 *
 * This is the ADR-049 fail-closed replacement for the fleet's nullable
 * `getObjectService(): ?ObjectService` / catch-Throwable→null resolver
 * anti-pattern: foundation-missing is an explicit, typed, logged condition —
 * never a silent null that a caller can mistake for "check skipped".
 * Controllers translate it to an explicit 503 with a machine-readable reason
 * via {@see \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when OpenRegister foundation services are unavailable to a consumer.
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
 *   — Requirement: Generic settings surface (Scenario: Foundation missing is explicit)
 */
class FoundationUnavailableException extends RuntimeException
{
    /**
     * Construct the foundation-unavailable exception with diagnostic context.
     *
     * @param string         $appId    The consuming (leaf) app id.
     * @param string         $detail   Short operator-actionable detail (which service, why).
     * @param Throwable|null $previous Previous exception in the chain.
     */
    public function __construct(
        private readonly string $appId,
        string $detail='OpenRegister is not installed or enabled.',
        ?Throwable $previous=null
    ) {
        parent::__construct(
            message: sprintf('[AppHost:%s] OpenRegister foundation unavailable: %s', $appId, $detail),
            code: 503,
            previous: $previous
        );
    }//end __construct()

    /**
     * Get the consuming app id the failure was raised for.
     *
     * @return string The leaf app id.
     */
    public function getAppId(): string
    {
        return $this->appId;
    }//end getAppId()
}//end class
