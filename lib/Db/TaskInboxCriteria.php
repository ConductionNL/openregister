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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * One inbox read's scope, filters and sort.
 *
 * Built from its two parts — audience and selection — and flattened onto one
 * property per filter the spec names, so every predicate reads one field.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
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
	 * The calling user.
	 *
	 * @var string
	 */
	public readonly string $uid;

	/**
	 * The caller's group ids.
	 *
	 * @var array<int, string>
	 */
	public readonly array $groupIds;

	/**
	 * Whether the caller is an administrator.
	 *
	 * @var bool
	 */
	public readonly bool $isAdmin;

	/**
	 * One of the SCOPE_* values.
	 *
	 * @var string
	 */
	public readonly string $scope;

	/**
	 * Restrict to these states.
	 *
	 * @var array<int, string>
	 */
	public readonly array $states;

	/**
	 * Restrict on terminality, or null for both.
	 *
	 * @var bool|null
	 */
	public readonly ?bool $isTerminal;

	/**
	 * Restrict to one priority.
	 *
	 * @var string|null
	 */
	public readonly ?string $priority;

	/**
	 * Restrict to tasks anchored to this object.
	 *
	 * @var string|null
	 */
	public readonly ?string $objectUuid;

	/**
	 * Restrict to the tasks one flow run raised.
	 *
	 * @var string|null
	 */
	public readonly ?string $runUuid;

	/**
	 * Only tasks due strictly before this instant.
	 *
	 * @var DateTime|null
	 */
	public readonly ?DateTime $overdueAt;

	/**
	 * Only tasks due at or after this instant.
	 *
	 * @var DateTime|null
	 */
	public readonly ?DateTime $dueAfter;

	/**
	 * Only tasks due strictly before this instant.
	 *
	 * @var DateTime|null
	 */
	public readonly ?DateTime $dueBefore;

	/**
	 * One of the SORT_* values.
	 *
	 * @var string
	 */
	public readonly string $sort;

	/**
	 * Whether to invert the sort.
	 *
	 * @var bool
	 */
	public readonly bool $sortDescending;

	/**
	 * Only tasks carrying this kind.
	 *
	 * @var string|null
	 */
	public readonly ?string $kind;

	/**
	 * Words every listed task's title or description must hold, case
	 * insensitively; null for no text filter.
	 *
	 * @var string|null
	 */
	public readonly ?string $term;

	/**
	 * Whether tasks whose blocker is still open are listed.
	 *
	 * @var bool
	 */
	public readonly bool $includeBlocked;

	/**
	 * Restrict to the tasks the runs of this flow raised.
	 *
	 * @var string|null
	 */
	public readonly ?string $flowId;

	/**
	 * Restrict to the tasks of this flow node.
	 *
	 * @var string|null
	 */
	public readonly ?string $nodeId;

	/**
	 * Whether open tasks sort before finished ones.
	 *
	 * @var bool
	 */
	public readonly bool $openFirst;

	/**
	 * Constructor.
	 *
	 * @param TaskInboxAudience       $audience  The caller and the scope of the read.
	 * @param TaskInboxSelection|null $selection The filters, due window, anchor and sort; the defaults when null.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function __construct(
		TaskInboxAudience $audience,
		?TaskInboxSelection $selection = null,
	) {
		$selection = ($selection ?? new TaskInboxSelection());
		$order = $selection->order;
		$this->uid = $audience->uid;
		$this->groupIds = $audience->groupIds;
		$this->isAdmin = $audience->isAdmin;
		$this->scope = $audience->scope;
		$this->states = $selection->filter->states;
		$this->isTerminal = $selection->filter->isTerminal;
		$this->priority = $selection->filter->priority;
		$this->objectUuid = $selection->anchor->objectUuid;
		$this->runUuid = $selection->anchor->runUuid;
		$this->overdueAt = $selection->window->overdueAt;
		$this->dueAfter = $selection->window->dueAfter;
		$this->dueBefore = $selection->window->dueBefore;
		$this->sort = $order->sort;
		$this->sortDescending = $order->sortDescending;
		$this->kind = $selection->filter->kind;
		$this->term = $selection->filter->term;
		$this->includeBlocked = $selection->anchor->includeBlocked;
		$this->flowId = $selection->anchor->flowId;
		$this->nodeId = $selection->anchor->nodeId;
		$this->openFirst = $order->openFirst;

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
