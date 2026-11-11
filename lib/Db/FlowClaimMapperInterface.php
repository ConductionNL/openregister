<?php

/**
 * FlowClaimMapperInterface: what callers outside the Db component use of FlowClaimMapper.
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

use DateTime;
use OCP\DB\Exception as DbException;

/**
 * The methods of FlowClaimMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowClaimMapper. Guarded by C04InterfaceBindingsTest.
 */
interface FlowClaimMapperInterface {

	/**
	 * How many claims one pass holds across ALL runs — the pass ceiling's input.
	 *
	 * @param string $owner The pass token.
	 *
	 * @return int Claims held.
	 */
	public function countHeldByOwner(string $owner): int;

	/**
	 * How many claims a run currently has outstanding — the per-run cap's input.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Claims held.
	 */
	public function countHeldForRun(string $runUuid): int;

	/**
	 * The claims a run currently holds.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return array<int, FlowClaim> The claims.
	 */
	public function findByRun(string $runUuid): array;

	/**
	 * Claims older than a cutoff — the reaper's read.
	 *
	 * @param DateTime $before Claims taken before this moment.
	 * @param int $limit Bound on one pass.
	 *
	 * @return array<int, FlowClaim> The abandoned claims.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-branch-abandoned-by-a-crashed-worker-must-be-recovered-and-must-not-be-silently-re-run
	 */
	public function findOlderThan(DateTime $before, int $limit = 25): array;

	/**
	 * Take one claim, or be refused by the unique index.
	 *
	 * Returns false ONLY on a unique-constraint violation — another holder has
	 * the place. Every other database failure propagates: a claim that fails
	 * for an unrelated reason must not read as "somebody else has it".
	 *
	 * @param FlowClaim $claim The claim to take.
	 *
	 * @return bool True when the claim landed.
	 *
	 * @throws DbException On any database failure other than a unique violation.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function insertOrRefuse(FlowClaim $claim): bool;

	/**
	 * Release the named places of one run.
	 *
	 * @param string $runUuid The run.
	 * @param array<int, string> $places The places to release.
	 *
	 * @return int Rows deleted.
	 */
	public function release(string $runUuid, array $places): int;

	/**
	 * Release every claim a holder took on a run.
	 *
	 * @param string $runUuid The run.
	 * @param string $owner The holder's pass token.
	 *
	 * @return int Rows deleted.
	 */
	public function releaseByOwner(string $runUuid, string $owner): int;

	/**
	 * Drop every row whose run no longer exists: the retention pass prunes runs by age.
	 *
	 * @return int Rows deleted.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function deleteOrphans(): int;
}//end interface
