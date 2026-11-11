<?php

/**
 * FlowStreamMapperInterface: what callers outside the Db component use of FlowStreamMapper.
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

use OCP\AppFramework\Db\Entity;

/**
 * The methods of FlowStreamMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowStreamMapper. Guarded by F05InterfaceBindingsTest.
 */
interface FlowStreamMapperInterface {

	/**
	 * Every stream of a run, in ordinal order.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return array<int, FlowStream> The streams.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function findByRun(string $runUuid): array;

	/**
	 * One stream of a run.
	 *
	 * @param string $runUuid  The run.
	 * @param string $streamId The stream.
	 *
	 * @return FlowStream|null The stream, or null when absent.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function findByRunAndStream(string $runUuid, string $streamId): ?FlowStream;

	/**
	 * Reserve the next step position within a stream.
	 *
	 * @param string $runUuid  The run.
	 * @param string $streamId The stream.
	 *
	 * @return int The reserved position, or 0 when the stream row does not exist.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function allocateNextSequence(string $runUuid, string $streamId): int;

	/**
	 * Insert a stream row.
	 *
	 * @param Entity $entity The stream.
	 *
	 * @return FlowStream The inserted stream.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update a stream row.
	 *
	 * @param Entity $entity The stream.
	 *
	 * @return FlowStream The updated stream.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Drop every row whose run no longer exists: the retention pass prunes runs by age.
	 *
	 * @return int Rows deleted.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function deleteOrphans(): int;
}//end interface
