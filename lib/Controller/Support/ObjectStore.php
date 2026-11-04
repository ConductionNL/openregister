<?php

/**
 * OpenRegister object store services
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The object service with the register, schema and audit trail mappers it is read through.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectStore {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectService    $objectService    The object service.
	 * @param RegisterMapper   $registerMapper   The register mapper.
	 * @param SchemaMapper     $schemaMapper     The schema mapper.
	 * @param AuditTrailMapper $auditTrailMapper The audit trail mapper.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectService $objectService,
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly AuditTrailMapper $auditTrailMapper,
	) {
	}//end __construct()
}//end class
