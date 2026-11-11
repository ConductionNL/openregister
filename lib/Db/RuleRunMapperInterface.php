<?php

/**
 * RuleRunMapperInterface: what callers outside the Db component use of RuleRunMapper.
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
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of RuleRunMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to RuleRunMapper. Guarded by C04InterfaceBindingsTest.
 */
interface RuleRunMapperInterface {

	/**
	 * How many rows one run-log read returns unless the caller asks for fewer. RuleRunMapper inherits it.
	 *
	 * @var integer
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * One rule's runs, newest first, optionally narrowed.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string|null $verdict Narrow to one verdict.
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param DateTime|null $until Only runs at or before this moment.
	 * @param int $limit How many rows to return, capped at MAX_LIMIT.
	 * @param int $offset Where to start.
	 *
	 * @return array<int, RuleRun> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findByRule(
		string $ruleId,
		?string $verdict = null,
		?DateTime $since = null,
		?DateTime $until = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): array;

	/**
	 * One rule's runs, newest first, narrowed by the selection's filter.
	 *
	 * @param RuleRunSelection $selection The rule and its filter.
	 * @param ListQuery        $page      The limit (capped at MAX_LIMIT) and offset; other fields are ignored.
	 *
	 * @return array<int, RuleRun> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findRuns(RuleRunSelection $selection, ListQuery $page): array;

	/**
	 * The most recent runs across every rule.
	 *
	 * The per-rule listing answers "how is this rule doing"; an operations
	 * console asks the other question, "what has the engine been doing", and
	 * cannot ask it by walking the rules one at a time. The order and the
	 * narrowing are the same as {@see findByRule()}, minus the rule.
	 *
	 * Index-backed on `created` (`or_rulerun_created_idx`), which is the
	 * column the ordering and the window both use, so the console never
	 * scans the run log (ADR-009).
	 *
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param int           $limit How many rows to return, capped at MAX_LIMIT.
	 *
	 * @return array<int, RuleRun> The rows, newest first.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findRecent(?DateTime $since = null, int $limit = self::DEFAULT_LIMIT): array;

	/**
	 * How many runs one rule has in the log, under the same filters.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string|null $verdict Narrow to one verdict.
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param DateTime|null $until Only runs at or before this moment.
	 *
	 * @return int The number of matching rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function countByRule(
		string $ruleId,
		?string $verdict = null,
		?DateTime $since = null,
		?DateTime $until = null,
	): int;

	/**
	 * How many runs one rule has in the log, under the selection's filter.
	 *
	 * @param RuleRunSelection $selection The rule and its filter.
	 *
	 * @return int The number of matching rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function countRuns(RuleRunSelection $selection): int;

	/**
	 * Delete detail rows older than a cut-off, in bounded batches.
	 *
	 * The summary rows are untouched, which is the point: the inventory keeps
	 * answering "last run" and "last error" after the detail is gone.
	 *
	 * @param DateTime $before Rows created strictly before this moment go.
	 * @param int $batches How many batches to delete in one pass.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function pruneBefore(DateTime $before, int $batches = 25): int;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return Entity The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
