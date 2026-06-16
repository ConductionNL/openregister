<?php

/**
 * OpenRegister AppHost — Health Check Provider Interface
 *
 * Escape hatch for health checks that cannot be expressed by the closed
 * declarative descriptor set. An app registers an implementation under the
 * container alias `OCA\OpenRegister\AppHost\IHealthCheckProvider::{appId}`
 * (the ADR-035 provider-alias discovery pattern); the engine merges the
 * returned checks into the generic health response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Interface
 * @package  OCA\OpenRegister\AppHost
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

namespace OCA\OpenRegister\AppHost;

/**
 * Imperative health-check provider for the AppHost observability engine.
 */
interface IHealthCheckProvider
{
    /**
     * Run the provider's checks.
     *
     * @return array<string, array{ok: bool, severity?: string, message?: string}>
     *   Map of check id => result. `ok` drives status; optional `severity`
     *   (critical|degraded, default critical) drives the HTTP code; optional
     *   `message` is shown only on failure and MUST NOT leak internals.
     */
    public function check(): array;
}//end interface
