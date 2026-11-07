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
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, schema, register and organisation mappers of the linked entity service.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class LinkedEntityMappers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface        $magicMapper        Magic mapper for object operations
	 * @param SchemaMapperInterface       $schemaMapper       Schema mapper
	 * @param RegisterMapperInterface     $registerMapper     Register mapper
	 * @param OrganisationMapperInterface $organisationMapper Organisation mapper
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $magicMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly OrganisationMapperInterface $organisationMapper,
	) {
	}//end __construct()
}//end class
