<?php

/**
 * OpenRegister ObjectRelationCleanupService
 *
 * The relation cleanup that runs when an object is deleted: notes, CalDAV
 * tasks, email links, calendar-event links, contact links and deck-card
 * links. Extracted verbatim from ObjectCleanupListener so the inline
 * (kill-switch) path and the deferred ObjectCleanupJob share ONE
 * implementation and cannot drift.
 *
 * Every cleanup is keyed by the object UUID alone — none of them needs the
 * ObjectEntity. That is why this listener's work is deferrable at all: the
 * row is hard-deleted before ObjectDeletedEvent fires, so a job could never
 * re-fetch it.
 *
 * Each cleanup is independent and fail-soft: a failure in one entity type is
 * logged and does not block the others.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;

/**
 * Cleans up every Nextcloud-entity relation belonging to a deleted object.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Cleanup spans six entity services by design.
 *
 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
 */
class ObjectRelationCleanupService implements ObjectRelationCleanupServiceInterface {
	/**
	 * Wire the entity services this cleanup spans.
	 *
	 * @param NoteServiceInterface         $noteService Note (comment) cleanup.
	 * @param ObjectCalendarTasksInterface $taskService CalDAV task cleanup.
	 * @param ObjectRelationCleanupLinks   $links       Email, calendar-event, contact and deck-card link services.
	 * @param LoggerInterface              $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly NoteServiceInterface $noteService,
		private readonly ObjectCalendarTasksInterface $taskService,
		private readonly ObjectRelationCleanupLinks $links,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run every relation cleanup for one deleted object UUID.
	 *
	 * Idempotent by construction: each cleanup deletes rows matching the
	 * UUID, so a second run finds nothing and is a no-op. This is what makes
	 * the at-least-once delivery of the deferred job safe.
	 *
	 * @param string $objectUuid UUID of the deleted object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
	 */
	public function cleanup(string $objectUuid): void {
		// (a) Delete all notes (comments).
		$this->cleanupNotes(objectUuid: $objectUuid);

		// (b) Delete all CalDAV tasks.
		$this->cleanupTasks(objectUuid: $objectUuid);

		// (c) Delete all email links.
		$this->cleanupEmails(objectUuid: $objectUuid);

		// (d) Unlink all calendar events (remove X-OPENREGISTER-* properties).
		$this->cleanupCalendarEvents(objectUuid: $objectUuid);

		// (e) Delete contact links and clean vCard properties.
		$this->cleanupContacts(objectUuid: $objectUuid);

		// (f) Delete deck card links.
		$this->cleanupDeckCards(objectUuid: $objectUuid);
	}//end cleanup()

	/**
	 * Count the timeline rows that are not notes and not tasks: the email,
	 * calendar-event, contact and deck-card links drawn beside them.
	 *
	 * Counted rather than inferred, because a destruction preview that
	 * promises a number has to be able to produce it. A source that cannot be
	 * read contributes nothing and says so through the logger rather than
	 * inflating the count.
	 *
	 * @param string $objectUuid UUID of the object.
	 *
	 * @return int The number of timeline link rows.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countTimelineLinks(string $objectUuid): int {
		$total = 0;
		foreach ($this->timelineLinkReaders(objectUuid: $objectUuid) as $label => $read) {
			$total += $this->countOf(source: $label . ' for object ' . $objectUuid, read: $read);
		}

		return $total;
	}//end countTimelineLinks()

	/**
	 * A reader per timeline-link source, by the label its warning names it with.
	 *
	 * @param string $objectUuid UUID of the object.
	 *
	 * @return array<string, callable(): array> The readers.
	 */
	private function timelineLinkReaders(string $objectUuid): array {
		return [
			'emails' => fn (): array => $this->links->emailService->getEmailsForObject($objectUuid)['results'],
			'calendar events' => fn (): array => $this->links->calendarEventService->getEventsForObject($objectUuid),
			'contacts' => fn (): array => $this->links->contactService->getContactsForObject($objectUuid),
			'deck cards' => fn (): array => $this->links->deckCardService->getCardsForObject($objectUuid),
		];
	}//end timelineLinkReaders()

	/**
	 * The rows one source holds; a source that cannot be read counts nothing and is logged.
	 *
	 * @param string            $source The source's label and the object, e.g. "notes for object <uuid>".
	 * @param callable(): array $read   The source's reader.
	 *
	 * @return int The number of rows.
	 */
	private function countOf(string $source, callable $read): int {
		try {
			return count($read());
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not count ' . $source . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return 0;
		}
	}//end countOf()

