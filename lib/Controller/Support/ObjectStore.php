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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The object service with the register, schema and audit trail mappers it is read through.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectStore {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface    $objectService    The object service.
	 * @param RegisterMapperInterface   $registerMapper   The register mapper.
	 * @param SchemaMapperInterface     $schemaMapper     The schema mapper.
	 * @param AuditTrailMapperInterface $auditTrailMapper The audit trail mapper.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
