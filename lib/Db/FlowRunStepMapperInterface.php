<?php

/**
 * FlowRunStepMapperInterface: what callers outside the Db component use of FlowRunStepMapper.
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

use OCP\AppFramework\Db\Entity;

/**
 * The methods of FlowRunStepMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowRunStepMapper. Guarded by F05InterfaceBindingsTest.
 */
interface FlowRunStepMapperInterface {

	/**
	 * The highest sequence recorded for a run so far.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return int The highest recorded sequence, or -1 when the run has no steps.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function highestSequence(string $runUuid): int;

	/**
	 * Delete the steps of one run.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return int The number of rows removed.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function deleteByRun(string $runUuid): int;

	/**
	 * Insert one step row.
	 *
	 * @param Entity $entity The step.
	 *
	 * @return FlowRunStep The inserted step.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Per node of one flow: executions, failures and the average duration of the
	 * successful ones, over the runs the window counts.
	 *
	 * @param string   $flowId       The flow.
	 * @param string   $organisation Count only this organisation's runs.
	 * @param DateTime $from         Runs created from this moment.
	 * @param DateTime $to           Runs created up to this moment.
	 *
	 * @return array<string, array{executions: int, failed: int, averageMs: float|null}> Keyed by node id.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function aggregatesByNode(string $flowId, string $organisation, DateTime $from, DateTime $to): array;

	/**
	 * The last moment a step of each completed run of the window finished.
	 *
	 * @param string   $flowId       The flow.
	 * @param string   $organisation Count only this organisation's runs.
	 * @param DateTime $from         Runs created from this moment.
	 * @param DateTime $to           Runs created up to this moment.
	 *
	 * @return array<string, DateTime> Last finish keyed by run uuid.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function lastFinishedByRun(string $flowId, string $organisation, DateTime $from, DateTime $to): array;
}//end interface
