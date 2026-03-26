<?php

/**
 * RelationsController
 *
 * Unified REST controller that aggregates all relation types for an object.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * RelationsController provides a unified endpoint for all object relations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregation of all relation types requires many dependencies
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor requires all service dependencies
 */
class RelationsController extends Controller
{

    /**
     * Object service.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

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
     * Constructor.
     *
     * @param string               $appName              Application name
     * @param IRequest             $request              HTTP request
     * @param ObjectService        $objectService        Object service
     * @param NoteService          $noteService          Note service
     * @param TaskService          $taskService          Task service
     * @param EmailService         $emailService         Email service
     * @param CalendarEventService $calendarEventService Calendar event service
     * @param ContactService       $contactService       Contact service
     * @param DeckCardService      $deckCardService      Deck card service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ObjectService $objectService,
        NoteService $noteService,
        TaskService $taskService,
        EmailService $emailService,
        CalendarEventService $calendarEventService,
        ContactService $contactService,
        DeckCardService $deckCardService
    ) {
        parent::__construct($appName, $request);

        $this->objectService        = $objectService;
        $this->noteService          = $noteService;
        $this->taskService          = $taskService;
        $this->emailService         = $emailService;
        $this->calendarEventService = $calendarEventService;
        $this->contactService       = $contactService;
        $this->deckCardService      = $deckCardService;
    }//end __construct()

    /**
     * Get all relations for an object.
     *
     * Supports filtering with ?types=emails,contacts
     * and timeline view with ?view=timeline
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject($register, $schema, $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $params      = $this->request->getParams();
            $objectUuid  = $object->getUuid();
            $view        = $params['view'] ?? null;
            $typesFilter = null;

            if (empty($params['types']) === false) {
                $typesFilter = array_map('trim', explode(',', $params['types']));
            }

            $relations = $this->gatherRelations($objectUuid, $typesFilter);

            if ($view === 'timeline') {
                return new JSONResponse($this->buildTimeline($relations));
            }

            return new JSONResponse($relations);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end index()

    /**
     * Gather all relations for an object, optionally filtered by type.
     *
     * @param string     $objectUuid  The object UUID.
     * @param array|null $typesFilter Types to include, or null for all.
     *
     * @return array Relations grouped by type.
     */
    private function gatherRelations(string $objectUuid, ?array $typesFilter): array
    {
        $relations = [];

        // Notes.
        if ($typesFilter === null || in_array('notes', $typesFilter) === true) {
            try {
                $notes = $this->noteService->getNotesForObject($objectUuid);
                $relations['notes'] = ['results' => $notes, 'total' => count($notes)];
            } catch (Exception $e) {
                // Silently skip on error.
            }
        }

        // Tasks.
        if ($typesFilter === null || in_array('tasks', $typesFilter) === true) {
            try {
                $tasks = $this->taskService->getTasksForObject($objectUuid);
                $relations['tasks'] = ['results' => $tasks, 'total' => count($tasks)];
            } catch (Exception $e) {
                // Silently skip on error.
            }
        }

        // Emails (only if Mail app is available).
        if (($typesFilter === null || in_array('emails', $typesFilter) === true)
            && $this->emailService->isMailAvailable() === true
        ) {
            try {
                $relations['emails'] = $this->emailService->getEmailsForObject($objectUuid);
            } catch (Exception $e) {
                // Silently skip on error.
            }
        }

        // Calendar events.
        if ($typesFilter === null || in_array('events', $typesFilter) === true) {
            try {
                $events = $this->calendarEventService->getEventsForObject($objectUuid);
                $relations['events'] = ['results' => $events, 'total' => count($events)];
            } catch (Exception $e) {
                // Silently skip on error.
            }
        }

        // Contacts.
        if ($typesFilter === null || in_array('contacts', $typesFilter) === true) {
            try {
                $relations['contacts'] = $this->contactService->getContactsForObject($objectUuid);
            } catch (Exception $e) {
                // Silently skip on error.
            }
        }

        // Deck cards (only if Deck app is available).
        if (($typesFilter === null || in_array('deck', $typesFilter) === true)
            && $this->deckCardService->isDeckAvailable() === true
        ) {
            try {
                $relations['deck'] = $this->deckCardService->getCardsForObject($objectUuid);
            } catch (Exception $e) {
                // Silently skip on error.
            }
        }

        return $relations;
    }//end gatherRelations()

    /**
     * Build a timeline view from grouped relations.
     *
     * @param array $relations Grouped relations.
     *
     * @return array Flat sorted timeline items.
     */
    private function buildTimeline(array $relations): array
    {
        $timeline = [];

        foreach ($relations as $type => $data) {
            if (isset($data['results']) === false) {
                continue;
            }

            foreach ($data['results'] as $item) {
                $item['type'] = rtrim($type, 's');

                // Normalize date for sorting.
                $date = $item['date'] ?? $item['linkedAt'] ?? $item['createdAt']
                    ?? $item['dtstart'] ?? $item['created'] ?? null;
                $item['_sortDate'] = $date;

                $timeline[] = $item;
            }
        }

        // Sort by date descending.
        usort($timeline, static function (array $a, array $b): int {
            return strcmp($b['_sortDate'] ?? '', $a['_sortDate'] ?? '');
        });

        // Remove sort key.
        foreach ($timeline as &$item) {
            unset($item['_sortDate']);
        }

        return $timeline;
    }//end buildTimeline()

    /**
     * Validate that the object exists.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null
     */
    private function validateObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateObject()
}//end class
