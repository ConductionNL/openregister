<?php

/**
 * The object, register, schema and audit trail mappers a schema-wide delete goes through.
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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, register, schema and audit trail mappers a schema-wide delete goes through.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class SchemaDeletionStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface      $magicMapper      Object mapper.
	 * @param RegisterMapperInterface   $registerMapper   Register mapper.
	 * @param SchemaMapperInterface     $schemaMapper     Schema mapper.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $magicMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
