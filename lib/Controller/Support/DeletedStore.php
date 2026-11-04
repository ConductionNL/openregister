<?php

/**
 * OpenRegister deleted store
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;

/**
 * Services of the deleted API.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DeletedStore {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapper           $objectEntityMapper The object entity mapper
	 * @param RegisterMapper        $registerMapper     The register mapper
	 * @param AuditTrailMapper      $auditTrailMapper   Reads back a destruction record and records a restore
	 * @param DeletionServiceBundle $deletion           The destruction-pipeline collaborators (window, right, scope, recorder, clock)
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function __construct(
		public readonly MagicMapper $objectEntityMapper,
		public readonly RegisterMapper $registerMapper,
		public readonly AuditTrailMapper $auditTrailMapper,
		public readonly DeletionServiceBundle $deletion,
	) {
	}//end __construct()
}//end class
