<?php

/**
 * OpenRegister subject export queue
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
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

namespace OCA\OpenRegister\Service\Gdpr\Export;

use OCA\OpenRegister\Db\SubjectExportMapperInterface;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCP\BackgroundJob\IJobList;

/**
 * The export rows, the assembler that fills them and the job list that runs it.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class SubjectExportQueue {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SubjectExportMapperInterface $mapper   The export rows.
	 * @param DataSubjectRequestService    $subjects The RBAC scoped assembler.
	 * @param IJobList                     $jobList  Where the assembly is queued.
	 */
	public function __construct(
		public readonly SubjectExportMapperInterface $mapper,
		public readonly DataSubjectRequestService $subjects,
		public readonly IJobList $jobList,
	) {
	}//end __construct()
}//end class
