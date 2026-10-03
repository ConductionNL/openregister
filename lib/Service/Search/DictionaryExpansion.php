<?php

/**
 * What the dictionary did to a query, said out loud.
 *
 * 🔴 A RESULT NOBODY EXPECTED MUST CARRY ITS OWN REASON. Expansion is the one
 * search feature that returns rows the searcher did not ask for: they typed
 * `omgevingsvergunning` and got a `bouwvergunning` back. Without this report
 * that reads as a broken search, and the person has no way to discover that an
 * administrator taught it the word.
 *
 * 🔴 THE REPORT NAMES WHAT THE DICTIONARY ADDED, not what the search matched.
 * Listing matched terms would let anything the pipeline attached appear as
 * though an administrator had declared it.
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

use JsonSerializable;

/**
 * The account a search gives of its own expansion.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final class DictionaryExpansion implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string   $term     The term the search actually runs.
	 * @param string   $original The term as typed.
	 * @param string[] $added    Synonyms the dictionary added.
	 * @param string[] $removed  Stopwords the dictionary dropped.
	 * @param bool     $kept     Whether the original stood because removal would have emptied it.
	 */
	private function __construct(
		private readonly string $term,
		private readonly string $original,
		private readonly array $added,
		private readonly array $removed,
		private readonly bool $kept,
	) {
	}//end __construct()

	/**
	 * The dictionary had nothing to say.
	 *
	 * @param string $term The term.
	 *
	 * @return self The expansion.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function unchanged(string $term): self {
		return new self(term: $term, original: $term, added: [], removed: [], kept: false);
	}//end unchanged()

	/**
	 * The dictionary changed the term.
	 *
	 * @param string   $term     The rewritten term.
	 * @param string   $original The term as typed.
	 * @param string[] $added    Synonyms added.
	 * @param string[] $removed  Stopwords dropped.
	 *
	 * @return self The expansion.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function expanded(string $term, string $original, array $added, array $removed): self {
		return new self(term: $term, original: $original, added: $added, removed: $removed, kept: false);
	}//end expanded()

	/**
	 * Every word was a stopword, so the term as typed stands.
	 *
	 * @param string   $term    The original term.
	 * @param string[] $removed The stopwords that would have been dropped.
	 *
	 * @return self The expansion.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function stopwordsWouldEmptyIt(string $term, array $removed): self {
		return new self(term: $term, original: $term, added: [], removed: $removed, kept: true);
	}//end stopwordsWouldEmptyIt()

	/**
	 * The term the search runs.
	 *
	 * @return string The term.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function term(): string {
		return $this->term;
	}//end term()

	/**
	 * Whether the term the search runs differs from the one that was typed.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function changed(): bool {
		return ($this->term !== $this->original);
	}//end changed()

	/**
	 * Whether this expansion is worth reporting at all.
	 *
	 * A term nothing happened to says nothing: an `@self.dictionary` block on
	 * every search would be noise on the many to serve the few.
	 *
	 * @return bool True when the dictionary did something.
	 */
	public function isReportable(): bool {
		return ($this->changed() === true || $this->removed !== [] || $this->kept === true);
	}//end isReportable()

	/**
	 * The report.
	 *
	 * @return array<string, mixed> The account.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'original' => $this->original,
			'searched' => $this->term,
			'added' => $this->added,
			'removedStopwords' => $this->removed,
			// Says WHY nothing was removed from a term made only of stopwords,
			// which otherwise looks like a dictionary that did not load.
			'keptBecauseRemovalWouldEmptyIt' => $this->kept,
		];
	}//end jsonSerialize()
}//end class
