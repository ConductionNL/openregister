<?php

/**
 * OpenRegister schema migration mappers
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

use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;

/**
 * The mappers and repositories of the schema migration API.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaMigrationMappers {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface          $schemaMapper    Schema lookup.
	 * @param SchemaChangelogMapperInterface $changelogMapper Changelog read.
	 * @param SchemaRunMapper       $runMapper       Run read.
	 * @param SchemaRunEntryMapper  $runEntryMapper  Run entry read.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly SchemaChangelogMapperInterface $changelogMapper,
		public readonly SchemaRunMapper $runMapper,
		public readonly SchemaRunEntryMapper $runEntryMapper,
	) {
	}//end __construct()
}//end class
