<?php

/**
 * What a manual entity write is checked and recorded with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

/**
 * The audit trail mapper, root folder and database connection the manual entity service checks file access and records writes with.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class ManualEntityRecording {
	/**
	 * Bundle the collaborators.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit-trail persistence.
	 * @param IRootFolder               $rootFolder       Nextcloud root folder (for file-write access check).
	 * @param IDBConnection             $db               Connection for explicit transaction control.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly IRootFolder $rootFolder,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
