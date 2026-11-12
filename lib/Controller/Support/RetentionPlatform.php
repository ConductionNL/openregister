<?php

/**
 * OpenRegister retention platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCP\BackgroundJob\IJobList;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the retention API.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IJobList        $jobList     Background job list
	 * @param IUserSession    $userSession User session
	 * @param LoggerInterface $logger      Logger
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly IJobList $jobList,
		public readonly IUserSession $userSession,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
