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
use OCA\OpenRegister\Controller\Support\RelationsControllerServices;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Notifications\Service\EmailServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
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
class RelationsController extends Controller {

	/**
	 * The six built-in relation types, in response order. Each entry names the
	 * injected service property, its app-availability guard method (or null),
	 * the per-object lookup method, and whether the result is wrapped as
	 * `{results, total}` (`counted`) or returned as the service shapes it.
	 *
	 * @var array<string,array{service:string,available:?string,method:string,counted:bool}>
	 */
	private const CORE_RELATIONS = [
		'notes'    => ['service' => 'noteService', 'available' => null, 'method' => 'getNotesForObject', 'counted' => true],
		'tasks'    => ['service' => 'taskService', 'available' => null, 'method' => 'getTasksForObject', 'counted' => true],
		'emails'   => ['service' => 'emailService', 'available' => 'isMailAvailable', 'method' => 'getEmailsForObject', 'counted' => false],
		'events'   => ['service' => 'calendarEventService', 'available' => null, 'method' => 'getEventsForObject', 'counted' => true],
		'contacts' => ['service' => 'contactService', 'available' => null, 'method' => 'getContactsForObject', 'counted' => false],
		'deck'     => ['service' => 'deckCardService', 'available' => 'isDeckAvailable', 'method' => 'getCardsForObject', 'counted' => false],
	];

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
		'talk' => [
			'class' => \OCA\OpenRegister\Service\TalkLinkServiceInterface::class,
			'available' => 'isTalkAvailable',
			'method' => 'getLinkedRooms',
		],
		'forms' => [
			'class' => \OCA\OpenRegister\Service\FormLinkServiceInterface::class,
			'available' => null,
			'method' => 'getLinkedForms',
		],
		'maps' => [
			'class' => \OCA\OpenRegister\Service\MapLinkServiceInterface::class,
			'available' => 'isMapsAvailable',
			'method' => 'getLinkedPois',
		],
		'polls' => [
			'class' => \OCA\OpenRegister\Service\PollLinkService::class,
			'available' => 'isPollsAvailable',
			'method' => 'getLinkedPolls',
		],
		'bookmarks' => [
			'class' => \OCA\OpenRegister\Service\BookmarkLinkService::class,
			'available' => 'isBookmarksAvailable',
			'method' => 'getLinkedBookmarks',
		],
		'collectives' => [
			'class' => \OCA\OpenRegister\Service\CollectiveLinkService::class,
			'available' => 'isCollectivesAvailable',
			'method' => 'getLinkedPages',
		],
		'photos' => [
			'class' => \OCA\OpenRegister\Service\PhotoLinkService::class,
			'available' => 'isPhotosAvailable',
			'method' => 'getLinkedAlbums',
		],
		'cospend' => [
			'class' => \OCA\OpenRegister\Service\CospendLinkServiceInterface::class,
			'available' => 'isCospendAvailable',
			'method' => 'getLinkedEntries',
		],
		'timetracker' => [
			'class' => \OCA\OpenRegister\Service\TimeTrackerLinkServiceInterface::class,
			'available' => 'isTimeManagerAvailable',
			'method' => 'getLinkedEntries',
		],
		'analytics' => [
			'class' => \OCA\OpenRegister\Service\AnalyticsLinkService::class,
			'available' => 'isAnalyticsAvailable',
			'method' => 'getLinkedReports',
		],
		'flow' => [
			'class' => \OCA\OpenRegister\Service\FlowLinkServiceInterface::class,
			'available' => 'isFlowAvailable',
			'method' => 'getLinkedOperations',
		],
		'openproject' => [
			'class' => \OCA\OpenRegister\Service\OpenProjectLinkServiceInterface::class,
			'available' => 'isOpenConnectorAvailable',
			'method' => 'getLinkedWorkPackages',
		],
		'xwiki' => [
			'class' => \OCA\OpenRegister\Service\XwikiLinkService::class,
			'available' => 'isOpenConnectorAvailable',
			'method' => 'getLinkedPages',
		],
	];

	/**
	 * Object service.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Note service.
	 *
	 * @var NoteServiceInterface
	 */
	private readonly NoteServiceInterface $noteService;

	/**
	 * Task service.
	 *
	 * @var ObjectCalendarTasksInterface
	 */
	private readonly ObjectCalendarTasksInterface $taskService;

	/**
	 * Email service.
	 *
	 * @var EmailServiceInterface
	 */
	private readonly EmailServiceInterface $emailService;

	/**
	 * Calendar event service.
	 *
	 * @var ObjectCalendarEventsInterface
	 */
	private readonly ObjectCalendarEventsInterface $calendarEventService;

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
	 * App container the pluggable leaf link services are resolved from.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request
	 * @param RelationsControllerServices $services The relations controller services.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		RelationsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->objectService = $services->records->objectService;
		$this->noteService = $services->records->noteService;
		$this->taskService = $services->records->taskService;
		$this->emailService = $services->records->emailService;
		$this->calendarEventService = $services->collaboration->calendarEventService;
		$this->contactService = $services->collaboration->contactService;
		$this->deckCardService = $services->collaboration->deckCardService;
		$this->logger = $services->platform->logger;
		$this->container = $services->platform->container;
	}//end __construct()

	/**
	 * Get all relations for an object.
	 *
	 * Supports filtering with ?types=emails,contacts
	 * and timeline view with ?view=timeline
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse 200 with the relations, 404 when the object does not exist,
	 *                      409 when the identifier is a slug two objects of this register
	 *                      and schema hold (#4037).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$params = $this->request->getParams();
			$objectUuid = $object->getUuid();
			$view = $params['view'] ?? null;
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
		} catch (MultipleObjectsReturnedException $e) {
			// Same body as ObjectsController: neither object is returned and the uuids stay in the log.
			return new JSONResponse(
				data: [
					'message' => sprintf('Identifier "%s" matches more than one object in this register and schema', $id),
					'error' => 'ambiguous-identifier',
				],
				statusCode: Http::STATUS_CONFLICT
			);
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
	 * @param string $objectUuid The object UUID.
	 * @param array|null $typesFilter Types to include, or null for all.
	 *
	 * @return array Relations grouped by type. When one or more per-type
	 *               lookups fail, the result also carries an `_errors` map
	 *               keyed by type with `{message, exception}` entries.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-2
	 */
	private function gatherRelations(string $objectUuid, ?array $typesFilter): array {
		$relations = [];
		$errors    = [];
		$lookups   = array_merge(array_keys(self::CORE_RELATIONS), array_keys(self::LEAF_INTEGRATIONS));
		foreach ($lookups as $key) {
			if ($typesFilter !== null && in_array($key, $typesFilter) === false) {
				continue;
			}

			$outcome = $this->relationOutcome(key: $key, objectUuid: $objectUuid);

			if (array_key_exists('relation', $outcome) === true) {
				$relations[$key] = $outcome['relation'];
			}

			if (isset($outcome['error']) === true) {
				$errors[$key] = $outcome['error'];
			}
		}

		if (empty($errors) === false) {
			$relations['_errors'] = $errors;
		}

		return $relations;
	}//end gatherRelations()

	/**
	 * Look up one relation type of an object, built in or leaf integration.
	 *
	 * @param string $key        The relation type.
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{relation?: mixed, error?: array{message: string, exception: string}} The outcome, empty when skipped.
	 */
	private function relationOutcome(string $key, string $objectUuid): array {
		if (isset(self::CORE_RELATIONS[$key]) === true) {
			return $this->coreRelation(key: $key, objectUuid: $objectUuid);
		}

		return $this->leafRelation(key: $key, objectUuid: $objectUuid);
	}//end relationOutcome()

	/**
	 * Look up one built-in relation type of an object.
	 *
	 * The Mail and Deck types are skipped when their app is not available. A user who
	 * never opened Calendar or Tasks has no VTODO calendar, so has no tasks on this
	 * object either: that is a normal state, not a failure (#4165), as in
	 * TasksController::index().
	 *
	 * @param string $key        The relation type, a key of CORE_RELATIONS.
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{relation?: mixed, error?: array{message: string, exception: string}} The outcome, empty when skipped.
	 */
	private function coreRelation(string $key, string $objectUuid): array {
		$spec    = self::CORE_RELATIONS[$key];
		$service = $this->{$spec['service']};
		if ($spec['available'] !== null && $service->{$spec['available']}() !== true) {
			return [];
		}

		try {
			$items = $service->{$spec['method']}($objectUuid);
			if ($spec['counted'] === true) {
				$items = ['results' => $items, 'total' => count($items)];
			}

			return ['relation' => $items];
		} catch (NoVtodoCalendarException $e) {
			if ($key === 'tasks') {
				return ['relation' => ['results' => [], 'total' => 0]];
			}

			$this->logRelationFailure(type: $key, objectUuid: $objectUuid, exception: $e);
			return ['error' => ['message' => $e->getMessage(), 'exception' => get_class($e)]];
		} catch (Exception $e) {
			$this->logRelationFailure(type: $key, objectUuid: $objectUuid, exception: $e);
			return ['error' => ['message' => $e->getMessage(), 'exception' => get_class($e)]];
		}//end try
	}//end coreRelation()

	/**
	 * Look up one pluggable leaf integration of an object (ADR-022).
	 *
	 * The owning app's link service is resolved lazily through the server container.
	 * Resolving it is NOT part of the lookup: an app that is absent or disabled makes
	 * the container throw or hand back null, and the type is then silently skipped, so
	 * no `_errors` key appears on a response whose requested lookups all succeeded. Only
	 * a failure of an AVAILABLE service's own call is an error worth surfacing.
	 *
	 * @param string $key        The relation type, a key of LEAF_INTEGRATIONS.
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{relation?: array, error?: array{message: string, exception: string}} The outcome, empty when skipped.
	 */
	private function leafRelation(string $key, string $objectUuid): array {
		$spec = self::LEAF_INTEGRATIONS[$key];
		try {
			$service = $this->container->get($spec['class']);
		} catch (\Throwable $e) {
			return [];
		}

		if (is_object($service) === false) {
			return [];
		}

		try {
			if ($spec['available'] !== null && $service->{$spec['available']}() !== true) {
				return [];
			}

			$items = $service->{$spec['method']}($objectUuid);
			return ['relation' => ['results' => $items, 'total' => count($items)]];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
				['type' => $key, 'uuid' => $objectUuid, 'error' => $e->getMessage()]
			);
			return ['error' => ['message' => $e->getMessage(), 'exception' => get_class($e)]];
		}//end try
	}//end leafRelation()

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
		'notes' => 'note',
		'tasks' => 'task',
		'emails' => 'email',
		'events' => 'event',
		'contacts' => 'contact',
		'deck' => 'deck',
	];

	/**
	 * Log a per-type aggregation failure surfaced from gatherRelations().
	 *
	 * Uses structured context so log scrapers can group by type and object
	 * uuid. The Throwable is passed via the `exception` context key so the
	 * Nextcloud logger preserves the original stack trace.
	 *
	 * @param string $type The relation type that failed (notes, tasks, emails, …).
	 * @param string $objectUuid The object UUID being aggregated.
	 * @param \Throwable $exception The caught exception.
	 *
	 * @return void
	 */
	private function logRelationFailure(string $type, string $objectUuid, \Throwable $exception): void {
		$this->logger->error(
			'[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
			[
				'type' => $type,
				'uuid' => $objectUuid,
				'error' => $exception->getMessage(),
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
	private function buildTimeline(array $relations): array {
		$timeline = [];

		foreach ($relations as $type => $data) {
			if (isset($data['results']) === false) {
				continue;
			}

			$singularType = (self::TIMELINE_TYPE_MAP[$type] ?? $type);

			foreach ($data['results'] as $item) {
				$item['type'] = $singularType;

				// Normalize date for sorting.
				$rawDate = ($item['date'] ?? $item['linkedAt'] ?? null);
				$rawDate = ($rawDate ?? $item['createdAt'] ?? $item['dtstart'] ?? null);
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
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(
		string $register,
		string $schema,
		string $id,
	): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
