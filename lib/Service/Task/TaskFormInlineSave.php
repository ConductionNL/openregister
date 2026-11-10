<?php

/**
 * The inline form path: accepted field values through the ordinary object write.
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
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUserSession;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SaveActor;

/**
 * Writes a task form's accepted values onto its subject object, as the run that raised the task.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
final class TaskFormInlineSave {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The ordinary object write.
	 * @param IUserSession $userSession The acting identity the object write authorizes.
	 * @param FlowRunContext|null $runContext Names the run this write belongs to,
	 *                             so the run's own lock does not refuse it.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly IUserSession $userSession,
		private readonly ?FlowRunContext $runContext = null,
	) {

	}//end __construct()

	/**
	 * Merge the accepted values into the task's subject and save it.
	 *
	 * @param Task $task The task whose subject is written.
	 * @param array<string, mixed> $accepted The values the allowlist accepted.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller may not write the object.
	 * @throws TaskSubjectWriteRefusedException When the schema refuses, or the object is gone.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	public function save(Task $task, array $accepted): void {
		$object = $this->subject(objectUuid: trim((string)$task->getObjectUuid()));

		// 🔴 THIS WRITE BELONGS TO THE RUN, EVEN THOUGH A PERSON MADE THE REQUEST.
		// The object-lock guard reads the acting run from an ambient context
		// precisely so a flow that locks its case at one step is not turned away
		// by its own lock at the next. That context is set while the ENGINE
		// walks the graph and not while a person answers over HTTP, so a run
		// that locked its case and then asked a form on it could never be
		// answered: the completion came back 500 with "Object is locked by flow
		// run …, running as admin", naming the very run trying to write.
		//
		// Measured by the flow-subjects Newman collection, which locks a case
		// and then asks a person with a form on it — the scene from the brief.
		$this->runContext?->push(frame: new FlowRunFrame(runUuid: self::actingRun(task: $task), nodeId: 'task-form', sequence: 0));

		try {
			$this->writeMerged(object: $object, accepted: $accepted);
		} finally {
			// In a `finally`, so a refused write does not leave the request
			// pretending to be a run for everything that follows it.
			$this->runContext?->pop();
		}
	}//end save()

	/**
	 * The subject object, read as a lookup rather than a person's read.
	 *
	 * @param string $objectUuid The subject object.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws TaskSubjectWriteRefusedException When the object no longer exists.
	 */
	private function subject(string $objectUuid): ObjectEntity {
		$object = WriteCause::asLookup(fn () => $this->objects->find(id: $objectUuid));
		if ($object === null) {
			throw new TaskSubjectWriteRefusedException(
				message: sprintf('Subject object "%s" no longer exists, so the form values cannot be written.', $objectUuid)
			);
		}

		return $object;
	}//end subject()

	/**
	 * The run a task belongs to, or null when unknown.
	 *
	 * @param Task $task The task.
	 *
	 * @return string|null The run uuid.
	 */
	private static function actingRun(Task $task): ?string {
		$runUuid = trim((string)$task->getRunUuid());
		if ($runUuid === '') {
			return null;
		}

		return $runUuid;
	}//end actingRun()

	/**
	 * Save the object with the accepted values merged in, translating refusals.
	 *
	 * @param ObjectEntity $object The subject object.
	 * @param array<string, mixed> $accepted The values the allowlist accepted.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller may not write the object.
	 * @throws TaskSubjectWriteRefusedException When the schema refuses.
	 */
	private function writeMerged(ObjectEntity $object, array $accepted): void {
		try {
			$this->objects->saveObject(
				object: array_merge($object->getObject(), $accepted),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $object->getRegister(), schema: $object->getSchema()),
					uuid: $object->getUuid(),
					actor: new SaveActor(currentUser: $this->userSession->getUser())
				)
			);
		} catch (NotAuthorizedException $denied) {
			throw new TaskAccessDeniedException(message: $denied->getMessage());
		} catch (HookStoppedException | ValidationException | CustomValidationException $refused) {
			throw new TaskSubjectWriteRefusedException(message: $refused->getMessage(), previous: $refused);
		}
	}//end writeMerged()
}//end class
