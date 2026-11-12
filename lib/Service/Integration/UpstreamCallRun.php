<?php

/**
 * UpstreamCallRun — one call the router is making for a provider.
 *
 * The provider, the request and which kind of call it is travel together
 * through the private helpers of {@see ExternalIntegrationRouter}; this run
 * object holds them for one call and never leaves the router (ADR-011 Rule 3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * The provider making a call, the request it makes and the kind of call.
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
 */
final class UpstreamCallRun {

	/**
	 * Constructor.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 * @param UpstreamRequest     $request  The method, path and call options.
	 * @param string              $kind     `call` or `call (with meta)`, as a log line names it.
	 */
	public function __construct(
		public readonly IntegrationProvider $provider,
		public readonly UpstreamRequest $request,
		public readonly string $kind,
	) {
	}//end __construct()
}//end class
