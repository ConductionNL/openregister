<?php

/**
 * OpenRegister SequenceService
 *
 * Hands out stable, atomic, per-scope running numbers backing the declarative
 * `sequence` calculation operator. A leaf app declares
 * `{ "sequence": { "scope": "yearly", "pad": 4 } }` and OpenRegister reserves
 * the next number on object CREATE — so an identifier like `2026-0042` is
 * declarative, with no leaf-app code.
 *
 * Concurrency contract: reserveNext() runs inside a DB transaction and issues a
 * single atomic `UPDATE … SET next_value = next_value + 1`. The UPDATE's row
 * lock (held by both Postgres and MySQL/InnoDB until commit) serialises
 * concurrent reservations on the same scope, so each value is handed out
 * exactly once. The scope row is created lazily on first use; a lost insert
 * race is resolved by retrying the increment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Sequence;
use OCA\OpenRegister\Db\SequenceMapperInterface;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use Throwable;

/**
 * Reserves the next running number for a (register, schema, scope) triple.
 */
class SequenceService implements SequenceServiceInterface {
	/**
	 * Wire the sequence mapper and DB connection used for the reservation transaction.
	 *
	 * @param SequenceMapperInterface $mapper The sequence mapper.
	 * @param IDBConnection $db DB connection (for the reservation transaction).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SequenceMapperInterface $mapper,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Atomically reserve and return the next number for the given scope.
	 *
	 * @param SequenceScope $sequence The register, schema and scope key of the counter.
	 *
	 * @return int The reserved running number (>= 1).
	 *
	 * @throws DbException When the reservation cannot be completed.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function reserveNext(SequenceScope $sequence): int {
		$scope = $sequence->toArray();
		$this->db->beginTransaction();
		try {
			$reserved = $this->reserveInScope(scope: $scope);
			$this->db->commit();
			return $reserved;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}//end reserveNext()

	/**
	 * Reserve the next number inside the open transaction.
	 *
	 * @param array{registerId: int, schemaId: int, scopeKey: string} $scope The sequence scope.
	 *
	 * @return int The reserved running number.
	 *
	 * @throws DbException When the reservation cannot be completed.
	 */
	private function reserveInScope(array $scope): int {
		if ($this->mapper->incrementScope(new SequenceScope(...$scope)) === 0) {
			// Scope row does not exist yet — seed it reserving value 1 by
			// storing next_value = 2. A concurrent seeder may win the
			// unique index; on that violation, fall back to the increment
			// path so we still reserve a fresh value.
			return $this->seedScope(scope: $scope);
		}

		return $this->lastReserved(scope: $scope);
	}//end reserveInScope()

	/**
	 * The value just reserved: the next_value column points at the NEXT
	 * hand-out, so the reserved value is one below it.
	 *
	 * @param array{registerId: int, schemaId: int, scopeKey: string} $scope The sequence scope.
	 *
	 * @return int The reserved running number.
	 *
	 * @throws DbException When the row cannot be read.
	 */
	private function lastReserved(array $scope): int {
		$row = $this->mapper->findForScope(new SequenceScope(...$scope));
		$next = (int)($row?->getNextValue() ?? 1);
		return ($next - 1);
	}//end lastReserved()

	/**
	 * Seed a brand-new scope row, reserving value 1.
	 *
	 * On a unique-index race (another transaction inserted the row first) the
	 * insert throws; we then re-run the atomic increment so the caller still
	 * receives a fresh, unique value.
	 *
	 * @param array{registerId: int, schemaId: int, scopeKey: string} $scope     The sequence scope.
	 * @param int                                                     $nextValue The NEXT hand-out to store; the value reserved is one below it.
	 *
	 * @return int The reserved running number.
	 *
	 * @throws DbException When the fallback increment cannot read the row.
	 */
	private function seedScope(array $scope, int $nextValue = 2): int {
		$entity = $this->newScopeRow(scope: $scope, nextValue: $nextValue);

		try {
			$this->mapper->insert($entity);
			return ($nextValue - 1);
		} catch (DbException $e) {
			// Lost the insert race — the row now exists; increment it instead.
			$this->mapper->incrementScope(new SequenceScope(...$scope));
			return $this->lastReserved(scope: $scope);
		}
	}//end seedScope()

	/**
	 * A new counter row for a scope.
	 *
	 * @param array{registerId: int, schemaId: int, scopeKey: string} $scope     The counter scope.
	 * @param int                                                    $nextValue The next value to store.
	 *
	 * @return Sequence
	 */
	private function newScopeRow(array $scope, int $nextValue): Sequence {
		$entity = new Sequence();
		$entity->setRegisterId($scope['registerId']);
		$entity->setSchemaId($scope['schemaId']);
		$entity->setScopeKey($scope['scopeKey']);
		// Storing n+1 means value n has just been reserved.
		$entity->setNextValue($nextValue);

		return $entity;
	}//end newScopeRow()

	/**
	 * Raise the counter of one scope so it never issues a number already issued elsewhere.
	 *
	 * @param SequenceScope $sequence The register, schema and scope key of the counter.
	 * @param int           $reserved The number already issued elsewhere.
	 *
	 * @return void
	 *
	 * @throws DbException When the counter cannot be read or written.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function advanceTo(SequenceScope $sequence, int $reserved): void {
		if ($reserved < 1) {
			return;
		}

		$scope = $sequence->toArray();
		$target = ($reserved + 1);
		if ($this->mapper->raiseTo(new SequenceScope(...$scope), minNextValue: $target) > 0) {
			return;
		}

		// Either the counter is already past this number, or it does not exist
		// yet. Only the second needs anything doing, and the two are told apart
		// by reading the row rather than by assuming which one it was.
		if ($this->mapper->findForScope(new SequenceScope(...$scope)) !== null) {
			return;
		}

		$this->seedOrRaise(scope: $scope, target: $target);
	}//end advanceTo()

	/**
	 * Seed a missing counter at the target; if the seed fails, raise the counter instead.
	 *
	 * @param array{registerId: int, schemaId: int, scopeKey: string} $scope  The sequence scope.
	 * @param int                                                     $target The next_value to store.
	 *
	 * @return void
	 *
	 * @throws DbException When the counter cannot be written.
	 */
	private function seedOrRaise(array $scope, int $target): void {
		try {
			// Insert directly rather than through seedScope(): seedScope() answers a
			// lost insert race by spending a number, which would leave the counter
			// below the imported one.
			$this->mapper->insert($this->newScopeRow(scope: $scope, nextValue: $target));
		} catch (Throwable $e) {
			// Lost the seed race. The row exists now, so raise it instead.
			$this->mapper->raiseTo(new SequenceScope(...$scope), minNextValue: $target);
		}
	}//end seedOrRaise()
}//end class
