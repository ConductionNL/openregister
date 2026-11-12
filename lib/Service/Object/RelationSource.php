<?php

/**
 * OpenRegister RelationSource
 *
 * The relation map and schema of the object whose outgoing relations are listed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The stored relation map of the source object and the schema that declares its properties.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationSource {

	/**
	 * Constructor.
	 *
	 * @param array<mixed, mixed> $relations The source object's stored relation map.
	 * @param Schema|null         $schema    The schema that declares its relation properties.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly array $relations,
		public readonly ?Schema $schema,
	) {
	}//end __construct()
}//end class
