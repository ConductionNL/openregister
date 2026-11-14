<?php

/**
 * RequestIdentityMap: the request-scoped map a mapper answers repeat lookups from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entities read in this request, by identifier and organisation scope.
 *
 * One map shape for every mapper that resolves an entity by id, uuid or slug
 * (schemas and registers), so the two do not each carry their own copy.
 *
 * The key is the lower-cased identifier plus the multi-tenancy flag, and
 * nothing else. The multi-tenancy flag stays in the key because the
 * organisation filter decides WHICH row a lookup returns: two organisations
 * may hold the same slug. The RBAC flag is left out: no mapper lookup that
 * uses this map reads it when building its query, so it never changes the
 * row loaded, and keying on it only doubled the misses.
 *
 * A found entity is remembered under the identifier asked for and under its
 * own id, uuid and slug, so a later lookup by any of them is answered here.
 *
 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
 */
final class RequestIdentityMap {
	/**
	 * Entities by key.
	 *
	 * @var array<string, Entity>
	 */
	private array $entries = [];

	/**
	 * The key of an identifier under an organisation scope, such as `slug:1`.
	 *
	 * @param int|string $identifier   The id, uuid or slug.
	 * @param bool       $multitenancy Whether the organisation filter applies.
	 *
	 * @return string The key.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is part of the key.
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public static function key(string|int $identifier, bool $multitenancy): string {
		return strtolower((string)$identifier) . ':' . (int)$multitenancy;
	}//end key()

	/**
	 * The entity remembered for an identifier, or null.
	 *
	 * @param int|string $identifier   The id, uuid or slug.
	 * @param bool       $multitenancy Whether the organisation filter applies.
	 *
	 * @return Entity|null The entity.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is part of the key.
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public function get(string|int $identifier, bool $multitenancy): ?Entity {
		return ($this->entries[self::key(identifier: $identifier, multitenancy: $multitenancy)] ?? null);
	}//end get()

	/**
	 * Remember an entity under the identifier asked for and its own aliases.
	 *
	 * @param Entity                 $entity       The entity.
	 * @param bool                   $multitenancy Whether the organisation filter applied.
	 * @param array<int, mixed>      $identifiers  The identifier asked for, then the entity's id, uuid and slug; nulls are skipped.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is part of the key.
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public function remember(Entity $entity, bool $multitenancy, array $identifiers): void {
		foreach ($identifiers as $identifier) {
			if (is_string($identifier) === false && is_int($identifier) === false) {
				continue;
			}

			$this->entries[self::key(identifier: $identifier, multitenancy: $multitenancy)] = $entity;
		}
	}//end remember()

	/**
	 * Drop every entry that holds the entity with this id.
	 *
	 * @param int $entityId The entity id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public function forget(int $entityId): void {
		foreach ($this->entries as $key => $entity) {
			if ($entity->getId() === $entityId) {
				unset($this->entries[$key]);
			}
		}
	}//end forget()

	/**
	 * Drop every entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public function clear(): void {
		$this->entries = [];
	}//end clear()

	/**
	 * The keys held, in insertion order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
	 */
	public function keys(): array {
		return array_keys($this->entries);
	}//end keys()
}//end class
