<?php

/**
 * ObjectTaskRecord: one task on an object, as a consuming app reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

use DateTimeImmutable;

/**
 * A task's identity, state, decision and delegation, without the task store's entity.
 *
 * `decidedBy` is who finished the task (`completedBy`), `assignee` who holds it
 * now; after a delegation `onBehalfOf` names the original performer and
 * `mandate` the authority the hand-over relied on.
 *
 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
 */
class ObjectTaskRecord {

	/**
	 * Constructor.
	 *
	 * @param string                 $uuid           The task uuid.
	 * @param string                 $title          The task title.
	 * @param string|null            $workflowStepId The step of the app's chain this task stands for.
	 * @param string                 $state          The lifecycle state.
	 * @param bool                   $isTerminal     Whether the task is finished.
	 * @param string|null            $outcome        The completion outcome, once finished.
	 * @param string|null            $assignee       Who holds the task now.
	 * @param string|null            $decidedBy      Who finished it.
	 * @param string|null            $onBehalfOf     The original performer of a delegated task.
	 * @param string|null            $mandate        The authority a delegation relied on.
	 * @param DateTimeImmutable|null $decidedAt      When it was finished.
	 * @param DateTimeImmutable|null $createdAt      When it was created.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A record field, not a mode switch.
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One field per published column.
	 *
	 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $title,
		public readonly ?string $workflowStepId,
		public readonly string $state,
		public readonly bool $isTerminal,
		public readonly ?string $outcome=null,
		public readonly ?string $assignee=null,
		public readonly ?string $decidedBy=null,
		public readonly ?string $onBehalfOf=null,
		public readonly ?string $mandate=null,
		public readonly ?DateTimeImmutable $decidedAt=null,
		public readonly ?DateTimeImmutable $createdAt=null,
	) {
	}//end __construct()
}//end class
