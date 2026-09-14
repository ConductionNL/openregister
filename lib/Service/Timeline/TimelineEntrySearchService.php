<?php

/**
 * TimelineEntrySearchService: entries searched across objects.
 *
 * A Woo request asks for every mention of a subject and the mentions live in
 * the timeline, not in the objects. OpenRegister's search has always answered
 * about objects; this answers about entries, and a hit names both.
 *
 * WHERE EACH FILTER LIVES, AND WHY THEY ARE NOT IN THE SAME PLACE.
 *
 *  - The VISIBILITY filter is a condition in the STATEMENT (D-1). A portal
 *    reader is entitled to public entries and the query says so, so the page
 *    it gets back is a real page of the right size. Filtering afterwards would
 *    shorten every page by an unknown amount and still count the ones it hid.
 *  - The OBJECT ACCESS check is resolved PER OBJECT, after the statement,
 *    through the same ObjectService read every other caller goes through. It
 *    is not a query condition because the set of objects a handler may read is
 *    unbounded and enumerating it to build an IN clause would be a bigger read
 *    than the search. The statement therefore over-fetches and this service
 *    drops what the caller may not see, which is why {@see search()} asks the
 *    mapper for more rows than it returns.
 *
 * An object resolves once per request, however many of its entries matched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Db\TimelineEntryMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Searching timeline entries across objects.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TimelineEntrySearchService {

	/**
	 * How many rows the statement fetches for each row the page returns.
	 *
	 * The access check drops rows AFTER the statement, so a page of 25 would
	 * come back short whenever some hits sit on objects the caller may not
	 * read. Over-fetching four times keeps a full page in the ordinary case
	 * without turning a search into a table scan in the pathological one.
	 *
	 * @var integer
	 */
	public const OVERFETCH = 4;

	/**
	 * Objects already resolved this request, keyed by uuid.
	 *
	 * The value is the object when the caller may read it and FALSE when they
	 * may not, so a refusal is remembered as firmly as an admission: without
	 * that, a search matching forty entries on one forbidden case would run
	 * forty identical refused reads.
	 *
	 * @var array<string, ObjectEntity|false>
	 */
	private array $resolved = [];

	/**
	 * Constructor.
	 *
	 * @param TimelineEntryMapper       $entries    The entry records.
	 * @param ObjectService             $objects    Resolves each hit's object under the caller's own access.
	 * @param TimelineVisibilityService $visibility Normalises the flag.
	 * @param LoggerInterface           $logger     Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimelineEntryMapper $entries,
		private readonly ObjectService $objects,
		private readonly TimelineVisibilityService $visibility,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Entries matching a term, on objects the caller may read.
	 *
	 * @param string      $term       The search term.
	 * @param string|null $visibility The visibility the caller is entitled to, or null for both.
	 * @param string|null $kind       Narrow to one kind.
	 * @param integer     $limit      How many hits to return.
	 *
	 * @return array<int, array{entry: TimelineEntry, object: ObjectEntity}> The hits, each naming its object.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function search(string $term, ?string $visibility = null, ?string $kind = null, int $limit = 25): array {
		$trimmed = trim($term);
		if ($trimmed === '') {
			return [];
		}

		$normalised = null;
		if ($visibility !== null) {
			$normalised = $this->visibility->normalise(value: $visibility);
		}

		$candidates = $this->entries->search(
			term: $trimmed,
			objectUuids: null,
			visibility: $normalised,
			kind: $kind,
			limit: ($limit * self::OVERFETCH)
		);

		$hits = [];
		foreach ($candidates as $entry) {
			$object = $this->objectFor(objectUuid: (string)$entry->getObjectUuid());
			if ($object === null) {
				continue;
			}

			$hits[] = ['entry' => $entry, 'object' => $object];

			if (count($hits) >= $limit) {
				break;
			}
		}

		return $hits;
	}//end search()

	/**
	 * The entries a caller may read as a plain payload, each naming its object.
	 *
	 * @param string      $term       The search term.
	 * @param string|null $visibility The visibility the caller is entitled to.
	 * @param string|null $kind       Narrow to one kind.
	 * @param integer     $limit      How many hits to return.
	 *
	 * @return array<int, array<string,mixed>> The hits.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function searchAsArrays(
		string $term,
		?string $visibility = null,
		?string $kind = null,
		int $limit = 25,
	): array {
		$results = [];
		foreach ($this->search(term: $term, visibility: $visibility, kind: $kind, limit: $limit) as $hit) {
			$entry = $hit['entry']->jsonSerialize();
			$entry['object'] = [
				'uuid' => (string)$hit['object']->getUuid(),
				'title' => (string)($hit['object']->getName() ?? $hit['object']->getUuid()),
				'register' => $hit['object']->getRegister(),
				'schema' => $hit['object']->getSchema(),
			];

			$results[] = $entry;
		}

		return $results;
	}//end searchAsArrays()

	/**
	 * Resolve one hit's object under the caller's own access, once per request.
	 *
	 * A read that throws or answers null is a refusal, and the refusal is
	 * REMEMBERED: the caller may not read this object, and asking again inside
	 * the same search would only produce the same answer more slowly.
	 *
	 * @param string $objectUuid The object a hit sits on.
	 *
	 * @return ObjectEntity|null The object, or null when the caller may not read it.
	 */
	private function objectFor(string $objectUuid): ?ObjectEntity {
		if ($objectUuid === '') {
			return null;
		}

		if (array_key_exists($objectUuid, $this->resolved) === true) {
			$known = $this->resolved[$objectUuid];

			return ($known === false) ? null : $known;
		}

		try {
			$object = $this->objects->find(
				id: $objectUuid,
				_rbac: true,
				_multitenancy: true,
				_render: false,
				_audit: false
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[TimelineEntrySearchService] Entry hit on object '.$objectUuid
					.' dropped; the searcher may not read it'
			);
			$object = null;
		}

		$this->resolved[$objectUuid] = ($object ?? false);

		return $object;
	}//end objectFor()
}//end class
