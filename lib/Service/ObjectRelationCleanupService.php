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

use Psr\Log\LoggerInterface;

/**
 * Cleans up every Nextcloud-entity relation belonging to a deleted object.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Cleanup spans six entity services by design.
 *
 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
 */
class ObjectRelationCleanupService {
	/**
	 * Wire the entity services this cleanup spans.
	 *
	 * @param NoteService $noteService Note (comment) cleanup.
	 * @param TaskService $taskService CalDAV task cleanup.
	 * @param EmailService $emailService Email-link cleanup.
	 * @param CalendarEventService $calendarEventService Calendar-event unlinking.
	 * @param ContactService $contactService Contact-link cleanup.
	 * @param DeckCardService $deckCardService Deck-card-link cleanup.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly NoteService $noteService,
		private readonly TaskService $taskService,
		private readonly EmailService $emailService,
		private readonly CalendarEventService $calendarEventService,
		private readonly ContactService $contactService,
		private readonly DeckCardService $deckCardService,
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
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to clean up notes for deleted object: ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
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
			foreach ($tasks as $task) {
				try {
					$this->taskService->deleteTask($task['calendarId'], $task['id']);
				} catch (\Exception $e) {
					$this->logger->warning(
						'Failed to delete task ' . $task['id'] . ' for object ' . $objectUuid . ': ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			}

			if (empty($tasks) === false) {
				$this->logger->info('Cleaned up ' . count($tasks) . ' task(s) for deleted object: ' . $objectUuid);
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to clean up tasks for deleted object: ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end cleanupTasks()

	/**
	 * Clean up email links for the deleted object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 */
	private function cleanupEmails(string $objectUuid): void {
		try {
			$count = $this->emailService->deleteLinksForObject($objectUuid);
			if ($count > 0) {
				$this->logger->info('Cleaned up ' . $count . ' email link(s) for deleted object: ' . $objectUuid);
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to clean up email links for deleted object: ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
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
			$this->calendarEventService->unlinkEventsForObject($objectUuid);
			$this->logger->info('Unlinked calendar events for deleted object: ' . $objectUuid);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to unlink calendar events for deleted object: ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
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
			$this->contactService->deleteLinksForObject($objectUuid);
			$this->logger->info('Cleaned up contact links for deleted object: ' . $objectUuid);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to clean up contact links for deleted object: ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
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
			$count = $this->deckCardService->deleteLinksForObject($objectUuid);
			if ($count > 0) {
				$this->logger->info('Cleaned up ' . $count . ' deck link(s) for deleted object: ' . $objectUuid);
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to clean up deck links for deleted object: ' . $objectUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end cleanupDeckCards()
}//end class
