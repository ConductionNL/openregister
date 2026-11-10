<?php

/**
 * OpenRegister ObjectStateDeleteGuardListener
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\Flow\FlowRunContextInterface;
use OCA\OpenRegister\Service\Object\RetentionDestructionInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Refuses the delete of an object locked by someone else, or archived.
 *
 * On the deleting event, so a single delete, a bulk delete and a cascade all answer
 * here. A row already in the trash is gone and its purge goes on, and the retention
 * machinery destroys on the date as it does on an open object.
 *
 * @template-implements IEventListener<ObjectDeletingEvent>
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
 */
class ObjectStateDeleteGuardListener implements IEventListener {

	/**
	 * @param IUserSession                  $userSession The caller.
	 * @param FlowRunContextInterface       $runContext  The ambient flow run, so a lock a run took admits that run.
	 * @param RetentionDestructionInterface $retention   Whether the retention machinery is destroying, which no lock or archive stops.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly FlowRunContextInterface $runContext,
		private readonly RetentionDestructionInterface $retention,
	) {
	}//end __construct()

	/**
	 * Refuse the delete when the object is locked against the caller, or archived.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === false || $this->retention->isRunning() === true) {
			return;
		}

		$object = $event->getObject();
		$refusal = $this->refusal(object: $object);
		if ($refusal === null || empty($object->getDeleted()) === false) {
			return;
		}

		$event->setErrors($refusal + ['uuid' => $object->getUuid()]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The errors of the refusal for this object, or null.
	 *
	 * @param ObjectEntity $object The object being deleted.
	 *
	 * @return array<string, mixed>|null
	 */
	private function refusal(ObjectEntity $object): ?array {
		$userId = $this->userSession->getUser()?->getUID();
		if ($object->isLockedBySomeoneElse(userId: $userId, runUuid: $this->runContext->currentRunUuid()) === true) {
			$locked = (new LockedException())->forObject($object, 'delete');

			return ['code' => 'object-locked', 'message' => $locked->getMessage(), 'status' => LockedException::HTTP_STATUS];
		}

		if ($object->isArchived() === true) {
			$archived = (new ObjectStateWriteException())->archived($object);

			return ['code' => 'object-archived', 'message' => $archived->getMessage(), 'status' => ObjectStateWriteException::HTTP_STATUS];
		}

		return null;
	}//end refusal()
}//end class