	/**
	 * Destroy the timeline rows that are not notes and not tasks.
	 *
	 * Runs the same four cleanups {@see cleanup()} runs, without the notes and
	 * tasks it also covers: those are their own declared scope members and a
	 * schema may name one without the other.
	 *
	 * @param string $objectUuid UUID of the object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroyTimelineLinks(string $objectUuid): void {
		$this->cleanupEmails(objectUuid: $objectUuid);
		$this->cleanupCalendarEvents(objectUuid: $objectUuid);
		$this->cleanupContacts(objectUuid: $objectUuid);
		$this->cleanupDeckCards(objectUuid: $objectUuid);
	}//end destroyTimelineLinks()

	/**
	 * Clean up notes for the deleted object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupNotes(string $objectUuid): void {
		try {
			$this->noteService->deleteNotesForObject($objectUuid);
			$this->logger->info('Cleaned up notes for deleted object: ' . $objectUuid);
		} catch (Exception $e) {
			$this->warnFailed(failure: 'clean up notes for deleted object: ' . $objectUuid, e: $e);
		}
	}//end cleanupNotes()

	/**
	 * Clean up tasks for the deleted object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupTasks(string $objectUuid): void {
		try {
			$tasks = $this->taskService->getTasksForObject($objectUuid);
			$this->deleteTasks(tasks: $tasks, objectUuid: $objectUuid);
		} catch (Exception $e) {
			$this->warnFailed(failure: 'clean up tasks for deleted object: ' . $objectUuid, e: $e);
		}
	}//end cleanupTasks()

	/**
	 * Delete each listed task; one failing does not strand the others.
	 *
	 * @param array<int, array<string, mixed>> $tasks      The object's tasks.
	 * @param string                           $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function deleteTasks(array $tasks, string $objectUuid): void {
		foreach ($tasks as $task) {
			$this->deleteTask(task: $task, objectUuid: $objectUuid);
		}

		if (empty($tasks) === false) {
			$this->logger->info('Cleaned up ' . count($tasks) . ' task(s) for deleted object: ' . $objectUuid);
		}
	}//end deleteTasks()

	/**
	 * Delete one task on its own calendar, logging a failure.
	 *
	 * @param array<string, mixed> $task       The task.
	 * @param string               $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function deleteTask(array $task, string $objectUuid): void {
		try {
			$this->taskService->deleteTask($task['calendarId'], $task['id']);
		} catch (Exception $e) {
			$this->logger->warning(
				'Failed to delete task ' . $task['id'] . ' for object ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end deleteTask()

	/**
	 * Clean up email links for the deleted object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupEmails(string $objectUuid): void {
		try {
			$count = $this->links->emailService->deleteLinksForObject($objectUuid);
			if ($count > 0) {
				$this->logger->info('Cleaned up ' . $count . ' email link(s) for deleted object: ' . $objectUuid);
			}
		} catch (Exception $e) {
			$this->warnFailed(failure: 'clean up email links for deleted object: ' . $objectUuid, e: $e);
		}
	}//end cleanupEmails()

	/**
	 * Clean up calendar events for the deleted object (unlink, not delete).
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupCalendarEvents(string $objectUuid): void {
		try {
			$this->links->calendarEventService->unlinkEventsForObject($objectUuid);
			$this->logger->info('Unlinked calendar events for deleted object: ' . $objectUuid);
		} catch (Exception $e) {
			$this->warnFailed(failure: 'unlink calendar events for deleted object: ' . $objectUuid, e: $e);
		}
	}//end cleanupCalendarEvents()

	/**
	 * Clean up contact links for the deleted object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupContacts(string $objectUuid): void {
		try {
			$this->links->contactService->deleteLinksForObject($objectUuid);
			$this->logger->info('Cleaned up contact links for deleted object: ' . $objectUuid);
		} catch (Exception $e) {
			$this->warnFailed(failure: 'clean up contact links for deleted object: ' . $objectUuid, e: $e);
		}
	}//end cleanupContacts()

	/**
	 * Clean up deck card links for the deleted object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupDeckCards(string $objectUuid): void {
		try {
			$count = $this->links->deckCardService->deleteLinksForObject($objectUuid);
			if ($count > 0) {
				$this->logger->info('Cleaned up ' . $count . ' deck link(s) for deleted object: ' . $objectUuid);
			}
		} catch (Exception $e) {
			$this->warnFailed(failure: 'clean up deck links for deleted object: ' . $objectUuid, e: $e);
		}
	}//end cleanupDeckCards()

	/**
	 * Log a cleanup that failed for the deleted object; the other cleanups go on.
	 *
	 * @param string    $failure What failed and for which object, e.g. "clean up notes for deleted object: <uuid>".
	 * @param Exception $e       The failure.
	 *
	 * @return void
	 */
	private function warnFailed(string $failure, Exception $e): void {
		$this->logger->warning(
			'Failed to ' . $failure . ': ' . $e->getMessage(),
			['exception' => $e]
		);
	}//end warnFailed()
}//end class
