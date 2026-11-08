<?php

/**
 * OpenRegister Relation Graph Sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\ObjectRelationMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Where the relation graph reads its edges and their names from.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final readonly class RelationGraphSources {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectRelationMapperInterface $relationMapper Mapper for stored relation rows.
	 * @param SchemaMapperInterface         $schemaMapper   Mapper for schemas.
	 * @param RelationTypeResolver          $relationTypes  Resolves what a relation is called.
	 */
	public function __construct(
		public ObjectRelationMapperInterface $relationMapper,
		public SchemaMapperInterface $schemaMapper,
		public RelationTypeResolver $relationTypes,
	) {
	}//end __construct()
}//end class
