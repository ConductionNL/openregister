<?php

/**
 * OpenRegister deferred object event lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers that re-read a deferred object, scoped to its register and schema.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */
final class DeferredObjectEventLookup {
	/**
	 * Re-reads the object at run time.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $objectMapper;

	/**
	 * Resolves the register to scope the read.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Resolves the schema to scope the read.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and magic-table mappers.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function __construct(SchemaCatalogInterface $catalog) {
		$this->objectMapper = $catalog->tables();
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
	}//end __construct()
}//end class
