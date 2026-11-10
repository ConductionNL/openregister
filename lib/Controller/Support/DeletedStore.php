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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
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
	 * @param MagicMapperInterface  $objectEntityMapper The object entity mapper
	 * @param RegisterMapperInterface        $registerMapper     The register mapper
	 * @param AuditTrailMapperInterface      $auditTrailMapper   Reads back a destruction record and records a restore
	 * @param DeletionServiceBundle $deletion           The destruction-pipeline collaborators (window, right, clock; scope and recorder)
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectEntityMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly DeletionServiceBundle $deletion,
	) {
	}//end __construct()
}//end class
