<?php

/**
 * ObjectCleanupListener
 *
 * Listens for ObjectDeletedEvent and cleans up associated notes and tasks.
 * Notes are deleted via ICommentsManager, tasks via TaskService.
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
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * ObjectCleanupListener cleans up notes and tasks when an object is deleted.
 *
 * Handles ObjectDeletedEvent by:
 * (a) Deleting all comments (notes) for the object UUID
 * (b) Deleting all CalDAV tasks linked to the object UUID
 *
 * Failures are logged but do not block the deletion.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<ObjectDeletedEvent>
 */
class ObjectCleanupListener implements IEventListener
{

    /**
     * Note service for comment cleanup.
     *
     * @var NoteService
     */
    private readonly NoteService $noteService;

    /**
     * Task service for CalDAV task cleanup.
     *
     * @var TaskService
     */
    private readonly TaskService $taskService;

    /**
     * Logger for error reporting.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param NoteService     $noteService Note service for comment operations
     * @param TaskService     $taskService Task service for CalDAV operations
     * @param LoggerInterface $logger      Logger for error reporting
     *
     * @return void
     */
    public function __construct(
        NoteService $noteService,
        TaskService $taskService,
        LoggerInterface $logger
    ) {
        $this->noteService = $noteService;
        $this->taskService = $taskService;
        $this->logger      = $logger;
    }//end __construct()

    /**
     * Handle the ObjectDeletedEvent.
     *
     * Cleans up all notes and tasks associated with the deleted object.
     * Failures are logged as warnings but do not block the deletion.
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

        // (a) Delete all notes (comments) for the object.
        try {
            $this->noteService->deleteNotesForObject($objectUuid);
            $this->logger->info(
                'Cleaned up notes for deleted object: '.$objectUuid
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up notes for deleted object: '.$objectUuid.': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

        // (b) Delete all CalDAV tasks linked to the object.
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
                $this->logger->info(
                    'Cleaned up '.count($tasks).' task(s) for deleted object: '.$objectUuid
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up tasks for deleted object: '.$objectUuid.': '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end handle()
}//end class
