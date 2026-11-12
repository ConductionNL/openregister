<?php

/**
 * OpenRegister AppHost — App State Health Checks
 *
 * The appEnabled and appConfig health-check primitives.
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

use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Runs the `appEnabled` and `appConfig` health checks.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
 */
class AppStateHealthChecks {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager (appEnabled check).
	 * @param IAppConfig  $appConfig  App config (appConfig check).
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * App-enabled check.
	 *
	 * @param string $app Target app id.
	 *
	 * @return array{0: bool, 1: string}
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
	 */
	public function appEnabled(string $app): array {
		$enabled = $this->appManager->isInstalled($app);
		if ($enabled === true) {
			return [true, ''];
		}

		return [false, 'app not enabled'];
	}//end appEnabled()

	/**
	 * App-config key assertion (present | nonEmpty) on the calling app's config.
	 *
	 * @param string                $appId      Calling app id.
	 * @param HealthCheckDescriptor $descriptor The check, carrying the key and the assertion.
	 *
	 * @return array{0: bool, 1: string}
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
	 */
	public function appConfig(string $appId, HealthCheckDescriptor $descriptor): array {
		$key = (string)$descriptor->key;
		$has = $this->appConfig->hasKey($appId, $key);
		if ($has === false) {
			return [false, 'config key missing'];
		}

		if ((string)$descriptor->assert === 'nonEmpty') {
			$value = $this->appConfig->getValueString($appId, $key, '');
			if ($value === '') {
				return [false, 'config key empty'];
			}
		}

		return [true, ''];
	}//end appConfig()
}//end class
