<?php

/**
 * OpenRegister Bulk Job Environment
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
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

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IAppConfig;
use OCP\IUserManager;

/**
 * Who a bulk job runs as and within which instance limits: the actor lookup, the object service and the settings.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md#requirement-a-bulk-action-is-one-job-with-an-actor-a-selection-and-a-reason-req-baj-001
 */
final readonly class BulkJobEnvironment {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService For running as the job's actor.
	 * @param IUserManager $userManager Actor lookup.
	 * @param IAppConfig $appConfig Instance settings.
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public IUserManager $userManager,
		public IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
