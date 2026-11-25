<?php

/**
 * What an inbox read is anchored to, and whether blocked tasks show.
 *
 * Part of one inbox read's {@see TaskInboxCriteria}.
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

/**
 * The object or run anchor, and whether tasks waiting on an open blocker are listed.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) includeBlocked is a filter
 * VALUE carried into the WHERE, not a behaviour switch.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */
final class TaskInboxAnchor {

	/**
	 * Constructor.
	 *
	 * @param string|null $objectUuid     Restrict to tasks anchored to this object.
	 * @param string|null $runUuid        Restrict to the tasks one flow run raised.
	 *                                    ANCHORS the read the way `objectUuid` does:
	 *                                    the question is what the RUN asked, not what
	 *                                    it asked ME, so the scope narrowing is
	 *                                    dropped. Visibility is not — a caller still
	 *                                    sees only tasks they hold a sanctioned
	 *                                    relationship to, and for a run's own tasks
	 *                                    that relationship is `requester`, which the
	 *                                    engine stamps with the run's acting identity.
	 * @param bool        $includeBlocked When true, tasks whose blocker is still open
	 *                                    are listed too. An object or run anchor
	 *                                    lists them regardless: the case shows what
	 *                                    waits and on what.
	 * @param string|null $flowId         Restrict to tasks the runs of this flow raised.
	 *                                    A filter, not an anchor: scope, visibility
	 *                                    and the blocked and external rules still apply.
	 * @param string|null $nodeId         Restrict to tasks of this flow node.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-person-sees-the-report-on-the-flow
	 */
	public function __construct(
		public readonly ?string $objectUuid = null,
		public readonly ?string $runUuid = null,
		public readonly bool $includeBlocked = false,
		public readonly ?string $flowId = null,
		public readonly ?string $nodeId = null,
	) {
	}//end __construct()
}//end class
