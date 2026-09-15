<?php

/**
 * OpenRegister TimelineEntriesProvider
 *
 * The unified-search provider over TIMELINE ENTRIES, beside the one over
 * objects. They are separate providers on purpose: a Woo request asks for
 * every mention of a subject, and the answer is a list of ENTRIES, each
 * naming the case it sits on. Folding the entry text into the object's own
 * document would produce one blob that matches everything and points at
 * nothing (D-1).
 *
 * SECURITY CONTRACT — the provider performs no access logic of its own. The
 * entry's internal or public visibility is a condition in the statement, and
 * the object behind each hit is resolved through the ordinary ObjectService
 * read with `_rbac` and `_multitenancy` on, so a hit on a case the searcher
 * may not read never reaches the page. Both live in
 * {@see \OCA\OpenRegister\Service\Timeline\TimelineEntrySearchService}, which
 * says which is which and why.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Timeline\TimelineEntrySearchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Searches timeline entries across objects.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
 */
class TimelineEntriesProvider implements IFilteringProvider {

	/**
	 * How many entry hits one search page carries.
	 *
	 * @var integer
	 */
	private const PAGE_LIMIT = 25;

	/**
	 * How much of an entry a subline shows.
	 *
	 * @var integer
	 */
	private const EXCERPT_LENGTH = 160;

	/**
	 * Constructor.
	 *
	 * @param IL10N                      $l10n      Localisation.
	 * @param TimelineEntrySearchService $entries   The search, with its two filters.
	 * @param IURLGenerator              $urls      Builds the fallback link.
	 * @param DeepLinkRegistryService    $deepLinks Sends a hit to the app that owns the case, when one claimed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly TimelineEntrySearchService $entries,
		private readonly IURLGenerator $urls,
		private readonly DeepLinkRegistryService $deepLinks,
	) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string The id.
	 *
	 * @psalm-return 'openregister_timeline_entries'
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function getId(): string {
		return 'openregister_timeline_entries';
	}//end getId()

	/**
	 * The section name a reader sees.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('Timeline entries');
	}//end getName()

	/**
	 * Where this section sits in the search dropdown.
	 *
	 * Below the objects section: somebody searching a case number usually
	 * wants the case, and the entries mentioning it second.
	 *
	 * @param string               $route           The route being searched from.
	 * @param array<string, mixed> $routeParameters The route parameters.
	 *
	 * @return integer|null The order.
	 *
	 * @psalm-suppress   UnusedParam Parameters required by the interface but not used
	 * @SuppressWarnings (PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		unset($route, $routeParameters);

		return 15;
	}//end getOrder()

	/**
	 * The filters this provider understands.
	 *
	 * @return array<int,string> The filter names.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function getSupportedFilters(): array {
		return ['term', 'kind'];
	}//end getSupportedFilters()

	/**
	 * Alternate ids, of which there are none.
	 *
	 * @return array<int,string> The ids.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function getAlternateIds(): array {
		return [];
	}//end getAlternateIds()

	/**
	 * The filters this provider declares of its own.
	 *
	 * @return array<int, FilterDefinition> The definitions.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function getCustomFilters(): array {
		return [new FilterDefinition(name: 'kind', type: FilterDefinition::TYPE_STRING)];
	}//end getCustomFilters()

	/**
	 * Search timeline entries.
	 *
	 * The visibility asked for here is null, meaning "whatever the entry
	 * carries": the top bar is an authenticated handler's search, and the
	 * public-only view belongs to the portal's subject-scoped reader, which
	 * comes in over the API with its own filter rather than through this
	 * provider.
	 *
	 * @param IUser        $user  The searcher.
	 * @param ISearchQuery $query The query.
	 *
	 * @return SearchResult The hits, each naming its object and its entry.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchResult::complete is the standard Nextcloud pattern
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		unset($user);

		$term = $query->getFilter('term')?->get();
		if (is_string($term) === false || trim($term) === '') {
			return SearchResult::complete(name: $this->getName(), entries: []);
		}

		$kind = $query->getFilter('kind')?->get();
		if (is_string($kind) === false || trim($kind) === '') {
			$kind = null;
		}

		$hits = $this->entries->search(
			term: $term,
			visibility: null,
			kind: $kind,
			limit: self::PAGE_LIMIT
		);

		$rows = [];
		foreach ($hits as $hit) {
			$rows[] = $this->format(entry: $hit['entry'], object: $hit['object']);
		}

		return SearchResult::complete(name: $this->getName(), entries: $rows);
	}//end search()

	/**
	 * Build one hit.
	 *
	 * The title names the OBJECT and the subline carries the entry, because a
	 * page of twelve identical "Note" rows helps nobody find the case they
	 * were looking for.
	 *
	 * @param TimelineEntry $entry  The matching entry.
	 * @param ObjectEntity  $object The object it sits on.
	 *
	 * @return SearchResultEntry The row.
	 */
	private function format(TimelineEntry $entry, ObjectEntity $object): SearchResultEntry {
		$objectTitle = (string)($object->getName() ?? $object->getUuid());
		$kind = $entry->getKind();

		$title = $objectTitle;
		if ($kind !== null) {
			$title = $objectTitle.' · '.$kind;
		}

		$url = $this->objectUrl(object: $object);

		return new SearchResultEntry(
			'',
			$title,
			$this->excerpt(text: $entry->getMessage()),
			$url.'#entry-'.(string)$entry->getUuid(),
			'icon-comment'
		);
	}//end format()

	/**
	 * Where a hit sends the reader.
	 *
	 * The deep-link registry first, so a hit on a dossiq case opens in dossiq
	 * rather than in the register admin; OpenRegister's own object route when
	 * no app claimed the pair. The entry's stable id rides along as a fragment
	 * so the receiving surface can scroll to the entry rather than to the top
	 * of a three-hundred-row timeline.
	 *
	 * @param ObjectEntity $object The object the hit sits on.
	 *
	 * @return string The url.
	 */
	private function objectUrl(ObjectEntity $object): string {
		$registerId = (int)$object->getRegister();
		$schemaId = (int)$object->getSchema();
		$uuid = (string)$object->getUuid();

		$url = $this->deepLinks->resolveUrl(
			registerId: $registerId,
			schemaId: $schemaId,
			objectData: ['uuid' => $uuid, 'register' => $registerId, 'schema' => $schemaId]
		);

		if ($url === null) {
			$url = $this->urls->linkToRoute(
				'openregister.objects.show',
				['register' => $registerId, 'schema' => $schemaId, 'id' => $uuid]
			);
		}

		return $url;
	}//end objectUrl()

	/**
	 * The first part of an entry, for the subline.
	 *
	 * @param string|null $text The entry text.
	 *
	 * @return string The excerpt.
	 */
	private function excerpt(?string $text): string {
		if (is_string($text) === false) {
			return '';
		}

		$flat = trim((string)preg_replace('/\s+/u', ' ', $text));
		if (mb_strlen($flat) <= self::EXCERPT_LENGTH) {
			return $flat;
		}

		return mb_substr($flat, 0, self::EXCERPT_LENGTH).'…';
	}//end excerpt()
}//end class
