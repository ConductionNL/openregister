<?php

/**
 * OpenRegister retention mappers
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
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * The mappers and repositories of the retention API.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionMappers {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapper      $objectMapper Object mapper
	 * @param SchemaMapper     $schemaMapper Schema mapper
	 * @param AuditTrailMapper $auditMapper  Audit trail mapper
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly MagicMapper $objectMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly AuditTrailMapper $auditMapper,
	) {
	}//end __construct()
}//end class
