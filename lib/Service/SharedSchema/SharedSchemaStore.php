<?php

/**
 * Where a shared schema split is persisted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
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

namespace OCA\OpenRegister\Service\SharedSchema;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;

/**
 * The database connection, register and schema mappers and table migrator the shared schema repair writes through.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SharedSchemaStore {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IDBConnection           $db             Database connection, for the split transaction.
	 * @param RegisterMapperInterface $registerMapper Register lookups and persistence.
	 * @param SchemaMapperInterface   $schemaMapper   Schema lookups and the clone fallback.
	 * @param SchemaTableMigrator     $migrator       Moves the object rows across the split.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly SchemaTableMigrator $migrator,
	) {
	}//end __construct()
}//end class
