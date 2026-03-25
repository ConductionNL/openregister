<?php

/**
 * ObjectCleanupListener
 *
 * Listens for ObjectDeletedEvent and cleans up associated notes, tasks,
 * email links, calendar event links, contact links, and deck card links.
 *
 * @category  Listener
 * @package   OCA\OpenRegister\Listener
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * ObjectCleanupListener cleans up all entity relations when an object is deleted.
 *
 * Handles ObjectDeletedEvent by cleaning up:
 * (a) Notes (comments)
 * (b) CalDAV tasks
 * (c) Email links
 * (d) Calendar event links (unlink, not delete)
 * (e) Contact links (unlink vCard properties + delete DB records)
 * (f) Deck card links
 *
 * Failures in one entity type do not block cleanup of other types.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Cleanup requires all service dependencies
 */
class ObjectCleanupListener implements IEventListener
{

    /**
     * Note service.
     *
     * @var NoteService
     */
    private readonly NoteService $noteService;

    /**
     * Task service.
     *
     * @var TaskService
     */
    private readonly TaskService $taskService;

    /**
     * Email service.
     *
     * @var EmailService
     */
    private readonly EmailService $emailService;

    /**
     * Calendar event service.
     *
     * @var CalendarEventService
     */
    private readonly CalendarEventService $calendarEventService;

    /**
     * Contact service.
     *
     * @var ContactService
     */
    private readonly ContactService $contactService;

    /**
     * Deck card service.
     *
     * @var DeckCardService
     */
    private readonly DeckCardService $deckCardService;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param NoteService          $noteService          Note service
     * @param TaskService          $taskService          Task service
     * @param EmailService         $emailService         Email service
     * @param CalendarEventService $calendarEventService Calendar event service
     * @param ContactService       $contactService       Contact service
     * @param DeckCardService      $deckCardService      Deck card service
     * @param LoggerInterface      $logger               Logger
     *
     * @return void
     */
    public function __construct(
        NoteService $noteService,
        TaskService $taskService,
        EmailService $emailService,
        CalendarEventService $calendarEventService,
        ContactService $contactService,
        DeckCardService $deckCardService,
        LoggerInterface $logger
    ) {
        $this->noteService          = $noteService;
        $this->taskService          = $taskService;
        $this->emailService         = $emailService;
        $this->calendarEventService = $calendarEventService;
        $this->contactService       = $contactService;
        $this->deckCardService      = $deckCardService;
        $this->logger               = $logger;
    }//end __construct()

    /**
     * Handle the ObjectDeletedEvent.
     *
     * Cleans up all entity relations. Each cleanup runs independently;
     * failure in one does not block the others.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectDeletedEvent) === false) {
            return;
        }

        $object     = $event->getObject();
        $objectUuid = $object->getUuid();

        // (a) Delete all notes (comments).
        $this->cleanupNotes($objectUuid);

        // (b) Delete all CalDAV tasks.
        $this->cleanupTasks($objectUuid);

        // (c) Delete all email links.
        $this->cleanupEmails($objectUuid);

        // (d) Unlink all calendar events (remove X-OPENREGISTER-* properties).
        $this->cleanupCalendarEvents($objectUuid);

        // (e) Delete contact links and clean vCard properties.
        $this->cleanupContacts($objectUuid);

        // (f) Delete deck card links.
        $this->cleanupDeckCards($objectUuid);
    }//end handle()

    /**
     * Clean up notes for the deleted object.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return void
     */
    private function cleanupNotes(string $objectUuid): void
    {
        try {
            $this->noteService->deleteNotesForObject($objectUuid);
            $this->logger->info('Cleaned up notes for deleted object: '.$objectUuid);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up notes for deleted object: '.$objectUuid.': '.$e->getMessage(),
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
    private function cleanupTasks(string $objectUuid): void
    {
        try {
            $tasks = $this->taskService->getTasksForObject($objectUuid);
            foreach ($tasks as $task) {
                try {
                    $this->taskService->deleteTask($task['calendarId'], $task['id']);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        'Failed to delete task '.$task['id'].' for object '.$objectUuid.': '.$e->getMessage(),
                        ['exception' => $e]
                    );
                }
            }

            if (empty($tasks) === false) {
                $this->logger->info('Cleaned up '.count($tasks).' task(s) for deleted object: '.$objectUuid);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up tasks for deleted object: '.$objectUuid.': '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end cleanupTasks()

    /**
     * Clean up email links for the deleted object.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return void
     */
    private function cleanupEmails(string $objectUuid): void
    {
        try {
            $count = $this->emailService->deleteLinksForObject($objectUuid);
            if ($count > 0) {
                $this->logger->info('Cleaned up '.$count.' email link(s) for deleted object: '.$objectUuid);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up email links for deleted object: '.$objectUuid.': '.$e->getMessage(),
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
    private function cleanupCalendarEvents(string $objectUuid): void
    {
        try {
            $this->calendarEventService->unlinkEventsForObject($objectUuid);
            $this->logger->info('Unlinked calendar events for deleted object: '.$objectUuid);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to unlink calendar events for deleted object: '.$objectUuid.': '.$e->getMessage(),
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
    private function cleanupContacts(string $objectUuid): void
    {
        try {
            $this->contactService->deleteLinksForObject($objectUuid);
            $this->logger->info('Cleaned up contact links for deleted object: '.$objectUuid);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up contact links for deleted object: '.$objectUuid.': '.$e->getMessage(),
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
    private function cleanupDeckCards(string $objectUuid): void
    {
        try {
            $count = $this->deckCardService->deleteLinksForObject($objectUuid);
            if ($count > 0) {
                $this->logger->info('Cleaned up '.$count.' deck link(s) for deleted object: '.$objectUuid);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up deck links for deleted object: '.$objectUuid.': '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end cleanupDeckCards()
}//end class
