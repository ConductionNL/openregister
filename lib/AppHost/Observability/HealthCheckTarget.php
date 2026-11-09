<?php

/**
 * OpenRegister AppHost — Health Check Target
 *
 * What one declared health check looks at: the app an appEnabled check
 * names, or the appConfig key and assertion an appConfig check names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Observability
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
 * The subject of one health check; every field is null for a check that needs none.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */
final class HealthCheckTarget {

	/**
	 * Constructor.
	 *
	 * @param string|null $app    Target app id (appEnabled only).
	 * @param string|null $key    AppConfig key (appConfig only).
	 * @param string|null $assert AppConfig assertion (appConfig only).
	 */
	public function __construct(
		public readonly ?string $app=null,
		public readonly ?string $key=null,
		public readonly ?string $assert=null,
	) {
	}//end __construct()
}//end class
