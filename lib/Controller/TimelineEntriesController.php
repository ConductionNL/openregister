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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * Constructor.
	 *
	 * @param string                     $appName    App name.
	 * @param IRequest                   $request    Request.
	 * @param ObjectService              $objects    Resolves an object through the RBAC boundary.
	 * @param TimelineEntryService       $entries    The record.
	 * @param TimelineWriteService       $writer     Writing an entry end to end.
	 * @param TimelineEntrySearchService $search     Entries searched across objects.
	 * @param ReferenceService           $references What an entry points at.
	 * @param TimelineVisibilityService  $visibility The internal or public flag and who may set it.
	 * @param LoggerInterface            $logger     Logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objects,
		private readonly TimelineEntryService $entries,
		private readonly TimelineWriteService $writer,
		private readonly TimelineEntrySearchService $search,
		private readonly ReferenceService $references,
		private readonly TimelineVisibilityService $visibility,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$params = $this->request->getParams();
		$requested = null;
		if (isset($params['visibility']) === true && is_string($params['visibility']) === true) {
			$requested = $params['visibility'];
		}

		$filter = $this->visibility->effectiveFilter(object: $object, requested: $requested);

		try {
			$entries = $this->entries->listForObject(
				object: $object,
				visibility: $filter,
				limit: (int)($params['limit'] ?? 50),
				offset: (int)($params['offset'] ?? 0)
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
	}//end index()

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
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$data = $this->request->getParams();
		$data['register'] = $register;
		$data['schema'] = $schema;

		$related = $this->resolveRelated(data: $data);
		if ($related instanceof JSONResponse) {
			return $related;
		}

		try {
			if ($related === []) {
				$entry = $this->writer->write(object: $object, data: $data);

				return new JSONResponse($entry->jsonSerialize(), Http::STATUS_CREATED);
			}

			$entries = $this->writer->writeToMany(objects: array_merge([$object], $related), data: $data);
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'create');
		}

		$results = [];
		foreach ($entries as $written) {
			$results[] = $written->jsonSerialize();
		}

		return new JSONResponse(['results' => $results], Http::STATUS_CREATED);
	}//end create()

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
		$resolved = $this->resolveEntry(register: $register, schema: $schema, id: $id, entryId: $entryId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		[$object, $entry] = $resolved;

		if ($this->mayRead(object: $object, visibility: (string)$entry->getVisibility()) === false) {
			return new JSONResponse(['message' => 'Entry not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = $entry->jsonSerialize();
		$payload['references'] = array_map(
			static fn ($reference) => $reference->jsonSerialize(),
			$this->references->forEntry(entryUuid: (string)$entry->getUuid())
		);

		return new JSONResponse($payload);
	}//end show()

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
		$resolved = $this->resolveEntry(register: $register, schema: $schema, id: $id, entryId: $entryId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		[$object, $entry] = $resolved;
		$data = $this->request->getParams();

		try {
			if (array_key_exists('pinned', $data) === true) {
				$entry = $this->entries->pin(
					object: $object,
					entry: $entry,
					pinned: ($data['pinned'] !== false && $data['pinned'] !== 'false')
				);
			}

			if (($data['followUp'] ?? null) === 'done') {
				$entry = $this->entries->closeFollowUp(entryObject: $object, entry: $entry);
			}
		} catch (TimelinePermissionException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'update');
		}

		return new JSONResponse($entry->jsonSerialize());
	}//end update()

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
		$resolved = $this->resolveEntry(register: $register, schema: $schema, id: $id, entryId: $entryId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		[$object, $entry] = $resolved;

		if ($this->mayRead(object: $object, visibility: (string)$entry->getVisibility()) === false) {
			return new JSONResponse(['message' => 'Entry not found'], Http::STATUS_NOT_FOUND);
		}

		$source = $this->entries->sourceFor(entry: $entry);
		if ($source['source'] === null) {
			return new JSONResponse(['message' => 'This entry was not made from an inbound message'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($source);
	}//end source()

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
			$results = $this->search->searchAsArrays(
				term: $term,
				visibility: $this->askedVisibility(params: $params),
				kind: $this->text(params: $params, key: 'kind'),
				limit: (int)($params['limit'] ?? 25)
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
				register: (string)($entry['register'] ?? ''),
				schema: (string)($entry['schema'] ?? ''),
				id: (string)($entry['id'] ?? '')
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
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 * @param string $entryId  Entry uuid.
	 *
	 * @return array{0: ObjectEntity, 1: \OCA\OpenRegister\Db\TimelineEntry}|JSONResponse The pair, or the refusal.
	 */
	private function resolveEntry(string $register, string $schema, string $id, string $entryId): array|JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
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
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or a 404.
	 */
	private function resolveObject(string $register, string $schema, string $id): ObjectEntity|JSONResponse {
		if ($register === '' || $schema === '' || $id === '') {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		$this->objects->setRegister($register);
		$this->objects->setSchema($schema);
		$this->objects->setObject($id);

		try {
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
