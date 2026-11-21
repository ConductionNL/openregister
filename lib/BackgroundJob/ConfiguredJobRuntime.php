<?php

/**
 * OpenRegister configured background job runtime
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * What a background job that reads admin settings runs on: the scheduler clock,
 * the app settings that tune the job and the log the run writes to.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ConfiguredJobRuntime {
	/**
	 * Bundle the runtime.
	 *
	 * @param ITimeFactory    $time      Clock handed to the Nextcloud job base class.
	 * @param IAppConfig      $appConfig App settings that tune the job.
	 * @param LoggerInterface $logger    Log the job run writes to.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly ITimeFactory $time,
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
