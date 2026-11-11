<?php

/**
 * The background jobs the operations console lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCP\BackgroundJob\IJobList;

/**
 * The bulk job records and Nextcloud's registered background jobs the operations console reads.
 *
 * @spec openspec/specs/operations-console/spec.md
 */
final class OperationsConsoleJobs {
	/**
	 * Bundle the collaborators.
	 *
	 * @param BulkJobMapperInterface $bulkJobs The bulk job records.
	 * @param IJobList      $jobList  Nextcloud's registered background jobs.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function __construct(
		public readonly BulkJobMapperInterface $bulkJobs,
		public readonly IJobList $jobList,
	) {
	}//end __construct()
}//end class
