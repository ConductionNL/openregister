<?php

/**
 * The safety-net write-back hook: catches calendar writes that reached the
 * backend WITHOUT traversing the Sabre plugin (another app calling
 * CalDavBackend directly, a future DAV path change).
 *
 * Here the write has already committed, so the response is REVERT: the
 * projection is re-rendered from the engine's truth and the actor is told
 * why, through the same gate the plugin uses (flow-task-inbox-projections,
 * design D-6). Echoes of the projector's own writes are recognised by their
 * content hash and ignored, so a gate-driven completion's re-render cannot
 * re-enter the gate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskProjectionServiceInterface;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGateInterface;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Committed calendar writes on projected VTODOs are reverted or applied
 * through the gate.
 *
 * @template-implements IEventListener<CalendarObjectUpdatedEvent|CalendarObjectDeletedEvent>
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The identity reader is a stateless
 * parser published by the projector.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */
class TaskVtodoWriteBackListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TaskVtodoWriteBackGateInterface $gate The one gate.
	 * @param TaskProjectionServiceInterface $projections Failure-isolated reconciliation.
	 * @param IUserSession $userSession Names the acting identity, when there is one.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskVtodoWriteBackGateInterface $gate,
		private readonly TaskProjectionServiceInterface $projections,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a committed calendar write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function handle(Event $event): void {
		if ($event instanceof CalendarObjectUpdatedEvent) {
			$this->onUpdated(event: $event);

			return;
		}

		if ($event instanceof CalendarObjectDeletedEvent) {
			$this->onDeleted(event: $event);
		}
	}//end handle()

	/**
	 * A projected VTODO was overwritten in the backend.
	 *
	 * @param CalendarObjectUpdatedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function onUpdated(CalendarObjectUpdatedEvent $event): void {
		$body = $this->bodyOf(event: $event);
		$taskUuid = $this->projectedTaskOf(body: $body);
		if ($taskUuid !== null) {
			$this->writeBack(body: (string)$body, taskUuid: $taskUuid);
		}
	}//end onUpdated()

	/**
	 * Put a committed edit of a projected VTODO through the gate.
	 *
	 * @param string $body     The calendar document as written.
	 * @param string $taskUuid The task it projects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function writeBack(string $body, string $taskUuid): void {
		try {
			$replacement = $this->gate->handleWrite(calendarData: $body, actor: $this->actor());
		} catch (Throwable $refused) {
			// The gate has already audited, reverted and notified; the write
			// that committed is now overwritten by the engine's truth.
			$this->logger->info(
				'[TaskVtodoWriteBackListener] A committed calendar edit was refused and reverted: ' . $refused->getMessage(),
				['task' => $taskUuid]
			);

			return;
		}

		if ($replacement !== null) {
			// Accepted verb or projection-owned edit: the stored document is
			// the user's; make it the engine's.
			$this->projections->reconcile(taskUuid: $taskUuid);
		}
	}//end writeBack()

	/**
	 * A projected VTODO was deleted in the backend: rebuild it, task untouched.
	 *
	 * @param CalendarObjectDeletedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	private function onDeleted(CalendarObjectDeletedEvent $event): void {
		$taskUuid = $this->projectedTaskOf(body: $this->bodyOf(event: $event));
		if ($taskUuid === null) {
			return;
		}

		$this->logger->info(
			'[TaskVtodoWriteBackListener] A projected calendar entry was deleted; rebuilding it, the task is unchanged.',
			['task' => $taskUuid]
		);
		$this->projections->reconcile(taskUuid: $taskUuid);
	}//end onDeleted()

	/**
	 * The task a calendar document projects, or null when it projects none.
	 *
	 * @param string|null $body The calendar document, or null when absent.
	 *
	 * @return string|null The task uuid.
	 */
	private function projectedTaskOf(?string $body): ?string {
		if ($body === null) {
			return null;
		}

		return TaskCalendarProjector::taskUuidOf(calendarData: $body);
	}//end projectedTaskOf()

	/**
	 * The calendar document the event carries.
	 *
	 * @param CalendarObjectUpdatedEvent|CalendarObjectDeletedEvent $event The event.
	 *
	 * @return string|null The document, or null when absent.
	 */
	private function bodyOf(CalendarObjectUpdatedEvent|CalendarObjectDeletedEvent $event): ?string {
		$objectData = $event->getObjectData();
		$data = ($objectData['calendardata'] ?? null);
		if (is_resource($data) === true) {
			$data = stream_get_contents($data);
		}

		if (is_string($data) === false || $data === '') {
			return null;
		}

		return $data;
	}//end bodyOf()

	/**
	 * The acting identity: the session user, or null (which the gate denies).
	 *
	 * @return string|null The uid.
	 */
	private function actor(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end actor()
}//end class
