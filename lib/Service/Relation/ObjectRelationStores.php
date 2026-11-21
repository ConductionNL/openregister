<?php

/**
 * The stores a relation row is written to and read through.
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
 * The relation row mapper, the schema mapper and the relation vocabulary.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ObjectRelationStores {
	/**
	 * Bundle the stores.
	 *
	 * @param ObjectRelationMapperInterface $mapper        The relation row mapper.
	 * @param SchemaMapperInterface         $schemaMapper  Mapper for schemas.
	 * @param RelationTypeResolver          $relationTypes Resolves what a relation is called.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectRelationMapperInterface $mapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RelationTypeResolver $relationTypes,
	) {
	}//end __construct()
}//end class
