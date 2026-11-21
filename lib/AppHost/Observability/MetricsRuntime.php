<?php

/**
 * OpenRegister AppHost — Metrics Runtime
 *
 * What the metrics engine reads from the running instance: app versions, the cache and the Nextcloud version.
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

use OCP\ICacheFactory;
use OCP\IConfig;

/**
 * What the metrics engine reads from the running instance.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
 */
final readonly class MetricsRuntime {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ManifestLoader $manifestLoader App version resolution.
	 * @param ICacheFactory  $cacheFactory   Distributed cache (cacheTtl).
	 * @param IConfig        $config         NC version (implicit info).
	 */
	public function __construct(
		public ManifestLoader $manifestLoader,
		public ICacheFactory $cacheFactory,
		public IConfig $config,
	) {
	}//end __construct()
}//end class
