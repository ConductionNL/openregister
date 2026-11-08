<?php

/**
 * The object, schema, register and audit trail mappers retention reads and writes through.
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
 * The object, schema, register and audit trail mappers retention reads and writes through.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface      $objectMapper   Object mapper.
	 * @param SchemaMapperInterface     $schemaMapper   Schema mapper.
	 * @param RegisterMapperInterface   $registerMapper Register mapper.
	 * @param AuditTrailMapperInterface $auditMapper    Audit trail mapper.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly AuditTrailMapperInterface $auditMapper,
	) {
	}//end __construct()
}//end class
