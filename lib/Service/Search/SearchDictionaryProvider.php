<?php

/**
 * Loads the administered dictionary, once per request, and never fails a search.
 *
 * The dictionary is data an administrator maintains, so it lives in the
 * vocabulary register the app already ships (ADR-011, ADR-031): a synonym set
 * is a SKOS concept with a preferred label and alternate labels, which is what
 * `altLabel` has always meant, and both are keyed by language tag, so "per
 * language" needs no second mechanism.
 *
 * 🔴 EVERY FAILURE IS AN EMPTY DICTIONARY. A register that is missing, a query
 * that throws, a shape that does not read: all of them answer "no dictionary",
 * and the search runs exactly as it did before one existed. A dictionary is a
 * convenience laid over search; it must never be able to break it.
 *
 * 🔴 IT CANNOT RE-ENTER ITSELF. Loading the dictionary issues a search, and
 * that search would otherwise load the dictionary. The flag below is what stops
 * the first search on a cold request from recursing until it dies.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads the synonym and stopword concepts an administrator maintains.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class SearchDictionaryProvider {

	/**
	 * The register holding the dictionary.
	 *
	 * @var string
	 */
	public const REGISTER = 'vocabulary';

	/**
	 * The schema a synonym group and a stopword are both written as.
	 *
	 * @var string
	 */
	public const SCHEMA = 'concept';

	/**
	 * The schema a concept scheme is written as.
	 *
	 * @var string
	 */
	public const SCHEME_SCHEMA = 'conceptScheme';

	/**
	 * The scheme whose concepts are synonym groups.
	 *
	 * @var string
	 */
	public const SYNONYM_SCHEME = 'https://openregister.app/vocabularies/search-synonyms';

	/**
	 * The scheme whose concepts are stopwords.
	 *
	 * @var string
	 */
	public const STOPWORD_SCHEME = 'https://openregister.app/vocabularies/search-stopwords';

	/**
	 * Most synonyms one word may contribute, unless administered otherwise.
	 *
	 * A group with forty labels must not turn one typed word into forty
	 * clauses; the bound is on the QUERY's cost, not on the administrator's
	 * vocabulary, so the group may be as large as it likes.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_GROUP = 5;

	/**
	 * Most synonyms one query may gain in total, unless administered otherwise.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_QUERY = 20;

	/**
	 * Most concepts read from the register in one load.
	 *
	 * @var int
	 */
	private const CONCEPT_LIMIT = 1000;

	/**
	 * The dictionary this request has already loaded, keyed by language.
	 *
	 * @var array<string, SearchDictionary>
	 */
	private array $memo = [];

	/**
	 * Whether a load is in progress, so the search it issues does not recurse.
	 *
	 * @var bool
	 */
	private bool $loading = false;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper     $objects    Reads the concepts.
	 * @param RegisterMapper  $registers  Resolves the vocabulary register's id.
	 * @param SchemaMapper    $schemas    Resolves the concept schemas' ids.
	 * @param IAppConfig      $appConfig  Holds the administered caps.
	 * @param LoggerInterface $logger     Diagnostics.
	 */
	public function __construct(
		private readonly MagicMapper $objects,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The dictionary for one language.
	 *
	 * @param string $language The BCP-47 language tag.
	 *
	 * @return SearchDictionary The dictionary, empty when there is none.
	 */
	public function forLanguage(string $language = 'nl'): SearchDictionary {
		if (isset($this->memo[$language]) === true) {
			return $this->memo[$language];
		}

		if ($this->loading === true) {
			// The load's own search reached back here. Answering empty is what
			// lets that search complete, and the outer call still gets the real
			// dictionary.
			return SearchDictionary::empty();
		}

		$this->loading = true;
		try {
			$dictionary = SearchDictionary::fromDeclarations(
				concepts: $this->declarationsIn(scheme: self::SYNONYM_SCHEME, language: $language),
				stopwords: $this->stopwordsIn(language: $language)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[SearchDictionaryProvider] No dictionary loaded, searching without one: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
			$dictionary = SearchDictionary::empty();
		} finally {
			$this->loading = false;
		}

		$this->memo[$language] = $dictionary;

		return $dictionary;
	}//end forLanguage()

	/**
	 * Most synonyms one word may contribute.
	 *
	 * @return int The cap.
	 */
	public function perGroupCap(): int {
		return max(0, $this->appConfig->getValueInt('openregister', 'searchDictionaryPerGroup', self::DEFAULT_PER_GROUP));
	}//end perGroupCap()

	/**
	 * Most synonyms one query may gain.
	 *
	 * @return int The cap.
	 */
	public function perQueryCap(): int {
		return max(0, $this->appConfig->getValueInt('openregister', 'searchDictionaryPerQuery', self::DEFAULT_PER_QUERY));
	}//end perQueryCap()

	/**
	 * The synonym concepts, projected to one language.
	 *
	 * @param string $scheme   The scheme uri.
	 * @param string $language The language tag.
	 *
	 * @return array<int, array{prefLabel: string, altLabel: array<int, string>}> The declarations.
	 */
	private function declarationsIn(string $scheme, string $language): array {
		$declarations = [];
		foreach ($this->conceptsIn(schemeUri: $scheme) as $concept) {
			$preferred = $this->label(raw: ($concept['prefLabel'] ?? null), language: $language);
			if ($preferred === null) {
				continue;
			}

			$alternates = [];
			foreach ((array)($concept['altLabel'] ?? []) as $tag => $value) {
				if ((string)$tag !== $language) {
					continue;
				}

				foreach ((array)$value as $alternate) {
					if (is_string($alternate) === true) {
						$alternates[] = $alternate;
					}
				}
			}

			$declarations[] = ['prefLabel' => $preferred, 'altLabel' => $alternates];
		}//end foreach

		return $declarations;
	}//end declarationsIn()

	/**
	 * The stopwords, projected to one language.
	 *
	 * @param string $language The language tag.
	 *
	 * @return array<int, string> The stopwords.
	 */
	private function stopwordsIn(string $language): array {
		$stopwords = [];
		foreach ($this->conceptsIn(schemeUri: self::STOPWORD_SCHEME) as $concept) {
			$label = $this->label(raw: ($concept['prefLabel'] ?? null), language: $language);
			if ($label !== null) {
				$stopwords[] = $label;
			}
		}

		return $stopwords;
	}//end stopwordsIn()

	/**
	 * One language's label off a language-keyed map.
	 *
	 * A concept with no label in this language contributes NOTHING rather than
	 * falling back to another language: expanding a Dutch query by an English
	 * synonym is not what the administrator declared.
	 *
	 * @param mixed  $raw      The language-keyed map.
	 * @param string $language The language tag.
	 *
	 * @return string|null The label.
	 */
	private function label(mixed $raw, string $language): ?string {
		if (is_array($raw) === false) {
			return null;
		}

		$label = ($raw[$language] ?? null);
		if (is_string($label) === false || trim($label) === '') {
			return null;
		}

		return $label;
	}//end label()

	/**
	 * The concepts of one scheme, as arrays.
	 *
	 * 🔴 SLUGS ARE RESOLVED TO IDS HERE. The search path casts whatever it is
	 * given to an int, so a register passed by slug becomes register 0 and the
	 * query answers nothing — silently, and in a feature that already fails
	 * soft, which would have made a broken dictionary indistinguishable from an
	 * unused one.
	 *
	 * 🔑 `inScheme` HOLDS THE SCHEME OBJECT'S UUID, not its uri. The uri is the
	 * durable public identifier an administrator writes; the reference beside
	 * it is the object. Filtering concepts by the uri matches nothing at all.
	 *
	 * @param string $schemeUri The scheme's canonical uri.
	 *
	 * @return array<int, array<string, mixed>> The concepts.
	 */
	private function conceptsIn(string $schemeUri): array {
		// The slug map answers slug => LIST OF IDS, keyed by the LOWERCASED
		// slug. Both halves matter: `conceptScheme` is filed under
		// `conceptscheme`, and a slug can legitimately resolve to several ids,
		// so the value is a list even when there is one.
		$registerId = self::firstId(
			map: $this->registers->findIdsBySlugs([self::REGISTER]),
			slug: self::REGISTER
		);
		$conceptId = self::firstId(map: $this->schemas->findIdsBySlugs([self::SCHEMA]), slug: self::SCHEMA);
		$schemeSchemaId = self::firstId(
			map: $this->schemas->findIdsBySlugs([self::SCHEME_SCHEMA]),
			slug: self::SCHEME_SCHEMA
		);

		if ($registerId === null || $conceptId === null || $schemeSchemaId === null) {
			$this->logger->info(
				'[SearchDictionaryProvider] No vocabulary register on this instance, searching without a dictionary'
			);
			return [];
		}

		$schemeUuid = $this->schemeUuid(
			registerId: $registerId,
			schemaId: $schemeSchemaId,
			schemeUri: $schemeUri
		);
		if ($schemeUuid === null) {
			// Said out loud rather than passed over: an administrator who wrote
			// concepts and sees no expansion needs a trail, and "no scheme" is
			// the first thing to check.
			$this->logger->info(
				'[SearchDictionaryProvider] No concept scheme "{scheme}", so nothing is administered for it',
				['scheme' => $schemeUri]
			);
			return [];
		}

		return $this->rowsOf(
			result: $this->objects->searchObjectsPaginated(
				searchQuery: [
					'_register' => $registerId,
					'_schema' => $conceptId,
					'inScheme' => $schemeUuid,
					'_limit' => self::CONCEPT_LIMIT,
				],
				countQuery: [],
				_rbac: false,
				_multitenancy: false
			)
		);
	}//end conceptsIn()

	/**
	 * The uuid of the scheme object carrying this uri.
	 *
	 * @param int    $registerId The vocabulary register.
	 * @param int    $schemaId   The conceptScheme schema.
	 * @param string $schemeUri  The canonical uri.
	 *
	 * @return string|null The uuid, or null when no scheme carries it.
	 */
	private function schemeUuid(int $registerId, int $schemaId, string $schemeUri): ?string {
		$result = $this->objects->searchObjectsPaginated(
			searchQuery: [
				'_register' => $registerId,
				'_schema' => $schemaId,
				'uri' => $schemeUri,
				'_limit' => 1,
			],
			countQuery: [],
			_rbac: false,
			_multitenancy: false
		);

		foreach (($result['results'] ?? []) as $row) {
			if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
				return (string)$row->getUuid();
			}

			if (is_array($row) === true) {
				$uuid = (($row['@self']['id'] ?? null) ?? ($row['id'] ?? null));
				if (is_string($uuid) === true && $uuid !== '') {
					return $uuid;
				}
			}
		}

		return null;
	}//end schemeUuid()

	/**
	 * The object payloads of a search result.
	 *
	 * @param array $result The search result.
	 *
	 * @return array<int, array<string, mixed>> The payloads.
	 */
	private function rowsOf(array $result): array {
		$rows = [];
		foreach (($result['results'] ?? []) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = (array)$row->getObject();
				continue;
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The first id a slug resolved to.
	 *
	 * @param array  $map  slug => list of ids, as findIdsBySlugs() answers it.
	 * @param string $slug The slug, in any case.
	 *
	 * @return int|null The id, or null when the slug resolved to nothing.
	 */
	private static function firstId(array $map, string $slug): ?int {
		$ids = ($map[strtolower($slug)] ?? []);
		if (is_array($ids) === false || $ids === []) {
			return null;
		}

		return (int)reset($ids);
	}//end firstId()
}//end class
