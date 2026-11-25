<?php

/**
 * Asks the permission layer whether the person a task would go to holds the
 * mandate the task requires, and returns the evidence when they do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskMandateRefusedException;
use OCA\OpenRegister\Kernel\Contracts\MandateEvidenceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The mandate check of a delegation or a substitution (design D-2).
 *
 * FAILS CLOSED. A subject that cannot be loaded, a verb the catalogue does not
 * know, a missing collaborator, or a permission check that throws all refuse.
 * The permission question itself is Core's, asked through the Kernel port
 * MandateEvidenceInterface about the named person, never as the system.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */
class TaskMandateGuard {

	/**
	 * The verb asked when the task names no mandate but has a subject:
	 * nobody receives work on a case they cannot open.
	 *
	 * @var string
	 */
	public const READ_VERB = 'read';

	/**
	 * The evidence source of a verb an app decided through its own vote.
	 *
	 * @var string
	 */
	public const SOURCE_CUSTOM = 'custom';

	/**
	 * Constructor.
	 *
	 * @param MandateEvidenceInterface|null $mandates Whether a person holds a verb, and what granted it.
	 * @param LoggerInterface|null          $logger   Where a check that broke is recorded.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function __construct(
		private readonly ?MandateEvidenceInterface $mandates = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Assert that a person holds the task's mandate; return the evidence.
	 *
	 * - Subject and `requiredMandate`: that verb, on the subject object.
	 * - Subject and no `requiredMandate`: read, on the subject object.
	 * - `requiredMandate` and no subject: that verb, on the schema the task
	 *   names; refused when it names none.
	 * - Neither: nothing to check, no evidence.
	 *
	 * @param Task   $task The task being handed over.
	 * @param string $uid  The person it would go to.
	 *
	 * @return array{verb: string, source: string, rule: mixed, until: string|null, app?: string}|null
	 *         The evidence, or null when there was nothing to check.
	 *
	 * @throws TaskMandateRefusedException When the person does not hold it, or it cannot be confirmed.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function assertHolds(Task $task, string $uid): ?array {
		$required = trim((string)$task->getRequiredMandate());
		$subject = trim((string)$task->getObjectUuid());
		if ($required === '' && $subject === '') {
			return null;
		}

		$verb = $required;
		if ($verb === '') {
			$verb = self::READ_VERB;
		}

		$evidence = null;
		try {
			$evidence = $this->evidence(task: $task, uid: $uid, verb: $verb);
		} catch (Throwable $failure) {
			$this->logger?->warning(
				'[TaskMandateGuard] The mandate check did not answer; refusing: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'verb' => $verb, 'exception' => $failure]
			);
		}

		if ($evidence === null) {
			throw new TaskMandateRefusedException(verb: $verb, uid: $uid);
		}

		return $evidence;
	}//end assertHolds()

	/**
	 * Whether a person holds the task's mandate, never throwing.
	 *
	 * @param Task   $task The task.
	 * @param string $uid  The person.
	 *
	 * @return array|null The evidence when held (an empty array when there was nothing to check), null when not.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function evidenceFor(Task $task, string $uid): ?array {
		try {
			return ($this->assertHolds(task: $task, uid: $uid) ?? []);
		} catch (TaskMandateRefusedException) {
			return null;
		}
	}//end evidenceFor()

	/**
	 * The verb the check asks for this task.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The required mandate, or read.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function verbFor(Task $task): string {
		$required = trim((string)$task->getRequiredMandate());
		if ($required === '') {
			return self::READ_VERB;
		}

		return $required;
	}//end verbFor()

	/**
	 * The evidence of the grant, or null when the person does not hold it.
	 *
	 * @param Task   $task The task.
	 * @param string $uid  The person.
	 * @param string $verb The verb asked.
	 *
	 * @return array|null The evidence, or null.
	 */
	private function evidence(Task $task, string $uid, string $verb): ?array {
		if ($this->mandates === null) {
			return null;
		}

		$subject = trim((string)$task->getObjectUuid());
		if ($subject === '') {
			$subject = null;
		}

		return $this->mandates->evidence(
			verb: $verb,
			uid: $uid,
			objectUuid: $subject,
			registerId: $task->getRegisterId(),
			schemaId: $task->getSchemaId()
		);
	}//end evidence()
}//end class
