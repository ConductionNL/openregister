<?php

/**
 * OpenRegister relation handler stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;

/**
 * The stores RelationHandler reads related objects, their registers and
 * schemas, and the names of their relations through.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationHandlerStores {
	/**
	 * Bundle the stores.
	 *
	 * @param MagicMapperInterface      $objects       Mapper for object entities.
	 * @param SchemaMapperInterface     $schemas       Mapper for schemas.
	 * @param RegisterMapperInterface   $registers     Mapper for registers.
	 * @param RelationTypeResolver|null $relationTypes Resolves what a relation is called from each side.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objects,
		public readonly SchemaMapperInterface $schemas,
		public readonly RegisterMapperInterface $registers,
		public readonly ?RelationTypeResolver $relationTypes = null,
	) {
	}//end __construct()
}//end class
