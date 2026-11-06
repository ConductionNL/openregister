<?php

/**
 * OpenRegister ExtendTargetReads
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Closure;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The objects an extend may show, out of a lookup that applied no access rule.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ExtendTargetReads {

	/**
	 * Whether the render answers to the caller's read right.
	 *
	 * @var boolean
	 */
	private bool $checked = true;

	/**
	 * Whether the render keeps to the caller's organisations.
	 *
	 * @var boolean
	 */
	private bool $multitenancy = true;

	/**
	 * Objects the caller may not read, by identifier.
	 *
	 * Kept apart from the render's cache: only a link that declares an exposure may show them.
	 *
	 * @var array<int|string, ObjectEntity>
	 */
	private array $refused = [];

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface       $mapper  Answers the read question per table.
	 * @param Closure(ObjectEntity):bool $mayRead The object rule, for a render without the organisation filter.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		private readonly MagicMapperInterface $mapper,
		private readonly Closure $mayRead,
	) {
	}//end __construct()

	/**
	 * Set the read rule of a render; a different rule forgets what the last one refused.
	 *
	 * @param bool $rbac         False for a trusted read.
	 * @param bool $multitenancy False for a read across organisations.
	 *
	 * @return bool True when the rule changed.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function applyRule(bool $rbac, bool $multitenancy): bool {
		$changed = $rbac !== $this->checked || $multitenancy !== $this->multitenancy;
		if ($changed === true) {
			$this->refused = [];
		}

		$this->checked = $rbac;
		$this->multitenancy = $multitenancy;

		return $changed;
	}//end applyRule()

	/**
	 * Whether the render answers to the caller's read right.
	 *
	 * @return bool False for a trusted read.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function checked(): bool {
		return $this->checked;
	}//end checked()

	/**
	 * Remember an object the caller may not read.
	 *
	 * @param int|string   $id     The identifier it was asked for by.
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function refuse(int|string $id, ObjectEntity $object): void {
		$this->refused[$id] = $object;
	}//end refuse()

	/**
	 * The refused object behind an identifier, if it was refused.
	 *
	 * @param int|string $id The identifier.
	 *
	 * @return ObjectEntity|null The object, or null when it was not refused.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function refused(int|string $id): ?ObjectEntity {
		return ($this->refused[$id] ?? null);
	}//end refused()

	/**
	 * The objects the caller may read.
	 *
	 * With the organisation filter on, the answer is the one a single object read gives.
	 *
	 * @param ObjectEntity[] $objects The objects found.
	 *
	 * @return ObjectEntity[] The readable objects.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function readable(array $objects): array {
		if ($this->multitenancy === false) {
			return array_values(array_filter($objects, $this->mayRead));
		}

		$readable = [];
		foreach (self::byTable(objects: $objects) as $tableObjects) {
			array_push($readable, ...$this->readableInTable(objects: $tableObjects));
		}

		return $readable;
	}//end readable()

	/**
	 * The objects of one table that the object read path admits.
	 *
	 * @param ObjectEntity[] $objects Objects of one register and schema.
	 *
	 * @return ObjectEntity[] The readable objects.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function readableInTable(array $objects): array {
		$first = $objects[0];
		$uuids = $this->mapper->filterReadableUuids(
			registerId: (int)$first->getRegister(),
			schemaId: (int)$first->getSchema(),
			uuids: array_map(fn (ObjectEntity $object): string => (string)$object->getUuid(), $objects)
		);

		return array_values(
			array_filter($objects, fn (ObjectEntity $object): bool => in_array($object->getUuid(), $uuids, true))
		);
	}//end readableInTable()

	/**
	 * The objects grouped by the table they live in.
	 *
	 * @param ObjectEntity[] $objects The objects.
	 *
	 * @return array<string, ObjectEntity[]> The objects by register and schema.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private static function byTable(array $objects): array {
		$tables = [];
		foreach ($objects as $object) {
			$tables[$object->getRegister() . ':' . $object->getSchema()][] = $object;
		}

		return $tables;
	}//end byTable()
}//end class
