<?php

/**
 * OpenRegister archival register mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register, schema and object mappers the archival services read the selection list and the retained records through.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class ArchivalRegisterMappers {
	/**
	 * Register lookups.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookups.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Object reads and writes.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $objectMapper;

	/**
	 * Read the three mappers off the schema catalog.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and magic mappers.
	 */
	public function __construct(SchemaCatalogInterface $catalog) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
		$this->objectMapper = $catalog->tables();
	}//end __construct()
}//end class
