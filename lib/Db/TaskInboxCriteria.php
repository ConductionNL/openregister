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
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A criteria value object:
 * one parameter per filter the spec names, all optional. A builder or an
 * array would hide exactly the shape this class exists to make explicit.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) isAdmin and sortDescending
 * are filter VALUES carried into the WHERE/ORDER BY, not behaviour switches.
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
	 * @param string|null $runUuid Restrict to the tasks one flow run raised.
	 *                             ANCHORS the read the way `objectUuid` does:
	 *                             the question is what the RUN asked, not what
	 *                             it asked ME, so the scope narrowing is
	 *                             dropped. Visibility is not — a caller still
	 *                             sees only tasks they hold a sanctioned
	 *                             relationship to, and for a run's own tasks
	 *                             that relationship is `requester`, which the
	 *                             engine stamps with the run's acting identity.
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
		public readonly ?string $runUuid = null,
		public readonly ?DateTime $overdueAt = null,
		public readonly string $sort = self::SORT_DUE,
		public readonly bool $sortDescending = false,
	) {

	}//end __construct()

	/**
	 * Every stored `assignee` value that means "this caller".
	 *
	 * 🔑 THE RESOLVER DECIDES AUTHORISATION, NOT LISTING. A typed assignee is
	 * stored as `type:id`, so the caller's identity expands to a small, fixed
	 * set of strings the datastore can match with an IN. Resolving a reference
	 * per ROW instead would resolve it a hundred times on one page, which is
	 * exactly what the design forbids.
	 *
	 * Three shapes, and all three are needed. The BARE uid, because every flow
	 * ever authored names people that way and none of them may stop working.
	 * `user:<uid>`, because that is what the picker now writes. And
	 * `group:<id>` for each group the caller is in, because a group reference
	 * is a direct assignee, not a candidate pool.
	 *
	 * 🔴 `agent:` IS DELIBERATELY ABSENT. An agent's task must not appear in a
	 * person's inbox, nor be answerable by them.
	 *
	 * @return array<int, string> The names, without duplicates.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function assigneeNames(): array {
		$names = [$this->uid, 'user:' . $this->uid];

		foreach ($this->groupIds as $groupId) {
			$groupId = trim((string)$groupId);
			if ($groupId !== '') {
				$names[] = 'group:' . $groupId;
			}
		}

		return array_values(array_unique($names));

	}//end assigneeNames()
}//end class
