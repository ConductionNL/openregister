<?php

/**
 * What a search response reports beside its results: the history predicate
 * when it narrows or could not be read, the dictionary expansion when it is
 * reportable, the recent lens, the registers and schemas the caller asked
 * to include, and a hint when a control parameter was used as a filter.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SearchResponseReports;
use OCA\OpenRegister\Service\Search\DictionaryExpansion;
use OCA\OpenRegister\Service\Search\HistoryPredicate;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SearchResponseReports}.
 */
class SearchResponseReportsTest extends TestCase {

	/**
	 * The reports under test.
	 *
	 * @var SearchResponseReports
	 */
	private SearchResponseReports $reports;

	/**
	 * Build the reports.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reports = new SearchResponseReports();
	}//end setUp()

	/**
	 * A plain search reports nothing; a narrowing history predicate, a
	 * reportable expansion and a recent lens are each reported.
	 *
	 * @return void
	 */
	public function testSearchReportsOnlyWhatShapedTheSearch(): void {
		$this->assertSame([], $this->reports->searchReports(query: [], historyPredicate: HistoryPredicate::parse(query: []), expansion: DictionaryExpansion::unchanged(term: 'a')));
		$this->assertSame([], $this->reports->searchReports(query: ['_recentLens' => 'x'], historyPredicate: HistoryPredicate::parse(query: []), expansion: null));

		$reports = $this->reports->searchReports(
			query: ['_recentLens' => ['days' => 7]],
			historyPredicate: HistoryPredicate::parse(query: ['_was_ever' => ['status' => 'open']]),
			expansion: DictionaryExpansion::expanded(term: 'a b', original: 'a', added: ['b'], removed: [])
		);

		$this->assertSame(['status' => 'open'], $reports['history']['wasEver']);
		$this->assertSame(['b'], $reports['dictionary']['added']);
		$this->assertSame(['recent' => ['days' => 7]], $reports['lenses']);
	}//end testSearchReportsOnlyWhatShapedTheSearch()

	/**
	 * A history predicate that could not be read is reported even though it
	 * narrows nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableHistoryPredicateIsReported(): void {
		$reports = $this->reports->searchReports(query: [], historyPredicate: HistoryPredicate::parse(query: ['_changed_between' => ['x' => 'y']]), expansion: null);

		$this->assertSame(['_changed_between[x]'], $reports['history']['unparsed']);
	}//end testAnUnreadableHistoryPredicateIsReported()

	/**
	 * Registers and schemas are included only when asked for by any
	 * spelling (string or list `_extend`) and only when the result has them.
	 *
	 * @return void
	 */
	public function testIncludedRegistersAndSchemasFollowTheExtendList(): void {
		$result = ['registers' => [['id' => 4]], 'schemas' => [['id' => 9]]];

		$this->assertSame(['registers' => [['id' => 4]], 'schemas' => [['id' => 9]]], $this->reports->requestedRegistersAndSchemas(query: ['_extend' => '_register,@self.schemas'], searchResult: $result));
		$this->assertSame(['schemas' => [['id' => 9]]], $this->reports->requestedRegistersAndSchemas(query: ['_extend' => ['_schemas']], searchResult: $result));
		$this->assertSame([], $this->reports->requestedRegistersAndSchemas(query: ['_extend' => ['_registers']], searchResult: ['registers' => []]));
		$this->assertSame([], $this->reports->requestedRegistersAndSchemas(query: [], searchResult: $result));
	}//end testIncludedRegistersAndSchemasFollowTheExtendList()

	/**
	 * Ignored filters are reported; a control parameter among them gets a
	 * hint naming its underscored form; none ignored reports nothing.
	 *
	 * @return void
	 */
	public function testIgnoredFiltersAreReportedWithAHintForControlParameters(): void {
		$this->assertSame([], $this->reports->ignoredFilterReport(ignoredFilters: []));
		$this->assertSame(['ignoredFilters' => ['colour']], $this->reports->ignoredFilterReport(ignoredFilters: ['colour']));

		$report = $this->reports->ignoredFilterReport(ignoredFilters: ['colour', 'limit', 'sort']);

		$this->assertSame(
			'Query returned 0 results because limit, sort was treated as a property filter. Did you mean _limit, _sort? Control params require underscore prefix.',
			$report['hint']
		);
	}//end testIgnoredFiltersAreReportedWithAHintForControlParameters()
}//end class
