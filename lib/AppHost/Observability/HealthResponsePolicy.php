<?php

/**
 * OpenRegister AppHost — Health Response Policy
 *
 * How a host app's health endpoint answers: its status-code policy and CORS switch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Observability
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

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * How a host app's health endpoint answers: its status-code policy and CORS switch.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
 */
final readonly class HealthResponsePolicy {
	/**
	 * Constructor.
	 *
	 * @param string $statusCodePolicy adr006|always200.
	 * @param bool $cors Emit CORS headers when true.
	 */
	public function __construct(
		public string $statusCodePolicy = ObservabilityManifest::POLICY_ADR006,
		public bool $cors = false,
	) {
	}//end __construct()
}//end class
