<?php

/**
 * OpenRegister Linked Entity Lookup
 *
 * One reverse lookup: which objects and entities link to an external entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The linked entity type (which is also its column name) and the entity id looked for.
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
 */
final class LinkedEntityLookup {

	/**
	 * Constructor.
	 *
	 * @param string $type     The linked entity type, e.g. `mail`.
	 * @param string $entityId The entity id to search for.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-reverse-lookup-across-tables
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $entityId,
	) {
	}//end __construct()

	/**
	 * The magic-table column holding the linked ids, `_<type>`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-magic-tables
	 */
	public function magicColumn(): string {
		return '_' . $this->type;
	}//end magicColumn()

	/**
	 * Whether an entity (register, schema, organisation) lists the entity id under its `<type>` field.
	 *
	 * @param object $entity The entity.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	public function isLinkedFrom(object $entity): bool {
		$getter = 'get' . ucfirst($this->type);
		$ids = $entity->$getter() ?? [];
		return in_array($this->entityId, $ids, true);
	}//end isLinkedFrom()
}//end class
