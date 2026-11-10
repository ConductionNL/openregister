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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\RelationsCollaboration;
use OCA\OpenRegister\Controller\Support\RelationsControllerServices;
use OCA\OpenRegister\Controller\Support\RelationsObjectLookup;
use OCA\OpenRegister\Controller\Support\RelationTypeLookup;
use OCA\OpenRegister\Controller\Support\RelationsRecords;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
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
 */
class RelationsController extends Controller {

	/**
	 * The six built-in relation types, in response order. Each entry names the
	 * services bundle and the service in it, its app-availability guard method
	 * (or null), the per-object lookup method, and whether the result is wrapped
	 * as `{results, total}` (`counted`) or returned as the service shapes it.
	 *
	 * @var array<string,array{group:string,service:string,available:?string,method:string,counted:bool}>
	 */
	private const CORE_RELATIONS = [
		'notes' => [
			'group' => 'records',
			'service' => 'noteService',
			'available' => null,
			'method' => 'getNotesForObject',
			'counted' => true,
		],
		'tasks' => [
			'group' => 'records',
			'service' => 'taskService',
			'available' => null,
			'method' => 'getTasksForObject',
			'counted' => true,
		],
		'emails' => [
			'group' => 'records',
			'service' => 'emailService',
			'available' => 'isMailAvailable',
			'method' => 'getEmailsForObject',
			'counted' => false,
		],
		'events' => [
			'group' => 'collaboration',
			'service' => 'calendarEventService',
			'available' => null,
			'method' => 'getEventsForObject',
			'counted' => true,
		],
		'contacts' => [
			'group' => 'collaboration',
			'service' => 'contactService',
			'available' => null,
			'method' => 'getContactsForObject',
			'counted' => false,
		],
		'deck' => [
			'group' => 'collaboration',
			'service' => 'deckCardService',
			'available' => 'isDeckAvailable',
			'method' => 'getCardsForObject',
			'counted' => false,
		],
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
			'class' => \OCA\OpenRegister\Service\PollLinkServiceInterface::class,
			'available' => 'isPollsAvailable',
			'method' => 'getLinkedPolls',
		],
		'bookmarks' => [
			'class' => \OCA\OpenRegister\Service\BookmarkLinkServiceInterface::class,
			'available' => 'isBookmarksAvailable',
			'method' => 'getLinkedBookmarks',
		],
		'collectives' => [
			'class' => \OCA\OpenRegister\Service\CollectiveLinkServiceInterface::class,
			'available' => 'isCollectivesAvailable',
			'method' => 'getLinkedPages',
		],
		'photos' => [
			'class' => \OCA\OpenRegister\Service\PhotoLinkServiceInterface::class,
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
			'class' => \OCA\OpenRegister\Service\AnalyticsLinkServiceInterface::class,
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
			'class' => \OCA\OpenRegister\Service\XwikiLinkServiceInterface::class,
			'available' => 'isOpenConnectorAvailable',
			'method' => 'getLinkedPages',
		],
	];

	/**
	 * The object, note, task and email services.
	 *
	 * @var RelationsRecords
	 */
	private readonly RelationsRecords $records;

	/**
	 * The calendar event, contact and deck card services.
	 *
	 * @var RelationsCollaboration
	 */
	private readonly RelationsCollaboration $collaboration;

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
	 * Resolves the object the route names, through the object service.
	 *
	 * @var RelationsObjectLookup
	 */
	private readonly RelationsObjectLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param RelationsControllerServices $services The relations controller services.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 */
	public function __construct(
		IRequest $request,
		RelationsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->records = $services->records;
		$this->collaboration = $services->collaboration;
		$this->logger = $services->platform->logger;
		$this->container = $services->platform->container;
		$this->lookup = new RelationsObjectLookup(objects: $services->records->objectService);
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
		return $this->indexForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * index() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse 200 with the relations, 404 when the object does not exist,
	 */
	private function indexForObject(ObjectRef $ref): JSONResponse {
		try {
			// A DoesNotExistException from the lookup is deliberately not caught
			// there: "no such object" stays distinguishable from a null read.
			$object = $this->lookup->find(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			return $this->relationsOf(objectUuid: $object->getUuid());
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->ambiguousIdentifier(id: $ref->id);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end indexForObject()

	/**
	 * The relations of a resolved object, grouped or as a timeline as the request asks.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return JSONResponse The relations.
	 */
	private function relationsOf(string $objectUuid): JSONResponse {
		$params = $this->request->getParams();
		$relations = $this->gatherRelations(objectUuid: $objectUuid, typesFilter: $this->typesFilter(params: $params));

		if (($params['view'] ?? null) === 'timeline') {
			return new JSONResponse($this->buildTimeline(relations: $relations));
		}

		return new JSONResponse($relations);
	}//end relationsOf()

	/**
	 * The relation types the request names in `types`, or null for all.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<int, string>|null The types.
	 */
	private function typesFilter(array $params): ?array {
		if (empty($params['types']) === true) {
			return null;
		}

		return array_map('trim', explode(',', $params['types']));
	}//end typesFilter()

	/**
	 * The 409 for a slug two objects of this register and schema hold.
	 *
	 * Same body as ObjectsController: neither object is returned and the uuids stay in the log.
	 *
	 * @param string $id The identifier as the caller sent it.
	 *
	 * @return JSONResponse The 409.
	 */
	private function ambiguousIdentifier(string $id): JSONResponse {
		$message = sprintf('Identifier "%s" matches more than one object in this register and schema', $id);

		return new JSONResponse(
			data: ['message' => $message, 'error' => 'ambiguous-identifier'],
			statusCode: Http::STATUS_CONFLICT
		);
	}//end ambiguousIdentifier()

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
		foreach ($this->wantedTypes(typesFilter: $typesFilter) as $key) {
			$outcome = $this->relationOutcome(key: $key, objectUuid: $objectUuid);

			if (array_key_exists('relation', $outcome) === true) {
				$relations[$key] = $outcome['relation'];
			}

			if (isset($outcome['error']) === true) {
				$errors[$key] = $outcome['error'];
			}
		}

		return self::withErrors(relations: $relations, errors: $errors);
	}//end gatherRelations()

	/**
	 * The relations with the failed lookups under `_errors`, when there are any.
	 *
	 * @param array<string, mixed> $relations The relations that were found.
	 * @param array<string, mixed> $errors    The failures, by type.
	 *
	 * @return array<string, mixed> The relations.
	 */
	private static function withErrors(array $relations, array $errors): array {
		if (empty($errors) === false) {
			$relations['_errors'] = $errors;
		}

		return $relations;
	}//end withErrors()

	/**
	 * The relation types to look up, built in first, in response order.
	 *
	 * @param array|null $typesFilter Types to include, or null for all.
	 *
	 * @return array<int, string> The types.
	 */
	private function wantedTypes(?array $typesFilter): array {
		$lookups = array_merge(array_keys(self::CORE_RELATIONS), array_keys(self::LEAF_INTEGRATIONS));
		if ($typesFilter === null) {
			return $lookups;
		}

		return array_values(array_filter($lookups, static fn (string $key): bool => in_array($key, $typesFilter) === true));
	}//end wantedTypes()

	/**
	 * Look up one relation type of an object, built in or leaf integration.
	 *
	 * @param string $key        The relation type.
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{relation?: mixed, error?: array{message: string, exception: string}} The outcome, empty when skipped.
	 */
	private function relationOutcome(string $key, string $objectUuid): array {
		$lookup = new RelationTypeLookup(key: $key, objectUuid: $objectUuid);
		if (isset(self::CORE_RELATIONS[$key]) === true) {
			return $this->coreRelation(lookup: $lookup);
		}

		return $this->leafRelation(lookup: $lookup);
	}//end relationOutcome()

	/**
	 * Look up one built-in relation type of an object.
	 *
	 * The Mail and Deck types are skipped when their app is not available. A user who
	 * never opened Calendar or Tasks has no VTODO calendar, so has no tasks on this
	 * object either: that is a normal state, not a failure (#4165), as in
	 * TasksController::index().
	 *
	 * @param RelationTypeLookup $lookup The relation type, a key of CORE_RELATIONS, and the object UUID.
	 *
	 * @return array{relation?: mixed, error?: array{message: string, exception: string}} The outcome, empty when skipped.
	 */
	private function coreRelation(RelationTypeLookup $lookup): array {
		$spec    = self::CORE_RELATIONS[$lookup->key];
		$service = $this->{$spec['group']}->{$spec['service']};
		if ($spec['available'] !== null && $service->{$spec['available']}() !== true) {
			return [];
		}

		try {
			return ['relation' => $this->coreItems(service: $service, lookup: $lookup)];
		} catch (NoVtodoCalendarException $e) {
			return $this->noVtodoOutcome(lookup: $lookup, exception: $e);
		} catch (Exception $e) {
			return $this->coreFailure(lookup: $lookup, exception: $e);
		}
	}//end coreRelation()

	/**
	 * The items one built-in service holds for an object, wrapped when the type is counted.
	 *
	 * @param object             $service The service.
	 * @param RelationTypeLookup $lookup  The relation type, a key of CORE_RELATIONS, and the object UUID.
	 *
	 * @return mixed The relation.
	 */
	private function coreItems(object $service, RelationTypeLookup $lookup): mixed {
		$spec  = self::CORE_RELATIONS[$lookup->key];
		$items = $service->{$spec['method']}($lookup->objectUuid);
		if ($spec['counted'] === true) {
			$items = ['results' => $items, 'total' => count($items)];
		}

		return $items;
	}//end coreItems()

	/**
	 * The outcome of a type whose lookup found no VTODO calendar: no tasks for `tasks`, a failure otherwise.
	 *
	 * @param RelationTypeLookup       $lookup    The relation type and the object UUID.
	 * @param NoVtodoCalendarException $exception The exception.
	 *
	 * @return array{relation?: mixed, error?: array{message: string, exception: string}} The outcome.
	 */
	private function noVtodoOutcome(RelationTypeLookup $lookup, NoVtodoCalendarException $exception): array {
		if ($lookup->key === 'tasks') {
			return ['relation' => ['results' => [], 'total' => 0]];
		}

		return $this->coreFailure(lookup: $lookup, exception: $exception);
	}//end noVtodoOutcome()

	/**
	 * Log a failed built-in lookup and answer it as an `_errors` entry.
	 *
	 * @param RelationTypeLookup $lookup    The relation type and the object UUID.
	 * @param \Throwable         $exception The failure.
	 *
	 * @return array{error: array{message: string, exception: string}} The outcome.
	 */
	private function coreFailure(RelationTypeLookup $lookup, \Throwable $exception): array {
		$this->logRelationFailure(lookup: $lookup, exception: $exception);

		return self::errorOutcome(exception: $exception);
	}//end coreFailure()

	/**
	 * A failure as the `_errors` entry the response carries for it.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return array{error: array{message: string, exception: string}} The outcome.
	 */
	private static function errorOutcome(\Throwable $exception): array {
		return ['error' => ['message' => $exception->getMessage(), 'exception' => get_class($exception)]];
	}//end errorOutcome()

	/**
	 * Look up one pluggable leaf integration of an object (ADR-022).
	 *
	 * The owning app's link service is resolved lazily through the server container.
	 * Resolving it is NOT part of the lookup: an app that is absent or disabled makes
	 * the container throw or hand back null, and the type is then silently skipped, so
	 * no `_errors` key appears on a response whose requested lookups all succeeded. Only
	 * a failure of an AVAILABLE service's own call is an error worth surfacing.
	 *
	 * @param RelationTypeLookup $lookup The relation type, a key of LEAF_INTEGRATIONS, and the object UUID.
	 *
	 * @return array{relation?: array, error?: array{message: string, exception: string}} The outcome, empty when skipped.
	 */
	private function leafRelation(RelationTypeLookup $lookup): array {
		$spec = self::LEAF_INTEGRATIONS[$lookup->key];
		$service = $this->leafService(class: $spec['class']);
		if ($service === null) {
			return [];
		}

		try {
			return $this->leafItems(service: $service, lookup: $lookup);
		} catch (\Throwable $e) {
			return $this->leafFailure(lookup: $lookup, exception: $e);
		}
	}//end leafRelation()

	/**
	 * What one leaf link service holds for an object, or nothing when its app reports itself unavailable.
	 *
	 * @param object             $service The service.
	 * @param RelationTypeLookup $lookup  The relation type, a key of LEAF_INTEGRATIONS, and the object UUID.
	 *
	 * @return array{relation?: array} The outcome, empty when skipped.
	 */
	private function leafItems(object $service, RelationTypeLookup $lookup): array {
		$spec = self::LEAF_INTEGRATIONS[$lookup->key];
		if ($spec['available'] !== null && $service->{$spec['available']}() !== true) {
			return [];
		}

		$items = $service->{$spec['method']}($lookup->objectUuid);
		return ['relation' => ['results' => $items, 'total' => count($items)]];
	}//end leafItems()

	/**
	 * The leaf link service the container holds, or null when the app is absent or disabled.
	 *
	 * @param string $class The service's interface.
	 *
	 * @return object|null The service.
	 */
	private function leafService(string $class): ?object {
		try {
			$service = $this->container->get($class);
		} catch (\Throwable $e) {
			$service = null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end leafService()

	/**
	 * Log a failed leaf lookup as a warning and answer it as an `_errors` entry.
	 *
	 * @param RelationTypeLookup $lookup    The relation type and the object UUID.
	 * @param \Throwable         $exception The failure.
	 *
	 * @return array{error: array{message: string, exception: string}} The outcome.
	 */
	private function leafFailure(RelationTypeLookup $lookup, \Throwable $exception): array {
		$this->logger->warning(
			'[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
			['type' => $lookup->key, 'uuid' => $lookup->objectUuid, 'error' => $exception->getMessage()]
		);

		return self::errorOutcome(exception: $exception);
	}//end leafFailure()

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
	 * @param RelationTypeLookup $lookup    The relation type that failed (notes, tasks, emails, …) and the object UUID being aggregated.
	 * @param \Throwable         $exception The caught exception.
	 *
	 * @return void
	 */
	private function logRelationFailure(RelationTypeLookup $lookup, \Throwable $exception): void {
		$this->logger->error(
			'[RelationsController::gatherRelations] {type} lookup failed for object {uuid}: {error}',
			[
				'type' => $lookup->key,
				'uuid' => $lookup->objectUuid,
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
				$item['_sortDate'] = self::sortDate(item: $item);
				$timeline[] = $item;
			}
		}

		return self::newestFirst(timeline: $timeline);
	}//end buildTimeline()

	/**
	 * The date a timeline item sorts on, whichever field its type carries it in.
	 *
	 * @param array<string, mixed> $item The item.
	 *
	 * @return mixed The date, or null when it carries none.
	 */
	private static function sortDate(array $item): mixed {
		$rawDate = ($item['date'] ?? $item['linkedAt'] ?? null);
		$rawDate = ($rawDate ?? $item['createdAt'] ?? $item['dtstart'] ?? null);

		return ($rawDate ?? $item['created'] ?? null);
	}//end sortDate()

	/**
	 * Timeline items sorted by date descending, without their sort key.
	 *
	 * @param array<int, array<string, mixed>> $timeline The items, each with `_sortDate`.
	 *
	 * @return array<int, array<string, mixed>> The sorted items.
	 */
	private static function newestFirst(array $timeline): array {
		usort(
			$timeline,
			static function (array $a, array $b): int {
				return strcmp($b['_sortDate'] ?? '', $a['_sortDate'] ?? '');
			}
		);

		foreach ($timeline as $index => $item) {
			unset($item['_sortDate']);
			$timeline[$index] = $item;
		}

		return $timeline;
	}//end newestFirst()
}//end class
