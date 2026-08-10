<?php

/**
 * RelationsController
 *
 * Unified REST controller that aggregates all relation types for an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use Psr\Log\LoggerInterface;

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
     * Pluggable leaf integrations aggregated into `/relations` beyond the core
     * six (notes/tasks/emails/events/contacts/deck). Keyed by the response group
     * key the frontend widget consumes; each entry names the OR link service
     * class (resolved lazily), its app-availability guard method (or null when
     * the service has none), and the per-object lookup method.
     *
     * @var array<string,array{class:class-string,available:?string,method:string}>
     */
    private const LEAF_INTEGRATIONS = [
        'talk'        => [
            'class'     => \OCA\OpenRegister\Service\TalkLinkService::class,
            'available' => 'isTalkAvailable',
            'method'    => 'getLinkedRooms',
        ],
        'forms'       => [
            'class'     => \OCA\OpenRegister\Service\FormLinkService::class,
            'available' => null,
            'method'    => 'getLinkedForms',
        ],
        'maps'        => [
            'class'     => \OCA\OpenRegister\Service\MapLinkService::class,
            'available' => 'isMapsAvailable',
            'method'    => 'getLinkedPois',
        ],
        'polls'       => [
            'class'     => \OCA\OpenRegister\Service\PollLinkService::class,
            'available' => 'isPollsAvailable',
            'method'    => 'getLinkedPolls',
        ],
        'bookmarks'   => [
            'class'     => \OCA\OpenRegister\Service\BookmarkLinkService::class,
            'available' => 'isBookmarksAvailable',
            'method'    => 'getLinkedBookmarks',
        ],
        'collectives' => [
            'class'     => \OCA\OpenRegister\Service\CollectiveLinkService::class,
            'available' => 'isCollectivesAvailable',
            'method'    => 'getLinkedPages',
        ],
        'photos'      => [
            'class'     => \OCA\OpenRegister\Service\PhotoLinkService::class,
            'available' => 'isPhotosAvailable',
            'method'    => 'getLinkedAlbums',
        ],
        'cospend'     => [
            'class'     => \OCA\OpenRegister\Service\CospendLinkService::class,
            'available' => 'isCospendAvailable',
            'method'    => 'getLinkedEntries',
        ],
        'timetracker' => [
            'class'     => \OCA\OpenRegister\Service\TimeTrackerLinkService::class,
            'available' => 'isTimeManagerAvailable',
            'method'    => 'getLinkedEntries',
        ],
        'analytics'   => [
            'class'     => \OCA\OpenRegister\Service\AnalyticsLinkService::class,
            'available' => 'isAnalyticsAvailable',
            'method'    => 'getLinkedReports',
        ],
        'flow'        => [
            'class'     => \OCA\OpenRegister\Service\FlowLinkService::class,
            'available' => 'isFlowAvailable',
            'method'    => 'getLinkedOperations',
        ],
        'openproject' => [
            'class'     => \OCA\OpenRegister\Service\OpenProjectLinkService::class,
            'available' => 'isOpenConnectorAvailable',
            'method'    => 'getLinkedWorkPackages',
        ],
        'xwiki'       => [
            'class'     => \OCA\OpenRegister\Service\XwikiLinkService::class,
            'available' => 'isOpenConnectorAvailable',
            'method'    => 'getLinkedPages',
        ],
    ];

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
     * Logger for surfacing per-type aggregation failures.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

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
     * @param LoggerInterface      $logger               PSR-3 logger
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
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
        DeckCardService $deckCardService,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->objectService        = $objectService;
        $this->noteService          = $noteService;
        $this->taskService          = $taskService;
        $this->emailService         = $emailService;
        $this->calendarEventService = $calendarEventService;
        $this->contactService       = $contactService;
        $this->deckCardService      = $deckCardService;
        $this->logger = $logger;
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
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

            $relations = $this->gatherRelations(objectUuid: $objectUuid, typesFilter: $typesFilter);

            if ($view === 'timeline') {
                return new JSONResponse($this->buildTimeline(relations: $relations));
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
     * Per-type failures are caught individually so that one bad service cannot
     * block the rest of the aggregation, but each failure is now logged via
     * {@see LoggerInterface::error()} (with the exception attached for the
     * full stack trace) and recorded under the response's `_errors` key so
     * callers can detect partial failures instead of silently receiving an
     * incomplete envelope.
     *
     * @param string     $objectUuid  The object UUID.
     * @param array|null $typesFilter Types to include, or null for all.
     *
     * @return array Relations grouped by type. When one or more per-type
     *               lookups fail, the result also carries an `_errors` map
     *               keyed by type with `{message, exception}` entries.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-2
     */
    private function gatherRelations(string $objectUuid, ?array $typesFilter): array
    {
        $relations = [];
        $errors    = [];

        // Notes.
        if ($typesFilter === null || in_array('notes', $typesFilter) === true) {
            try {
                $notes = $this->noteService->getNotesForObject($objectUuid);
                $relations['notes'] = ['results' => $notes, 'total' => count($notes)];
            } catch (Exception $e) {
                $this->logRelationFailure(type: 'notes', objectUuid: $objectUuid, exception: $e);
                $errors['notes'] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }
        }

        // Tasks.
        if ($typesFilter === null || in_array('tasks', $typesFilter) === true) {
            try {
                $tasks = $this->taskService->getTasksForObject($objectUuid);
                $relations['tasks'] = ['results' => $tasks, 'total' => count($tasks)];
            } catch (Exception $e) {
                $this->logRelationFailure(type: 'tasks', objectUuid: $objectUuid, exception: $e);
                $errors['tasks'] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }
        }

        // Emails (only if Mail app is available).
        if (($typesFilter === null || in_array('emails', $typesFilter) === true)
            && $this->emailService->isMailAvailable() === true
        ) {
            try {
                $relations['emails'] = $this->emailService->getEmailsForObject($objectUuid);
            } catch (Exception $e) {
                $this->logRelationFailure(type: 'emails', objectUuid: $objectUuid, exception: $e);
                $errors['emails'] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }
        }

        // Calendar events.
        if ($typesFilter === null || in_array('events', $typesFilter) === true) {
            try {
                $events = $this->calendarEventService->getEventsForObject($objectUuid);
                $relations['events'] = ['results' => $events, 'total' => count($events)];
            } catch (Exception $e) {
                $this->logRelationFailure(type: 'events', objectUuid: $objectUuid, exception: $e);
                $errors['events'] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }
        }

        // Contacts.
        if ($typesFilter === null || in_array('contacts', $typesFilter) === true) {
            try {
                $relations['contacts'] = $this->contactService->getContactsForObject($objectUuid);
            } catch (Exception $e) {
                $this->logRelationFailure(type: 'contacts', objectUuid: $objectUuid, exception: $e);
                $errors['contacts'] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }
        }

        // Deck cards (only if Deck app is available).
        if (($typesFilter === null || in_array('deck', $typesFilter) === true)
            && $this->deckCardService->isDeckAvailable() === true
        ) {
            try {
                $relations['deck'] = $this->deckCardService->getCardsForObject($objectUuid);
            } catch (Exception $e) {
                $this->logRelationFailure(type: 'deck', objectUuid: $objectUuid, exception: $e);
                $errors['deck'] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }
        }

        // Additional pluggable leaf integrations. Each owning app's link service
        // is resolved lazily through the server container and guarded by its own
        // availability check, so a missing/disabled app is silently skipped and
        // never breaks the core relations response. Records arrive as plain
        // arrays (url-stamped by the service where the app exposes a canonical
        // deep-link route). See ADR-022 — apps consume OR leaf abstractions.
        foreach (self::LEAF_INTEGRATIONS as $key => $spec) {
            if ($typesFilter !== null && in_array($key, $typesFilter) === false) {
                continue;
            }

            // Resolving the owning app's service is NOT part of the lookup, and
            // failing to resolve it is not an error to report. An app that is
            // absent or disabled makes Server::get() throw, and folding that
            // into $errors put an `_errors` key on a response where every
            // requested lookup actually succeeded — the opposite of the
            // "silently skipped, never breaks the core response" contract above,
            // and visible to every consumer that walks the envelope's top-level
            // keys. Only a failure of an AVAILABLE service's own call is an
            // error worth surfacing.
            try {
                $service = \OCP\Server::get($spec['class']);
            } catch (\Throwable $e) {
                continue;
            }

            // Server::get() does not always THROW when it cannot supply a
            // service — it can hand back null. Calling the availability probe on
            // that produced "Call to a member function isXAvailable() on null"
            // for every leaf, which then landed in $errors.
            if (is_object($service) === false) {
                continue;
            }

            try {
                if ($spec['available'] !== null && $service->{$spec['available']}() !== true) {
                    continue;
                }

                $items           = $service->{$spec['method']}($objectUuid);
                $relations[$key] = ['results' => $items, 'total' => count($items)];
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
                    ['type' => $key, 'uuid' => $objectUuid, 'error' => $e->getMessage()]
                );
                $errors[$key] = ['message' => $e->getMessage(), 'exception' => get_class($e)];
            }//end try
        }//end foreach

        if (empty($errors) === false) {
            $relations['_errors'] = $errors;
        }

        return $relations;
    }//end gatherRelations()

    /**
     * Map of relation group keys (as returned by gatherRelations) to the
     * singular per-item type label used in timeline view.
     *
     * Replaces a naive `rtrim($type, 's')` singularisation that silently
     * mangled any type whose singular form happens to end in 's' (e.g. an
     * `addresses`/`address` pair, or a singular key like `deck` that would
     * be untouched today but is one rename away from becoming `decks`).
     *
     * @var array<string, string>
     */
    private const TIMELINE_TYPE_MAP = [
        'notes'    => 'note',
        'tasks'    => 'task',
        'emails'   => 'email',
        'events'   => 'event',
        'contacts' => 'contact',
        'deck'     => 'deck',
    ];

    /**
     * Log a per-type aggregation failure surfaced from gatherRelations().
     *
     * Uses structured context so log scrapers can group by type and object
     * uuid. The Throwable is passed via the `exception` context key so the
     * Nextcloud logger preserves the original stack trace.
     *
     * @param string     $type       The relation type that failed (notes, tasks, emails, …).
     * @param string     $objectUuid The object UUID being aggregated.
     * @param \Throwable $exception  The caught exception.
     *
     * @return void
     */
    private function logRelationFailure(string $type, string $objectUuid, \Throwable $exception): void
    {
        $this->logger->error(
            '[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
            [
                'type'      => $type,
                'uuid'      => $objectUuid,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]
        );
    }//end logRelationFailure()

    /**
     * Build a timeline view from grouped relations.
     *
     * @param array $relations Grouped relations.
     *
     * @return array Flat sorted timeline items.
     *
     * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-3
     */
    private function buildTimeline(array $relations): array
    {
        $timeline = [];

        foreach ($relations as $type => $data) {
            if (isset($data['results']) === false) {
                continue;
            }

            $singularType = (self::TIMELINE_TYPE_MAP[$type] ?? $type);

            foreach ($data['results'] as $item) {
                $item['type'] = $singularType;

                // Normalize date for sorting.
                $rawDate           = ($item['date'] ?? $item['linkedAt'] ?? null);
                $rawDate           = ($rawDate ?? $item['createdAt'] ?? $item['dtstart'] ?? null);
                $item['_sortDate'] = ($rawDate ?? $item['created'] ?? null);

                $timeline[] = $item;
            }
        }

        // Sort by date descending.
        usort(
                $timeline,
                static function (array $a, array $b): int {
                    return strcmp($b['_sortDate'] ?? '', $a['_sortDate'] ?? '');
                }
                );

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
     *
     * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
     *
     * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
     *         than caught: every call site already wraps this helper and translates it to a 404.
     *         Swallowing it here would collapse "no such object" into the same null this method
     *         returns for other reasons, which the caller could no longer tell apart.
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
