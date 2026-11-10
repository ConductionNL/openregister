<?php

/**
 * TimelineEntriesController
 *
 * The timeline entry as a record: written with a kind and its fields, read
 * pinned first, pinned and unpinned, its follow-up closed, its raw inbound
 * source read, and searched across objects.
 *
 * WHY THIS SITS BESIDE THE NOTES ENDPOINT AND DOES NOT REPLACE IT. The notes
 * endpoint answers the shape every leaf app already renders, and this change
 * does not move anybody's floor. A note written there still becomes a record,
 * so it is searchable; a caller that wants a kind, fields, a pin or a
 * follow-up comes here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Notifications\Controller\Support\TimelineEntriesControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryPin;
use OCA\OpenRegister\Notifications\Service\Timeline\EntrySearch;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Timeline entries on an object, and the search across them.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Six routed actions on one
 * sub-resource, each with its own refusal path, plus the three private
 * resolvers they share. Splitting it would put the entry's read guard in one
 * class and its write guard in another, which is how the two drift apart.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineEntriesController extends Controller {

	/**
	 * Resolves an object through the RBAC boundary.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objects;

	/**
	 * The record.
	 *
	 * @var TimelineEntryService
	 */
	private readonly TimelineEntryService $entries;

	/**
	 * Writing an entry end to end.
	 *
	 * @var TimelineWriteService
	 */
	private readonly TimelineWriteService $writer;

	/**
	 * Entries searched across objects.
	 *
	 * @var TimelineEntrySearchService
	 */
	private readonly TimelineEntrySearchService $search;

	/**
	 * What an entry points at.
	 *
	 * @var ReferenceService
	 */
	private readonly ReferenceService $references;

	/**
	 * The internal or public flag and who may set it.
	 *
	 * @var TimelineVisibilityService
	 */
	private readonly TimelineVisibilityService $visibility;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request    Request.
	 * @param TimelineEntriesControllerServices $services The timeline entries controller services.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		TimelineEntriesControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->objects = $services->domain->objects;
		$this->entries = $services->domain->entries;
		$this->writer = $services->domain->writer;
		$this->search = $services->domain->search;
		$this->references = $services->visibility->references;
		$this->visibility = $services->visibility->visibility;
		$this->logger = $services->visibility->logger;
	}//end __construct()

	/**
	 * One object's timeline, pinned first.
	 *
	 * A caller without `update` on the object is served the public view even
	 * when it asks for none, exactly as the notes endpoint does: a filter a
	 * caller can drop by omitting a parameter is no filter at all.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse The entries.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->entriesAt(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
	}//end index()

	/**
	 * The timeline of the object at a route path.
	 *
	 * @param array<string, string> $path The register, schema and object id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function entriesAt(array $path): JSONResponse {
		$object = $this->resolveObject(path: $path);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->listOn(object: $object);
	}//end entriesAt()

	/**
	 * The entries of a resolved object the caller may see, in the visibility they asked for.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The entries.
	 */
	private function listOn(ObjectEntity $object): JSONResponse {
		$params = $this->request->getParams();
		$requested = null;
		if (isset($params['visibility']) === true && is_string($params['visibility']) === true) {
			$requested = $params['visibility'];
		}

		$filter = $this->visibility->effectiveFilter(object: $object, requested: $requested);

		try {
			$entries = $this->entries->listForObject(
				object: $object,
				query: new ListQuery(limit: (int)($params['limit'] ?? 50), offset: (int)($params['offset'] ?? 0), filters: ['visibility' => $filter])
			);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'index');
		}

		$results = [];
		foreach ($entries as $entry) {
			$results[] = $entry->jsonSerialize();
		}

		return new JSONResponse(
			[
				'results' => $results,
				'total' => count($results),
				'visibility' => $filter,
				'canManage' => $this->visibility->mayManage(object: $object),
			]
		);
	}//end listOn()

	/**
	 * Write an entry.
	 *
	 * `relatedObjects` writes the same note onto several objects at once. Every
	 * one of them is resolved before anything is written, so an object the
	 * author may not reach is a 403 naming it rather than a note that landed
	 * on three of the four objects they asked for.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse The entry, or the entries.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->createAt(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
	}//end create()

	/**
	 * Write an entry on the object at a route path.
	 *
	 * @param array<string, string> $path The register, schema and object id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function createAt(array $path): JSONResponse {
		$object = $this->resolveObject(path: $path);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->writeOn(object: $object, scope: ['register' => $path['register'], 'schema' => $path['schema']]);
	}//end createAt()

	/**
	 * Write the requested entry onto a resolved object and every related object.
	 *
	 * Every related object is resolved before anything is written.
	 *
	 * @param ObjectEntity          $object The object.
	 * @param array<string, string> $scope  The register and schema as the caller named them.
	 *
	 * @return JSONResponse The entry, or the entries.
	 */
	private function writeOn(ObjectEntity $object, array $scope): JSONResponse {
		$data = $this->request->getParams();
		$data['register'] = $scope['register'];
		$data['schema'] = $scope['schema'];

		$related = $this->resolveRelated(data: $data);
		if ($related instanceof JSONResponse) {
			return $related;
		}

		return $this->written(objects: array_merge([$object], $related), data: $data);
	}//end writeOn()

	/**
	 * Write one entry onto one object, or the same entry onto several.
	 *
	 * @param array<int, ObjectEntity> $objects The object first, then the related objects.
	 * @param array<string, mixed>     $data    The entry.
	 *
	 * @return JSONResponse The entry (one object) or the entries (several), or an error.
	 */
	private function written(array $objects, array $data): JSONResponse {
		try {
			if (count($objects) === 1) {
				$entry = $this->writer->write(object: $objects[0], data: $data);

				return new JSONResponse($entry->jsonSerialize(), Http::STATUS_CREATED);
			}

			$entries = $this->writer->writeToMany(objects: $objects, data: $data);
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'create');
		}

		$results = [];
		foreach ($entries as $entry) {
			$results[] = $entry->jsonSerialize();
		}

		return new JSONResponse(['results' => $results], Http::STATUS_CREATED);
	}//end written()

	/**
	 * One entry, with what its text points at.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 * @param string $entryId  Entry uuid.
	 *
	 * @return JSONResponse The entry.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $id, string $entryId): JSONResponse {
		return $this->showAt(path: ['register' => $register, 'schema' => $schema, 'id' => $id], entryId: $entryId);
	}//end show()

	/**
	 * One entry of the object at a route path.
	 *
	 * @param array<string, string> $path    The register, schema and object id from the route.
	 * @param string                $entryId The entry id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function showAt(array $path, string $entryId): JSONResponse {
		$resolved = $this->resolveEntry(path: $path, entryId: $entryId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		return $this->shown(object: $resolved[0], entry: $resolved[1]);
	}//end showAt()

	/**
	 * An entry the caller may read, with what its text points at.
	 *
	 * @param ObjectEntity  $object The object.
	 * @param TimelineEntry $entry  The entry on it.
	 *
	 * @return JSONResponse The entry, or 404 for an entry the caller may not read.
	 */
	private function shown(ObjectEntity $object, TimelineEntry $entry): JSONResponse {
		if ($this->mayRead(object: $object, visibility: (string)$entry->getVisibility()) === false) {
			return new JSONResponse(['message' => 'Entry not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = $entry->jsonSerialize();
		$payload['references'] = array_map(
			static fn ($reference) => $reference->jsonSerialize(),
			$this->references->forEntry(entryUuid: (string)$entry->getUuid())
		);

		return new JSONResponse($payload);
	}//end shown()

	/**
	 * Pin an entry, unpin it, or close its follow-up.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 * @param string $entryId  Entry uuid.
	 *
	 * @return JSONResponse The written entry.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function update(string $register, string $schema, string $id, string $entryId): JSONResponse {
		return $this->updateAt(path: ['register' => $register, 'schema' => $schema, 'id' => $id], entryId: $entryId);
	}//end update()

	/**
	 * Update one entry of the object at a route path.
	 *
	 * @param array<string, string> $path    The register, schema and object id from the route.
	 * @param string                $entryId The entry id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function updateAt(array $path, string $entryId): JSONResponse {
		$resolved = $this->resolveEntry(path: $path, entryId: $entryId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		return $this->updatedOn(object: $resolved[0], entry: $resolved[1]);
	}//end updateAt()

	/**
	 * Apply the requested pin and follow-up change to an entry.
	 *
	 * @param ObjectEntity  $object The object.
	 * @param TimelineEntry $entry  The entry on it.
	 *
	 * @return JSONResponse The written entry, or an error.
	 */
	private function updatedOn(ObjectEntity $object, TimelineEntry $entry): JSONResponse {
		$data = $this->request->getParams();

		try {
			$entry = $this->applyUpdate(object: $object, change: ['entry' => $entry, 'data' => $data]);
		} catch (TimelinePermissionException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'update');
		}

		return new JSONResponse($entry->jsonSerialize());
	}//end updatedOn()

	/**
	 * Pin or unpin the entry when the request names `pinned`, then close its
	 * follow-up when the request says `followUp: done`.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $change The entry (`entry`) and the request data (`data`).
	 *
	 * @return TimelineEntry The written entry.
	 *
	 * @throws TimelinePermissionException When the caller may not manage the object.
	 * @throws TimelineValidationException When the entry carries no follow-up.
	 */
	private function applyUpdate(ObjectEntity $object, array $change): TimelineEntry {
		$entry = $change['entry'];
		$data = $change['data'];
		if (array_key_exists('pinned', $data) === true) {
			$entry = $this->entries->pin(
				object: $object,
				pin: new EntryPin(entry: $entry, pinned: ($data['pinned'] !== false && $data['pinned'] !== 'false'))
			);
		}

		if (($data['followUp'] ?? null) === 'done') {
			$entry = $this->entries->closeFollowUp(entryObject: $object, entry: $entry);
		}

		return $entry;
	}//end applyUpdate()

	/**
	 * The raw inbound message and its headers.
	 *
	 * A rendered message cannot prove when it arrived; the headers can. The
	 * access is the entry's own: a reader who may read the entry may read what
	 * it was made from, and nobody else can.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 * @param string $entryId  Entry uuid.
	 *
	 * @return JSONResponse The source.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function source(string $register, string $schema, string $id, string $entryId): JSONResponse {
		return $this->sourceAt(path: ['register' => $register, 'schema' => $schema, 'id' => $id], entryId: $entryId);
	}//end source()

	/**
	 * The source of one entry of the object at a route path.
	 *
	 * @param array<string, string> $path    The register, schema and object id from the route.
	 * @param string                $entryId The entry id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function sourceAt(array $path, string $entryId): JSONResponse {
		$resolved = $this->resolveEntry(path: $path, entryId: $entryId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		return $this->sourceOf(object: $resolved[0], entry: $resolved[1]);
	}//end sourceAt()

	/**
	 * The raw inbound message of an entry the caller may read.
	 *
	 * @param ObjectEntity  $object The object.
	 * @param TimelineEntry $entry  The entry on it.
	 *
	 * @return JSONResponse The source, or 404.
	 */
	private function sourceOf(ObjectEntity $object, TimelineEntry $entry): JSONResponse {
		if ($this->mayRead(object: $object, visibility: (string)$entry->getVisibility()) === false) {
			return new JSONResponse(['message' => 'Entry not found'], Http::STATUS_NOT_FOUND);
		}

		$source = $this->entries->sourceFor(entry: $entry);
		if ($source['source'] === null) {
			return new JSONResponse(['message' => 'This entry was not made from an inbound message'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($source);
	}//end sourceOf()

	/**
	 * Entries matching a term, across every object the caller may read.
	 *
	 * This is the Woo path: a subject named in fifty timelines is fifty hits,
	 * each naming its case.
	 *
	 * @return JSONResponse The hits.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Takes a TERM, never an object id, so there is no
	 * caller-supplied id to scope. The per-object predicate is enforced two hops
	 * out, which is why it is not visible in this body:
	 * TimelineEntrySearchService::objectFor() resolves EVERY hit's object through
	 * ObjectService::find() with `_rbac: true` and `_multitenancy: true` — the
	 * same read every other caller of an object goes through — and drops the hit
	 * when that read refuses or answers null. The entry's internal or public flag
	 * is a second, independent narrowing applied as a condition in the statement.
	 * Asserted in TimelineEntrySearchServiceTest::testAnEntryOnAnUnreadableObjectIsAbsent
	 * and ::testAReadThatThrowsIsARefusalRatherThanAFailedSearch.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	#[NoAdminRequired]
	public function searchEntries(): JSONResponse {
		$params = $this->request->getParams();
		$term = $this->text(params: $params, key: 'q');
		if ($term === null) {
			return new JSONResponse(['message' => 'A search needs a term'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$results = $this->search->findHitsAsArrays(
				search: new EntrySearch(
					term: $term,
					visibility: $this->askedVisibility(params: $params),
					kind: $this->text(params: $params, key: 'kind'),
					limit: (int)($params['limit'] ?? 25)
				)
			);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'search');
		}

		return new JSONResponse(['results' => $results, 'total' => count($results)]);
	}//end searchEntries()

	/**
	 * The visibility a search asked for, when it named one this app knows.
	 *
	 * An unknown value is DROPPED rather than refused. The parameter narrows a
	 * search; a caller that misspells it gets everything they are entitled to,
	 * which is the same answer as omitting it, and refusing would turn a typo
	 * into a broken search page.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return string|null The flag, or null for no narrowing.
	 */
	private function askedVisibility(array $params): ?string {
		$visibility = $this->text(params: $params, key: 'visibility');
		if ($visibility === null) {
			return null;
		}

		if ($this->visibility->isKnownValue(value: $visibility) === false) {
			return null;
		}

		return $visibility;
	}//end askedVisibility()

	/**
	 * Read one optional, non-empty string off the query.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param string              $key    The key.
	 *
	 * @return string|null The value, or null when absent, empty or not a string.
	 */
	private function text(array $params, string $key): ?string {
		if (isset($params[$key]) === false || is_string($params[$key]) === false) {
			return null;
		}

		$value = trim($params[$key]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end text()

	/**
	 * Whether a caller may read an entry carrying this visibility.
	 *
	 * An internal entry is readable only by somebody who could have set the
	 * flag in the first place. Answering 404 rather than 403 is deliberate: a
	 * 403 on a specific entry id confirms that the entry exists.
	 *
	 * @param ObjectEntity $object     The object.
	 * @param string       $visibility The entry's flag.
	 *
	 * @return boolean True when the entry may be read.
	 */
	private function mayRead(ObjectEntity $object, string $visibility): bool {
		if ($this->visibility->normalise(value: $visibility) === TimelineVisibilityService::PUBLIC_ENTRY) {
			return true;
		}

		return $this->visibility->mayManage(object: $object);
	}//end mayRead()

	/**
	 * Resolve the objects a multi-object note also writes on.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return array<int, ObjectEntity>|JSONResponse The objects, or the refusal naming the one that failed.
	 */
	private function resolveRelated(array $data): array|JSONResponse {
		if (isset($data['relatedObjects']) === false || is_array($data['relatedObjects']) === false) {
			return [];
		}

		$related = [];
		foreach ($data['relatedObjects'] as $entry) {
			if (is_array($entry) === false) {
				return new JSONResponse(
					['message' => 'Each related object names its register, schema and id'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$resolved = $this->resolveObject(
				path: [
					'register' => (string)($entry['register'] ?? ''),
					'schema' => (string)($entry['schema'] ?? ''),
					'id' => (string)($entry['id'] ?? ''),
				]
			);
			if ($resolved instanceof JSONResponse) {
				return new JSONResponse(
					['message' => 'Related object '.(string)($entry['id'] ?? '').' could not be reached'],
					Http::STATUS_NOT_FOUND
				);
			}

			$related[] = $resolved;
		}//end foreach

		return $related;
	}//end resolveRelated()

	/**
	 * Resolve the object and the entry that must hang on it.
	 *
	 * @param array<string, string> $path    The register slug or id, schema slug or id and object uuid.
	 * @param string                $entryId Entry uuid.
	 *
	 * @return array{0: ObjectEntity, 1: \OCA\OpenRegister\Notifications\Db\TimelineEntry}|JSONResponse The pair, or the refusal.
	 */
	private function resolveEntry(array $path, string $entryId): array|JSONResponse {
		$object = $this->resolveObject(path: $path);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$entry = $this->entries->get(object: $object, entryUuid: $entryId);
		if ($entry === null) {
			return new JSONResponse(['message' => 'Entry not found'], Http::STATUS_NOT_FOUND);
		}

		return [$object, $entry];
	}//end resolveEntry()

	/**
	 * Resolve an object through the RBAC boundary.
	 *
	 * REGISTER FIRST: `setSchema()` scopes its slug lookup to whatever register
	 * is currently set on this shared service, so setting the schema first
	 * resolves it against a register left behind by an unrelated call.
	 *
	 * @param array<string, string> $path The register slug or id, schema slug or id and object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or a 404.
	 */
	private function resolveObject(array $path): ObjectEntity|JSONResponse {
		if ($path['register'] === '' || $path['schema'] === '' || $path['id'] === '') {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			// setObject() throws for an unknown object, or one the caller may not read.
			$this->objects->setRegister($path['register']);
			$this->objects->setSchema($path['schema']);
			$this->objects->setObject($path['id']);
			$object = $this->objects->getObject();
		} catch (Throwable $e) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if (($object instanceof ObjectEntity) === false) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return $object;
	}//end resolveObject()

	/**
	 * The one answer for something nobody expected.
	 *
	 * @param Throwable $exception The failure.
	 * @param string    $context   Which method it happened in.
	 *
	 * @return JSONResponse A 500 that says nothing about the instance.
	 */
	private function unexpected(Throwable $exception, string $context): JSONResponse {
		$this->logger->error(
			'[TimelineEntriesController] '.$context.' failed',
			['exception' => $exception]
		);

		return new JSONResponse(['message' => 'The timeline could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end unexpected()
}//end class
