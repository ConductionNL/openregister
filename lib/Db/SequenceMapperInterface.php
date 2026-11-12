<?php

/**
 * SequenceMapperInterface: what callers outside the Db component use of SequenceMapper.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Service\SequenceScope;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of SequenceMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SequenceMapper. Guarded by C04InterfaceBindingsTest.
 */
interface SequenceMapperInterface {

	/**
	 * Find the sequence row for a scope, or null when absent.
	 *
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 *
	 * @return Sequence|null The row, or null when absent.
	 */
	public function findForScope(SequenceScope $scope): ?Sequence;

	/**
	 * Atomically increment the scope row's `next_value` by one.
	 *
	 * Issues a single `UPDATE … SET next_value = next_value + 1 WHERE …`. The
	 * UPDATE acquires a row lock that both Postgres and MySQL/InnoDB hold for
	 * the duration of the surrounding transaction, so a concurrent reservation
	 * on the same scope blocks here until this transaction commits — which is
	 * what serialises the hand-out and prevents two callers ever reading the
	 * same post-increment value.
	 *
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 *
	 * @return int The number of affected rows (1 when the scope row exists, 0 otherwise).
	 */
	public function incrementScope(SequenceScope $scope): int;

	/**
	 * Raise a scope row's `next_value` so it is at least the given number.
	 *
	 * Conditional on purpose: the `next_value < ?` clause is what makes this safe
	 * to run beside a concurrent reservation. A plain SET would let an import
	 * that supplied an OLD number push the counter backwards, and the next create
	 * would then re-issue a number already printed on a record.
	 *
	 * @param SequenceScope $scope The register, schema and scope key of the counter.
	 * @param int $minNextValue The value `next_value` must reach.
	 *
	 * @return int Affected rows: 1 when the row moved, 0 when it was already high enough or absent.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function raiseTo(SequenceScope $scope, int $minNextValue): int;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return Sequence The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
