<?php

/**
 * What a citizen's word means here, as administered data.
 *
 * A search cannot be taught that `omgevingsvergunning` and `bouwvergunning`
 * are the same thing to the person typing in a portal, and it cannot be told
 * which words carry no meaning in this register. Both are administered facts
 * about language, not code, so they live in the vocabulary register (ADR-031)
 * and are applied at query time (ADR-007): a change takes effect on the next
 * search, with no index to rebuild.
 *
 * 🔴 WHAT A QUERY MAY EXPAND TO COMES FROM THE DECLARATIONS. A group is a
 * concept an administrator wrote, with its preferred label and its alternate
 * labels; a stopword is a concept in the stopword scheme. Nothing is inferred
 * from what a search happened to match, and nothing is read from whatever
 * else sits in the register. A dictionary assembled from observed data would
 * expand a query by words nobody administered and report them as if somebody
 * had.
 *
 * 🔑 AN EXPANSION IS BOUNDED TWICE, per group and per query. One group with
 * forty labels must not turn one word into forty; a sentence of eight words
 * must not turn into a hundred. Both bounds are administered, and the report
 * says when one bit.
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

/**
 * An administered synonym and stopword dictionary for one language.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final class SearchDictionary {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<int, string>> $groups    Synonym groups, each a list of lowercase terms.
	 * @param array<int, string>             $stopwords Lowercase stopwords.
	 */
	private function __construct(
		private readonly array $groups,
		private readonly array $stopwords,
	) {
	}//end __construct()

	/**
	 * An empty dictionary, which expands nothing and removes nothing.
	 *
	 * The fail-soft answer everywhere: an instance with no administered
	 * dictionary searches exactly as it did before one existed.
	 *
	 * @return self The empty dictionary.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function empty(): self {
		return new self(groups: [], stopwords: []);
	}//end empty()

	/**
	 * Build a dictionary from administered declarations.
	 *
	 * A group is `{prefLabel, altLabel[]}` — the SKOS concept shape the
	 * vocabulary register already uses, so a synonym set is a concept and needs
	 * no register of its own (ADR-011). A group with fewer than two distinct
	 * terms is dropped: it can only ever expand a word to itself, and keeping
	 * it would report an expansion that changed nothing.
	 *
	 * @param array<int, array{prefLabel?: mixed, altLabel?: mixed}> $concepts  The synonym concepts.
	 * @param array<int, mixed>                                      $stopwords The stopword terms.
	 *
	 * @return self The dictionary.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function fromDeclarations(array $concepts, array $stopwords): self {
		$groups = [];
		foreach ($concepts as $concept) {
			$terms = self::groupOf(concept: $concept);
			if (count($terms) >= 2) {
				$groups[] = $terms;
			}
		}

		return new self(groups: $groups, stopwords: self::normaliseTerms(raw: $stopwords));
	}//end fromDeclarations()

	/**
	 * A concept's preferred and alternative labels as normalised terms; none for a concept that is not an object.
	 *
	 * @param mixed $concept One synonym concept.
	 *
	 * @return array<int, string> The terms.
	 */
	private static function groupOf(mixed $concept): array {
		if (is_array($concept) === false) {
			return [];
		}

		return self::normaliseTerms(
			raw: array_merge(
				[($concept['prefLabel'] ?? null)],
				(array)($concept['altLabel'] ?? [])
			)
		);
	}//end groupOf()

	/**
	 * Whether this dictionary can change any query at all.
	 *
	 * @return bool True when it holds a group or a stopword.
	 */
	public function isEmpty(): bool {
		return ($this->groups === [] && $this->stopwords === []);
	}//end isEmpty()

	/**
	 * Expand a plain search term.
	 *
	 * Stopwords are dropped, each surviving word is joined with its group's
	 * other terms, and the result is written back in the search grammar as
	 * `(word OR synonym)`.
	 *
	 * 🔴 REMOVING EVERY WORD FALLS BACK TO THE ORIGINAL TERM. A query of
	 * nothing but stopwords is still a query somebody typed, and answering it
	 * with the whole register — which an empty term does — is the loudest
	 * possible response to the quietest possible input.
	 *
	 * @param string      $term The raw term, already known to carry no operators.
	 * @param SynonymCaps $caps Most synonyms added per word and across the whole term.
	 *
	 * @return DictionaryExpansion What the term became, and why.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function expand(string $term, SynonymCaps $caps): DictionaryExpansion {
		$words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
		if ($words === false || $words === []) {
			return DictionaryExpansion::unchanged(term: $term);
		}

		[$kept, $removed] = $this->partitionStopwords(words: $words);

		if ($kept === []) {
			// Every word was a stopword. The original term stands.
			return DictionaryExpansion::stopwordsWouldEmptyIt(term: $term, removed: $removed);
		}

		[$pieces, $added] = $this->expandWords(words: $kept, caps: $caps);

		return DictionaryExpansion::expanded(
			term: implode(' ', $pieces),
			original: $term,
			added: $added,
			removed: $removed
		);
	}//end expand()

	/**
	 * Split words into the ones kept and the stopwords removed, in order.
	 *
	 * @param string[] $words The words of the term.
	 *
	 * @return array{0: list<string>, 1: list<string>} The kept and the removed words.
	 */
	private function partitionStopwords(array $words): array {
		$kept = [];
		$removed = [];
		foreach ($words as $word) {
			if (in_array(mb_strtolower($word), $this->stopwords, true) === true) {
				$removed[] = $word;
				continue;
			}

			$kept[] = $word;
		}

		return [$kept, $removed];
	}//end partitionStopwords()

	/**
	 * Write each kept word with its capped synonyms in the search grammar.
	 *
	 * @param string[]   $words The kept words.
	 * @param SynonymCaps $caps  Most synonyms added per word and across the whole term.
	 *
	 * @return array{0: list<string>, 1: list<string>} The pieces of the term and the synonyms added.
	 */
	private function expandWords(array $words, SynonymCaps $caps): array {
		$added = [];
		$budget = max(0, $caps->perQuery);
		$pieces = [];
		foreach ($words as $word) {
			$synonyms = array_slice($this->synonymsFor(word: $word), 0, max(0, $caps->perGroup));
			$synonyms = array_slice($synonyms, 0, $budget);
			$budget -= count($synonyms);

			$pieces[] = $this->piece(word: $word, synonyms: $synonyms);
			$added = array_merge($added, $synonyms);
		}

		return [$pieces, $added];
	}//end expandWords()

	/**
	 * One word, alone or as `(word OR synonym ...)`.
	 *
	 * @param string   $word     The word.
	 * @param string[] $synonyms Its synonyms, already capped.
	 *
	 * @return string The piece of the term.
	 */
	private function piece(string $word, array $synonyms): string {
		if ($synonyms === []) {
			return $word;
		}

		return '(' . implode(' OR ', array_merge([$word], $synonyms)) . ')';
	}//end piece()

	/**
	 * The other terms in this word's group.
	 *
	 * A word in two groups takes both, in declaration order, because two
	 * administrators can legitimately have taught the same word twice.
	 *
	 * @param string $word The word.
	 *
	 * @return string[] The synonyms, without the word itself.
	 *
	 * @psalm-return list<string>
	 */
	private function synonymsFor(string $word): array {
		$needle = mb_strtolower($word);
		$synonyms = [];
		foreach ($this->groups as $group) {
			if (in_array($needle, $group, true) === false) {
				continue;
			}

			$synonyms = $this->withGroup(synonyms: $synonyms, others: array_diff($group, [$needle]));
		}

		return $synonyms;
	}//end synonymsFor()

	/**
	 * Append a group's other terms not yet collected.
	 *
	 * @param string[] $synonyms The synonyms collected so far.
	 * @param string[] $others   The group's terms without the word itself.
	 *
	 * @return string[] The synonyms with the group's new terms appended.
	 *
	 * @psalm-return list<string>
	 */
	private function withGroup(array $synonyms, array $others): array {
		foreach ($others as $term) {
			if (in_array($term, $synonyms, true) === false) {
				$synonyms[] = $term;
			}
		}

		return $synonyms;
	}//end withGroup()

	/**
	 * Lowercase, trim and de-duplicate a term list.
	 *
	 * @param array<int, mixed> $raw The raw terms.
	 *
	 * @return string[] The terms.
	 *
	 * @psalm-return list<string>
	 */
	private static function normaliseTerms(array $raw): array {
		$terms = [];
		foreach ($raw as $term) {
			if (is_string($term) === false) {
				continue;
			}

			$normalised = mb_strtolower(trim($term));
			if ($normalised === '' || in_array($normalised, $terms, true) === true) {
				continue;
			}

			$terms[] = $normalised;
		}

		return $terms;
	}//end normaliseTerms()
}//end class
