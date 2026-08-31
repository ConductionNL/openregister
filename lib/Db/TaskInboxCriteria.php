<?php

/**
 * What one inbox read selects, for whom.
 *
 * A value object rather than a parameter list so `findInbox` and
 * `countInbox` are FORCED to run over the same predicates: the page and the
 * total both take this one object, and a filter that exists for one exists
 * for the other by construction (design D-9).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * One inbox read's scope, filters and sort.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxCriteria {

	/**
	 * Tasks assigned to the caller.
	 */
	public const SCOPE_ASSIGNED = 'assigned';

	/**
	 * Unclaimed tasks in the caller's candidate pools.
	 */
	public const SCOPE_POOLED = 'pooled';

	/**
	 * Tasks the caller watches.
	 */
	public const SCOPE_WATCHED = 'watched';

	/**
	 * Everything the caller may see.
	 */
	public const SCOPE_ALL = 'all';

	/**
	 * Sort keys.
	 */
	public const SORT_DUE = 'dueAt';

	public const SORT_PRIORITY = 'priority';

	public const SORT_CREATED = 'created';

	/**
	 * Constructor.
	 *
	 * @param string $uid The calling user.
	 * @param array<int, string> $groupIds The caller's group ids, resolved by
	 *                                     the SERVICE (the mapper never asks a
	 *                                     group backend).
	 * @param bool $isAdmin Whether the caller is an administrator — the one
	 *                      case visibility is not narrowed.
	 * @param string $scope One of the SCOPE_* values.
	 * @param array<int, string> $states Restrict to these states.
	 * @param bool|null $isTerminal Restrict on terminality, or null for both.
	 * @param string|null $priority Restrict to one priority.
	 * @param string|null $objectUuid Restrict to tasks anchored to this object.
	 * @param DateTime|null $overdueAt When set, only tasks whose `due_at` lies
	 *                                 strictly before this instant — the
	 *                                 derived-overdue filter, handed the clock
	 *                                 by TaskTemporalProjection.
	 * @param string $sort One of the SORT_* values.
	 * @param bool $sortDescending Whether to invert the sort.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function __construct(
		public readonly string $uid,
		public readonly array $groupIds = [],
		public readonly bool $isAdmin = false,
		public readonly string $scope = self::SCOPE_ASSIGNED,
		public readonly array $states = [],
		public readonly ?bool $isTerminal = null,
		public readonly ?string $priority = null,
		public readonly ?string $objectUuid = null,
		public readonly ?DateTime $overdueAt = null,
		public readonly string $sort = self::SORT_DUE,
		public readonly bool $sortDescending = false,
	) {

	}//end __construct()
}//end class
