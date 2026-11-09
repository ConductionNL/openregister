<?php

/**
 * OpenRegister listener deferral platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The platform services the listener deferral enqueues, configures and logs with.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ListenerDeferralPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IJobList        $jobList   Job list the chunk jobs are enqueued on.
	 * @param IAppConfig      $appConfig App config holding the kill switch.
	 * @param LoggerInterface $logger    PSR logger.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly IJobList $jobList,
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
