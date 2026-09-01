<?php

/**
 * An in-memory CaseItemMapper for the state-machine, cascade and service tests.
 *
 * Holds rows in an array, assigns ids, and implements the conditional
 * `updateIfState()` the way the database does: the row moves only if it is
 * still in the expected state. A `$failUpdateFor` hook lets a test simulate a
 * concurrent mover on one uuid.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * In-memory plan-item storage.
 */
class FakeCaseItemMapper extends CaseItemMapper {

	/**
	 * Rows by id.
	 *
	 * @var array<int, CaseItem>
	 */
	public array $rows = [];

	/**
	 * Uuids whose next conditional update must report "somebody else moved it".
	 *
	 * @var array<string, bool>
	 */
	public array $failUpdateFor = [];

	/**
	 * How many conditional updates ran.
	 *
	 * @var integer
	 */
	public int $updates = 0;

	/**
	 * Next id.
	 *
	 * @var integer
	 */
	private int $nextId = 1;

	/**
	 * Constructor over a mocked connection.
	 *
	 * @param TestCase $test The test, for the connection mock.
	 */
	public function __construct(TestCase $test) {
		parent::__construct(db: $test->getMockBuilder(IDBConnection::class)->getMock());

	}//end __construct()

	/**
	 * Seed rows (ids assigned when absent).
	 *
	 * @param array<int, CaseItem> $rows The rows.
	 *
	 * @return void
	 */
	public function seed(array $rows): void {
		foreach ($rows as $row) {
			$this->insert($row);
		}
	}//end seed()

	/**
	 * Insert.
	 *
	 * @param Entity $entity The row.
	 *
	 * @return CaseItem The row with an id.
	 */
	public function insert(Entity $entity): CaseItem {
		/*
		 * @var CaseItem $entity
		 */
		if ($entity->getId() === null) {
			$entity->setId($this->nextId);
		}

		$this->nextId = max($this->nextId, (int)$entity->getId()) + 1;
		if (trim((string)$entity->getUuid()) === '') {
			$entity->setUuid('item-' . (int)$entity->getId());
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new \DateTime());
		}

		$entity->resetUpdatedFields();
		$this->rows[(int)$entity->getId()] = $entity;

		return $entity;
	}//end insert()

	/**
	 * Update.
	 *
	 * @param Entity $entity The row.
	 *
	 * @return CaseItem The row.
	 */
	public function update(Entity $entity): CaseItem {
		/*
		 * @var CaseItem $entity
		 */
		$this->rows[(int)$entity->getId()] = $entity;

		return $entity;
	}//end update()

	/**
	 * Conditional update.
	 *
	 * @param CaseItem $item The row.
	 * @param string $expectedState The state it must still be in.
	 *
	 * @return boolean Whether it moved.
	 */
	public function updateIfState(CaseItem $item, string $expectedState): bool {
		$this->updates++;
		$uuid = (string)$item->getUuid();
		if (isset($this->failUpdateFor[$uuid]) === true) {
			unset($this->failUpdateFor[$uuid]);

			return false;
		}

		$stored = ($this->rows[(int)$item->getId()] ?? null);
		if ($stored === null) {
			return false;
		}

		// The stored row and the in-memory row are the same object in these
		// tests, so the "expected state" check is against what the caller read.
		if ($stored !== $item && $stored->getState() !== $expectedState) {
			return false;
		}

		$this->rows[(int)$item->getId()] = $item;

		return true;
	}//end updateIfState()

	/**
	 * By uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return CaseItem The row.
	 */
	public function findByUuid(string $uuid): CaseItem {
		foreach ($this->rows as $row) {
			if ($row->getUuid() === $uuid) {
				return $row;
			}
		}

		throw new DoesNotExistException('no such item ' . $uuid);
	}//end findByUuid()

	/**
	 * By object.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array<int, CaseItem> The rows.
	 */
	public function findByObject(string $objectUuid): array {
		$rows = array_values(array_filter($this->rows, static fn (CaseItem $row): bool => $row->getObjectUuid() === $objectUuid));
		usort($rows, static fn (CaseItem $a, CaseItem $b): int => [(int)$a->getPosition(), (int)$a->getId()] <=> [(int)$b->getPosition(), (int)$b->getId()]);

		return $rows;
	}//end findByObject()

	/**
	 * Open count by object.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return int The count.
	 */
	public function countOpenByObject(string $objectUuid): int {
		return count(array_filter($this->findByObject($objectUuid), static fn (CaseItem $row): bool => $row->isInTerminalState() === false));
	}//end countOpenByObject()

	/**
	 * By realisation.
	 *
	 * @param string $realisationUuid The task or run uuid.
	 *
	 * @return array<int, CaseItem> The rows.
	 */
	public function findByRealisation(string $realisationUuid): array {
		return array_values(array_filter($this->rows, static fn (CaseItem $row): bool => $row->getRealisationUuid() === $realisationUuid));
	}//end findByRealisation()

	/**
	 * By type and state.
	 *
	 * @param string|null $type The type.
	 * @param string|null $state The state.
	 * @param int $limit Page size.
	 * @param int $offset Offset.
	 *
	 * @return array<int, CaseItem> The page.
	 */
	public function findByTypeAndState(?string $type, ?string $state, int $limit = 25, int $offset = 0): array {
		return array_slice($this->matching($type, $state), $offset, $limit);
	}//end findByTypeAndState()

	/**
	 * Count by type and state.
	 *
	 * @param string|null $type The type.
	 * @param string|null $state The state.
	 *
	 * @return int The total.
	 */
	public function countByTypeAndState(?string $type, ?string $state): int {
		return count($this->matching($type, $state));
	}//end countByTypeAndState()

	/**
	 * Delete by object.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return int Deleted.
	 */
	public function deleteByObject(string $objectUuid): int {
		$before = count($this->rows);
		$this->rows = array_filter($this->rows, static fn (CaseItem $row): bool => $row->getObjectUuid() !== $objectUuid);

		return $before - count($this->rows);
	}//end deleteByObject()

	/**
	 * Rows matching the filters.
	 *
	 * @param string|null $type The type.
	 * @param string|null $state The state.
	 *
	 * @return array<int, CaseItem> The rows.
	 */
	private function matching(?string $type, ?string $state): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn (CaseItem $row): bool => ($type === null || $row->getPlanItemType() === $type) && ($state === null || $row->getState() === $state)
			)
		);
	}//end matching()
}//end class
