<?php

/**
 * OpenRegister AppHost — Health Check Target
 *
 * Immutable value object naming what one declarative health check inspects,
 * parsed from a host app's manifest `observability.health.checks[]` block.
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
 * What one declarative health check inspects: the target app, or an
 * appConfig key and the assertion made about it.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */
final readonly class HealthCheckTarget {
	/**
	 * Constructor.
	 *
	 * @param string|null $app Target app id (appEnabled only).
	 * @param string|null $key AppConfig key (appConfig only).
	 * @param string|null $assert AppConfig assertion (appConfig only).
	 */
	public function __construct(
		public ?string $app = null,
		public ?string $key = null,
		public ?string $assert = null,
	) {
	}//end __construct()
}//end class
