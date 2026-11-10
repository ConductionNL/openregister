<?php

/**
 * OpenRegister RelatedReadFilter
 *
 * Keeps the related objects the current user may read.
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

use Closure;
use OCA\OpenRegister\Db\MagicMapper\RelatedRowRbacInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Schema-level read authorisation over objects found by a lookup that applied no access rule.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelatedReadFilter {

	/**
	 * Constructor.
	 *
	 * @param RelatedRowRbacInterface $rbac    The RBAC handler.
	 * @param SchemaMapperInterface   $schemas The schema mapper.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly RelatedRowRbacInterface $rbac,
		private readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()

	/**
	 * The objects the current user may read, as a list; an administrator keeps every object.
	 *
	 * @param array<int, mixed> $objects The objects to filter.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function admitted(array $objects): array {
		if ($this->rbac->isAdmin() === true) {
			return $objects;
		}

		$schemaOf = $this->schemaMemo();
		return array_values(array_filter($objects, fn ($object): bool => $this->admitsRead(object: $object, schemaOf: $schemaOf)));
	}//end admitted()

	/**
	 * A lookup of schemas by id, without RBAC or multitenancy, that remembers each schema it loaded.
	 *
	 * A schema that cannot be loaded gives null and is asked for again next time.
	 *
	 * @return Closure(mixed): ?Schema
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function schemaMemo(): Closure {
		$cache = [];
		return function (mixed $schemaId) use (&$cache): ?Schema {
			if (isset($cache[$schemaId]) === false) {
				try {
					$cache[$schemaId] = $this->schemas->find((int)$schemaId, _multitenancy: false, _rbac: false);
				} catch (\Exception $e) {
					return null;
				}
			}

			return $cache[$schemaId];
		};
	}//end schemaMemo()

	/**
	 * Whether the current user may read the object; values that are not objects, and objects without a schema, pass.
	 *
	 * An object whose schema cannot be loaded is dropped.
	 *
	 * @param mixed                    $object   The candidate.
	 * @param Closure(mixed): ?Schema $schemaOf The schema lookup.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function admitsRead(mixed $object, Closure $schemaOf): bool {
		if (($object instanceof ObjectEntity) === false || $object->getSchema() === null) {
			return true;
		}

		$schema = $schemaOf($object->getSchema());
		if ($schema === null) {
			return false;
		}

		return $this->readableUnder(object: $object, schema: $schema);
	}//end admitsRead()

	/**
	 * Whether the current user may read the object under its schema.
	 *
	 * Another user's draft is kept from this caller, as on every other path. The
	 * candidates come from a lookup that applied no access rule, so a draft the
	 * per-table query left out can reach this list; hasRowPermission() has no draft rule.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema Its schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function readableUnder(ObjectEntity $object, Schema $schema): bool {
		$userId = $this->rbac->getCurrentUserId();
		if ((new DraftCallerRule())->keptFromCaller(object: $object, schema: $schema, userId: $userId, isAdmin: false) === true) {
			return false;
		}

		return $this->rbac->hasRowPermission(schema: $schema, action: 'read', row: $object) === true;
	}//end readableUnder()
}//end class
