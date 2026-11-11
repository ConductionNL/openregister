<?php

/**
 * OpenRegister scheduled job runtime
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * What a scheduled tenant job runs on: the clock the job list schedules it by,
 * the app configuration it reads its settings from, and the log it reports to.
 *
 * Consumed by TenantDeprovisionJob, TenantPurgeJob and TenantUsageSyncJob (ADR-011 Rule 4).
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class ScheduledJobRuntime {
	/**
	 * Bundle the runtime services.
	 *
	 * @param ITimeFactory    $time   The clock the timed job is scheduled by.
	 * @param IAppConfig      $config The app configuration.
	 * @param LoggerInterface $logger The log the job reports to.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ITimeFactory $time,
		public readonly IAppConfig $config,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
