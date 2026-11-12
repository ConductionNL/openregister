<?php

/**
 * The mappers the linked entity service reads and writes through.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, schema, register and organisation mappers of the linked entity service.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class LinkedEntityMappers {
	/**
	 * Magic mapper for object operations
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $magicMapper;

	/**
	 * Schema mapper
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register mapper
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaCatalogInterface      $catalog            Register, schema and magic-table mappers.
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly OrganisationMapperInterface $organisationMapper,
	) {
		$this->magicMapper    = $catalog->tables();
		$this->schemaMapper   = $catalog->schemas();
		$this->registerMapper = $catalog->registers();
	}//end __construct()
}//end class
