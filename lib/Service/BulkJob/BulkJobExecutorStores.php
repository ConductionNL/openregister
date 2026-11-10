<?php

/**
 * OpenRegister BulkJobExecutorStores
 *
 * The persistence collaborators of the bulk job executor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCA\OpenRegister\Db\BulkJobMemberMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The persistence collaborators of the bulk job executor.
 *
 * Groups collaborators of {@see BulkJobExecutor} so its constructor stays small;
 * the container autowires it.
 */
final class BulkJobExecutorStores {

	/**
	 * Bundle the collaborators.
	 *
	 * @param BulkJobMapperInterface       $jobMapper        Job persistence.
	 * @param BulkJobMemberMapperInterface $memberMapper     Member persistence.
	 * @param SchemaMapperInterface        $schemaMapper     Schema lookup for the access check.
	 * @param AuditTrailMapperInterface    $auditTrailMapper Audit entries per member.
	 */
	public function __construct(
		public readonly BulkJobMapperInterface $jobMapper,
		public readonly BulkJobMemberMapperInterface $memberMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
