<?php

/**
 * The mappers the object tree walk reads through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, register and schema mappers of the tree walk.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
final class ObjectTreeMappers {
	/**
	 * Bundle the mappers.
	 *
	 * @param MagicMapperInterface    $objectMapper   Loads objects and filters them by read access.
	 * @param RegisterMapperInterface $registerMapper Resolves the root's register.
	 * @param SchemaMapperInterface   $schemaMapper   Resolves the root's schema.
	 *
	 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
