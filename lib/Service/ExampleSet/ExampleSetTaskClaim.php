<?php

/**
 * OpenRegister Example Set Task Claim
 *
 * The moment a set's task was picked up: a task may declare `claimedBy`, its
 * assignee, and `claimedAt`, and install appends one `claim` entry to the
 * task's audit by that account at that moment, so its history reads like a
 * live claim. It is an audit entry, not the claim verb: the verb only claims
 * an unassigned task, and a set's task is born assigned. The audit is
 * append-only, so the entry is written with its declared moment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use Psr\Container\ContainerInterface;

/**
 * Checks and writes the claim of a set's task.
 *
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
 */
class ExampleSetTaskClaim {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the task audit mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * A claim by someone other than the assignee, or at a moment still to come. The task part refuses a
	 * `claimedAt` that is no date.
	 *
	 * @param array<string, mixed> $data   The filled task fields.
	 * @param DateTime|null        $moment The claim's moment, null when none is declared.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
	 */
	public function refusal(array $data, ?DateTime $moment): string {
		$claimedBy = (string) ($data['claimedBy'] ?? '');
		if ($claimedBy === '') {
			if (isset($data['claimedAt']) === true) {
				return 'claimedAt needs claimedBy';
			}

			return '';
		}

		if ($claimedBy !== (string) ($data['assignee'] ?? '')) {
			return 'claimedBy is the assignee: "'.$claimedBy.'" is not';
		}

		if ($moment !== null && $moment > new DateTime()) {
			return 'claimedAt lies in the future';
		}

		return '';
	}//end refusal()

	/**
	 * Append the task's claim to its audit, when it declares one.
	 *
	 * @param Task                 $task   The written task.
	 * @param array<string, mixed> $data   The filled task fields.
	 * @param DateTime|null        $moment The claim's moment, null for now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
	 */
	public function write(Task $task, array $data, ?DateTime $moment): void {
		if ((string) ($data['claimedBy'] ?? '') === '') {
			return;
		}

		$entry = new TaskAudit();
		$entry->setTaskId((int) $task->getId());
		$entry->setAction('claim');
		$entry->setStateAfter($task->getState());
		$entry->setActor((string) $data['claimedBy']);
		$entry->setPerformerType($task->getPerformerType());
		$entry->setAuthorized(true);
		$entry->setCreated(($moment ?? new DateTime()));
		$this->container->get(TaskAuditMapperInterface::class)->insert($entry);
	}//end write()
}//end class
