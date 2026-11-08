<?php

/**
 * Characterisation tests for the search dictionary, history predicate,
 * history narrowing, term tokenizer and dictionary provider units.
 *
 * Pins the exact outcomes of every branch the complexity refactor of these
 * units touches, recorded on the code before that refactor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Exception\SearchTermSyntaxException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCA\OpenRegister\Service\Search\HistoryPredicate;
use OCA\OpenRegister\Service\Search\SearchDictionary;
use OCA\OpenRegister\Service\Search\SearchDictionaryMappers;
use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;
use OCA\OpenRegister\Service\Search\SearchTermParser;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SearchUnitsCharacterisationTest extends TestCase {

	/**
	 * The dictionary every expansion case runs against.
	 *
	 * @return SearchDictionary The dictionary.
	 */
	private function dictionary(): SearchDictionary {
		return SearchDictionary::fromDeclarations(
			concepts: [
				['prefLabel' => 'Auto', 'altLabel' => ['wagen', 'voertuig', 'AUTO']],
				['prefLabel' => 'wagen', 'altLabel' => ['kar']],
				['prefLabel' => 'fiets', 'altLabel' => ['rijwiel']],
				'not-a-concept',
				['prefLabel' => 'alleen'],
			],
			stopwords: ['De', 'het', 7, '  ']
		);
	}//end dictionary()

	/**
	 * Expansion cases: term, per-group cap, per-query cap, serialised outcome.
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: array<string, mixed>}> The cases.
	 */
	public static function expansionCases(): array {
		return [
			'blank term is unchanged' => [
				'   ',
				5,
				20,
				['original' => '   ', 'searched' => '   ', 'added' => [], 'removedStopwords' => [], 'keptBecauseRemovalWouldEmptyIt' => false],
			],
			'only stopwords keeps the term' => [
				'De  het',
				5,
				20,
				['original' => 'De  het', 'searched' => 'De  het', 'added' => [], 'removedStopwords' => ['De', 'het'], 'keptBecauseRemovalWouldEmptyIt' => true],
			],
			'stopword dropped, words expanded across groups' => [
				' de Wagen fiets boom ',
				5,
				20,
				[
					'original' => ' de Wagen fiets boom ',
					'searched' => '(Wagen OR auto OR voertuig OR kar) (fiets OR rijwiel) boom',
					'added' => ['auto', 'voertuig', 'kar', 'rijwiel'],
					'removedStopwords' => ['de'],
					'keptBecauseRemovalWouldEmptyIt' => false,
				],
			],
			'per-group cap bounds one word' => [
				'wagen',
				1,
				20,
				['original' => 'wagen', 'searched' => '(wagen OR auto)', 'added' => ['auto'], 'removedStopwords' => [], 'keptBecauseRemovalWouldEmptyIt' => false],
			],
			'per-query budget runs out mid-term' => [
				'auto fiets',
				5,
				2,
				['original' => 'auto fiets', 'searched' => '(auto OR wagen OR voertuig) fiets', 'added' => ['wagen', 'voertuig'], 'removedStopwords' => [], 'keptBecauseRemovalWouldEmptyIt' => false],
			],
			'negative caps add nothing' => [
				'auto het',
				-3,
				-1,
				['original' => 'auto het', 'searched' => 'auto', 'added' => [], 'removedStopwords' => ['het'], 'keptBecauseRemovalWouldEmptyIt' => false],
			],
		];
	}//end expansionCases()

	/**
	 * Every expansion branch answers its recorded outcome.
	 *
	 * @param string               $term     The term.
	 * @param int                  $perGroup The per-group cap.
	 * @param int                  $perQuery The per-query cap.
	 * @param array<string, mixed> $expected The serialised expansion.
	 *
	 * @return void
	 */
	#[DataProvider('expansionCases')]
	public function testExpansionMatchesRecording(string $term, int $perGroup, int $perQuery, array $expected): void {
		$expansion = $this->dictionary()->expand($term, $perGroup, $perQuery);

		$this->assertSame($expected, $expansion->jsonSerialize());
	}//end testExpansionMatchesRecording()

	/**
	 * Predicate parse cases: query, serialised predicate with periods as ISO strings.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> The cases.
	 */
	public static function predicateCases(): array {
		$period = ['after' => '2024-01-01T00:00:00+00:00', 'before' => '2024-12-31T00:00:00+00:00'];

		return [
			'no history keys' => [
				['status' => 'x'],
				['wasEver' => [], 'changedBetween' => [], 'unparsed' => []],
			],
			'was-ever: good, bad name, non-scalar, empty' => [
				['_was_ever' => ['status' => 'open', '9bad' => 'x', 'tags' => ['a'], 'kind' => '', 'n' => 3]],
				['wasEver' => ['status' => 'open', 'n' => '3'], 'changedBetween' => [], 'unparsed' => ['_was_ever[9bad]', '_was_ever[tags]', '_was_ever[kind]']],
			],
			'changed-between: string and array forms' => [
				['_changed_between' => [
					'a' => ' 2024-01-01T00:00:00+00:00 , 2024-12-31T00:00:00+00:00 ',
					'b' => ['after' => '2024-01-01T00:00:00+00:00', 'before' => '2024-12-31T00:00:00+00:00'],
				]],
				['wasEver' => [], 'changedBetween' => ['a' => $period, 'b' => $period], 'unparsed' => []],
			],
			'changed-between: every refusal' => [
				['_changed_between' => [
					'one' => '2024-01-01',
					'three' => '2024-01-01,2024-02-01,2024-03-01',
					'nonstring' => ['after' => 1, 'before' => '2024-01-01'],
					'missing' => ['after' => '2024-01-01'],
					'garbage' => 'not-a-date,2024-01-01',
					'backwards' => '2024-12-31,2024-01-01',
					'scalar' => 5,
					'bad name!' => '2024-01-01,2024-02-01',
				]],
				['wasEver' => [], 'changedBetween' => [], 'unparsed' => [
					'_changed_between[one]',
					'_changed_between[three]',
					'_changed_between[nonstring]',
					'_changed_between[missing]',
					'_changed_between[garbage]',
					'_changed_between[backwards]',
					'_changed_between[scalar]',
					'_changed_between[bad name!]',
				]],
			],
			'both keys: was-ever refusals listed before changed-between refusals' => [
				['_changed_between' => ['x' => 'nope'], '_was_ever' => ['' => 'v', 'ok' => 'v']],
				['wasEver' => ['ok' => 'v'], 'changedBetween' => [], 'unparsed' => ['_was_ever[]', '_changed_between[x]']],
			],
			'scalar history key is cast to a list' => [
				['_was_ever' => 'status'],
				['wasEver' => [], 'changedBetween' => [], 'unparsed' => ['_was_ever[0]']],
			],
		];
	}//end predicateCases()

	/**
	 * Every parse branch answers its recorded predicate.
	 *
	 * @param array<string, mixed> $query    The query.
	 * @param array<string, mixed> $expected The serialised predicate.
	 *
	 * @return void
	 */
	#[DataProvider('predicateCases')]
	public function testPredicateMatchesRecording(array $query, array $expected): void {
		$serialised = json_decode((string)json_encode(HistoryPredicate::parse($query)), true);

		$this->assertSame($expected, $serialised);
	}//end testPredicateMatchesRecording()

	/**
	 * Narrowing intersects every filter in order, then the incoming ids, and logs once.
	 *
	 * @return void
	 */
	public function testNarrowingIntersectsFiltersThenIds(): void {
		$mapper = $this->createMock(StateHistoryMapper::class);
		$calls = [];
		$mapper->method('findObjectUuidsEverAt')->willReturnCallback(
			static function (string $property, string $value) use (&$calls): array {
				$calls[] = 'ever:' . $property . '=' . $value;
				return ['a', 'b', 'b', 'c', 'd'];
			}
		);
		$mapper->method('findObjectUuidsChangedBetween')->willReturnCallback(
			static function (string $property) use (&$calls): array {
				$calls[] = 'between:' . $property;
				return ['d', 'c', 'b'];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[HistoryNarrowing] History predicate narrowed the query to {count} candidates',
			['count' => 2]
		);

		$predicate = HistoryPredicate::parse([
			'_was_ever' => ['status' => 'open'],
			'_changed_between' => ['phase' => '2024-01-01,2024-02-01'],
		]);
		$narrowed = (new HistoryNarrowing($mapper, $logger))->narrow($predicate, ['c', 'x', 'b']);

		$this->assertSame(['c', 'b'], $narrowed);
		$this->assertSame(['ever:status=open', 'between:phase'], $calls);
	}//end testNarrowingIntersectsFiltersThenIds()

	/**
	 * A first filter alone is de-duplicated; an empty id set does not narrow it further.
	 *
	 * @return void
	 */
	public function testNarrowingWithEmptyIdsKeepsTheCandidates(): void {
		$mapper = $this->createMock(StateHistoryMapper::class);
		$mapper->method('findObjectUuidsEverAt')->willReturn(['a', 'a', 'b']);

		$narrowed = (new HistoryNarrowing($mapper, $this->createMock(LoggerInterface::class)))->narrow(
			HistoryPredicate::parse(['_was_ever' => ['status' => 'open']]),
			[]
		);

		$this->assertSame(['a', 'b'], $narrowed);
	}//end testNarrowingWithEmptyIdsKeepsTheCandidates()

	/**
	 * A predicate that read nothing answers the incoming ids, or an empty list, and does not log.
	 *
	 * @return void
	 */
	public function testNarrowingWithoutFiltersAnswersTheIds(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');
		$narrowing = new HistoryNarrowing($this->createMock(StateHistoryMapper::class), $logger);
		$empty = HistoryPredicate::parse([]);

		$this->assertSame(['z', 'y'], $narrowing->narrow($empty, ['z', 'y']));
		$this->assertSame([], $narrowing->narrow($empty, null));
	}//end testNarrowingWithoutFiltersAnswersTheIds()

	/**
	 * Tokenizer cases: whitespace kinds, brackets, quotes and word boundaries.
	 *
	 * @return array<string, array{0: string}> The terms.
	 */
	public static function tokenizerCases(): array {
		return [
			'tabs and newlines separate words' => ["a\tAND\nb"],
			'brackets end a word' => ['(a)OR(b)'],
			'a quote ends a word' => ['a"b c" AND d'],
			'multibyte words' => ['café AND (straße OR "ü x")'],
		];
	}//end tokenizerCases()

	/**
	 * Tokenizing yields the same tree as parsing the canonical spelling.
	 *
	 * @param string $term The term.
	 *
	 * @return void
	 */
	#[DataProvider('tokenizerCases')]
	public function testTokenizerBranches(string $term): void {
		$tree = (new SearchTermParser())->parse($term);

		$this->assertNotNull($tree);
		$this->assertSame(
			self::recordedTrees()[$term],
			json_encode($tree, JSON_UNESCAPED_UNICODE)
		);
	}//end testTokenizerBranches()

	/**
	 * The trees recorded on the code before the refactor.
	 *
	 * @return array<string, string> term => json.
	 */
	private static function recordedTrees(): array {
		return [
			"a\tAND\nb" => '{"type":"and","value":"","children":[{"type":"term","value":"a","children":[],"leadingWildcard":false,"trailingWildcard":false},{"type":"term","value":"b","children":[],"leadingWildcard":false,"trailingWildcard":false}],"leadingWildcard":false,"trailingWildcard":false}',
			'(a)OR(b)' => '{"type":"or","value":"","children":[{"type":"term","value":"a","children":[],"leadingWildcard":false,"trailingWildcard":false},{"type":"term","value":"b","children":[],"leadingWildcard":false,"trailingWildcard":false}],"leadingWildcard":false,"trailingWildcard":false}',
			'a"b c" AND d' => '{"type":"and","value":"","children":[{"type":"term","value":"a","children":[],"leadingWildcard":false,"trailingWildcard":false},{"type":"term","value":"b c","children":[],"leadingWildcard":false,"trailingWildcard":false},{"type":"term","value":"d","children":[],"leadingWildcard":false,"trailingWildcard":false}],"leadingWildcard":false,"trailingWildcard":false}',
			'café AND (straße OR "ü x")' => '{"type":"and","value":"","children":[{"type":"term","value":"café","children":[],"leadingWildcard":false,"trailingWildcard":false},{"type":"or","value":"","children":[{"type":"term","value":"straße","children":[],"leadingWildcard":false,"trailingWildcard":false},{"type":"term","value":"ü x","children":[],"leadingWildcard":false,"trailingWildcard":false}],"leadingWildcard":false,"trailingWildcard":false}],"leadingWildcard":false,"trailingWildcard":false}',
		];
	}//end recordedTrees()

	/**
	 * An unclosed quote and a lone close bracket are refused at their positions.
	 *
	 * @return void
	 */
	public function testTokenizerRefusals(): void {
		$parser = new SearchTermParser();
		foreach (['a AND "b' => 7, 'a AND b)' => 8] as $term => $position) {
			try {
				$parser->parse($term);
				$this->fail('Expected a refusal for ' . $term);
			} catch (SearchTermSyntaxException $e) {
				$this->assertSame($position, $e->getPosition());
			}
		}
	}//end testTokenizerRefusals()

	/**
	 * Build a provider whose scheme lookup answers the given rows.
	 *
	 * @param array $schemeRows  Rows the scheme lookup answers.
	 * @param array $conceptRows Rows the concept search answers, per scheme uuid.
	 *
	 * @return SearchDictionaryProvider The provider.
	 */
	private function provider(array $schemeRows, array $conceptRows): SearchDictionaryProvider {
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $searchQuery) use ($schemeRows, $conceptRows): array {
				if (isset($searchQuery['uri']) === true) {
					return ['results' => ($schemeRows[$searchQuery['uri']] ?? [])];
				}

				return ['results' => ($conceptRows[$searchQuery['inScheme']] ?? [])];
			}
		);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findIdsBySlugs')->willReturn(['vocabulary' => [7]]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findIdsBySlugs')->willReturnCallback(
			static fn (array $slugs): array => array_intersect_key(
				['concept' => [11], 'conceptscheme' => [12]],
				[strtolower($slugs[0]) => true]
			)
		);

		return new SearchDictionaryProvider(
			$objects,
			new SearchDictionaryMappers(registers: $registers, schemas: $schemas),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end provider()

	/**
	 * The scheme uuid is read off the first usable row, in each row shape.
	 *
	 * @return array<string, array{0: array<int, mixed>, 1: string}> scheme rows => expected searched term for 'auto'.
	 */
	public static function schemeRowCases(): array {
		return [
			'no rows: no dictionary' => [[], 'auto'],
			'unusable rows skipped, @self id used' => [['junk', ['@self' => ['id' => '']], ['@self' => ['id' => 'S']]], '(auto OR wagen)'],
			'plain id used' => [[['id' => 'S']], '(auto OR wagen)'],
			'@self null falls to id' => [[['@self' => ['id' => null], 'id' => 'S']], '(auto OR wagen)'],
			'non-string id skipped' => [[['id' => 5]], 'auto'],
			'object with getUuid' => [[new class {
				/**
				 * The uuid.
				 *
				 * @return string The uuid.
				 */
				public function getUuid(): string {
					return 'S';
				}
			},
			],
			'(auto OR wagen)',
			],
			'object without getUuid skipped' => [[new \stdClass(), ['id' => 'S']], '(auto OR wagen)'],
		];
	}//end schemeRowCases()

	/**
	 * Every scheme-row shape resolves (or not) as recorded.
	 *
	 * @param array<int, mixed> $rows     The scheme rows.
	 * @param string            $searched The term searched for 'auto'.
	 *
	 * @return void
	 */
	#[DataProvider('schemeRowCases')]
	public function testSchemeUuidRowShapes(array $rows, string $searched): void {
		$provider = $this->provider(
			[SearchDictionaryProvider::SYNONYM_SCHEME => $rows],
			['S' => [['prefLabel' => ['nl' => 'auto'], 'altLabel' => ['nl' => ['wagen']]]]]
		);

		$this->assertSame($searched, $provider->forLanguage('nl')->expand('auto', 5, 20)->term());
	}//end testSchemeUuidRowShapes()

	/**
	 * Alternate labels are taken only in the asked language, strings only, scalar or list.
	 *
	 * @return void
	 */
	public function testDeclarationLabelsPerLanguage(): void {
		$provider = $this->provider(
			[SearchDictionaryProvider::SYNONYM_SCHEME => [['id' => 'S']]],
			['S' => [
				['prefLabel' => ['nl' => 'auto'], 'altLabel' => ['en' => ['car'], 'nl' => ['wagen', 3, 'kar']]],
				['prefLabel' => ['nl' => 'fiets'], 'altLabel' => ['nl' => 'rijwiel']],
				['prefLabel' => ['en' => 'boat'], 'altLabel' => ['nl' => ['boot', 'schip']]],
				['prefLabel' => ['nl' => '  '], 'altLabel' => ['nl' => ['x', 'y']]],
				['prefLabel' => 'auto', 'altLabel' => ['nl' => ['x']]],
				['prefLabel' => ['nl' => 'huis']],
			],
			]
		);

		$this->assertSame(
			'(auto OR wagen OR kar) (fiets OR rijwiel) boot x huis',
			$provider->forLanguage('nl')->expand('auto fiets boot x huis', 5, 20)->term()
		);
	}//end testDeclarationLabelsPerLanguage()
}//end class
