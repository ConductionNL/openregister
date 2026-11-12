<?php

/**
 * OpenRegister AppHost — Local Health Checks
 *
 * The health-check primitives that probe this Nextcloud instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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
 * Routes the `database`, `filesystem`, `appEnabled` and `appConfig` checks.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
 */
class LocalHealthChecks {
	/**
	 * Constructor.
	 *
	 * @param StorageHealthChecks  $storage  Database and filesystem checks.
	 * @param AppStateHealthChecks $appState App-enabled and app-config checks.
	 */
	public function __construct(
		private readonly StorageHealthChecks $storage,
		private readonly AppStateHealthChecks $appState,
	) {
	}//end __construct()

	/**
	 * Run a local check.
	 *
	 * @param string                $appId      Calling app id.
	 * @param HealthCheckDescriptor $descriptor The check.
	 *
	 * @return array{0: bool, 1: string}|null [ok, generic failure message], or null for a type that is not local.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
	 */
	public function run(string $appId, HealthCheckDescriptor $descriptor): ?array {
		return match ($descriptor->type) {
			'database' => $this->storage->database(),
			'filesystem' => $this->storage->filesystem(),
			'appEnabled' => $this->appState->appEnabled(app: (string)$descriptor->app),
			'appConfig' => $this->appState->appConfig(appId: $appId, descriptor: $descriptor),
			default => null,
		};
	}//end run()
}//end class
