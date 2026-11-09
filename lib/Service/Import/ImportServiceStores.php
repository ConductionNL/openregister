<?php

/**
 * The stores ImportService reads schemas from and writes and validates objects with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;

/**
 * The schema lookup, the object writer, the audit trail and the validator of an import.
 *
 * The admin check (the group manager) is not in here: it stays a direct
 * constructor parameter of ImportService.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportServiceStores {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface     $schemaMapper     Finds the schema rows are imported into.
	 * @param ObjectOperationsInterface $objectService    Saves and deletes the imported objects.
	 * @param AuditTrailMapperInterface $auditTrailMapper Tags and finds the rows of one import job.
	 * @param ValidateObjectInterface   $validator        Validates rows on a side-effect-free dry run.
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectOperationsInterface $objectService,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly ValidateObjectInterface $validator,
	) {
	}//end __construct()
}//end class
