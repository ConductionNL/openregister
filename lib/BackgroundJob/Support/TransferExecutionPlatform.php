<?php

/**
 * OpenRegister transfer execution platform
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

use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The platform services a transfer attempt reschedules, configures and logs with.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferExecutionPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IJobList        $jobList   Schedules the next attempt.
	 * @param IAppConfig      $appConfig Transport and attempt-cap settings.
	 * @param LoggerInterface $logger    PSR logger.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		public readonly IJobList $jobList,
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
