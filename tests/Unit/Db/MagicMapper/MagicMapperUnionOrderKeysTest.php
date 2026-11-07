<?php

/**
 * The order keys of a cross-schema search, read through MagicMapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder;
use OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionPage
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 */
class MagicMapperUnionOrderKeysTest extends TestCase {
	private function call(string $method, array $args): mixed {
		$mapper = (new ReflectionClass(MagicMapper::class))->newInstanceWithoutConstructor();

		return (new ReflectionMethod(MagicMapper::class, $method))->invokeArgs($mapper, $args);
	}//end call()

	private function keys(array $query): array {
		return $this->call('buildUnionOrderKeys', ['query' => $query, 'isPostgres' => true]);
	}//end keys()

	public function testAnEmptySearchTermHasNoScoreKey(): void {
		$this->assertSame(['_uuid'], array_column($this->keys(['_search' => '']), 'row'));
	}//end testAnEmptySearchTermHasNoScoreKey()

	public function testRelevanceIsTheScoreWithADirection(): void {
		$keys = $this->keys(['_search' => 'x', '_order' => ['_relevance' => 'asc']]);

		$this->assertSame(['row' => '_search_score', 'sql' => '_search_score', 'dir' => 'ASC'], $keys[0]);
	}//end testRelevanceIsTheScoreWithADirection()

	public function testRelevanceWithoutASearchTermIsLeftOut(): void {
		$this->assertSame(['_uuid'], array_column($this->keys(['_order' => ['_relevance' => 'DESC']]), 'row'));
	}//end testRelevanceWithoutASearchTermIsLeftOut()

	public function testAnUnderscoreFieldStaysUnquoted(): void {
		$this->assertSame(['row' => '_name', 'sql' => '_name', 'dir' => 'DESC'], $this->keys(['_order' => ['_name' => 'desc']])[0]);
	}//end testAnUnderscoreFieldStaysUnquoted()

	public function testAPropertyFieldIsQuotedOnItsColumn(): void {
		$this->assertSame(['row' => 'first_name', 'sql' => '"first_name"', 'dir' => 'ASC'], $this->keys(['_order' => ['firstName' => 'up']])[0]);
	}//end testAPropertyFieldIsQuotedOnItsColumn()

	public function testEveryFormOfOrderGivesTheSameKeys(): void {
		$expected = [['row' => 'title', 'sql' => '"title"', 'dir' => 'DESC'], ['row' => '_uuid', 'sql' => '_uuid', 'dir' => 'ASC']];

		foreach ([['title' => 'desc'], '{"title":"desc"}', '[{"field":"title","direction":"DESC"}]', ['title:desc'], 'title:desc'] as $order) {
			$this->assertSame($expected, $this->keys(['_order' => $order]), json_encode($order));
		}
	}//end testEveryFormOfOrderGivesTheSameKeys()

	public function testADigitOnlyFieldIsAColumn(): void {
		$this->assertSame('"7"', $this->keys(['_order' => [7 => 'asc']])[0]['sql']);
	}//end testADigitOnlyFieldIsAColumn()

	public function testASequentialSearchReadsTheFieldsOfEveryForm(): void {
		$order = new MagicSequentialOrder(query: ['_order' => '[{"field":"firstName","direction":"DESC"}]']);

		$this->assertSame(['firstName' => 'DESC', '@self.uuid' => 'ASC'], $order->tableOrder());
	}//end testASequentialSearchReadsTheFieldsOfEveryForm()

	private function orderable(mixed $order, array $columns): array {
		return (new MagicUnionOrder(isPostgres: false))->orderable(query: ['_order' => $order], columns: $columns)['_order'];
	}//end orderable()

	public function testAQueryWithoutAnOrderStaysAsItIs(): void {
		$query = ['_search' => 'x'];

		$this->assertSame($query, (new MagicUnionOrder(isPostgres: false))->orderable(query: $query, columns: []));
	}//end testAQueryWithoutAnOrderStaysAsItIs()

	public function testAnUnknownFieldIsLeftOut(): void {
		$order = ['_foo' => 'desc', 'nope' => 'asc', '.name' => 'asc', 'firstName' => 'desc'];

		$this->assertSame(['firstName' => 'DESC'], $this->orderable(order: $order, columns: ['first_name' => 'firstName']));
	}//end testAnUnknownFieldIsLeftOut()

	public function testWhatAUnionKnowsStays(): void {
		$order = ['_name' => 'asc', '@self.created' => 'desc', '_relevance' => 'desc', '_search_score' => 'asc'];

		$this->assertSame(
			['_name' => 'ASC', '@self.created' => 'DESC', '_relevance' => 'DESC', '_search_score' => 'ASC'],
			$this->orderable(order: $order, columns: [])
		);
	}//end testWhatAUnionKnowsStays()

	public function testAPropertyCountsUnderItsExactNameOnly(): void {
		$columns = ['first_name' => 'firstName'];

		$this->assertSame(['_uuid' => 'ASC'], $this->orderable(order: ['first_name' => 'asc'], columns: $columns));
		$this->assertSame(['_uuid' => 'ASC'], $this->orderable(order: ['FirstName' => 'asc'], columns: $columns));
	}//end testAPropertyCountsUnderItsExactNameOnly()

	public function testAPropertyThatStartsWithAnUnderscoreStays(): void {
		$this->assertSame(['_own' => 'ASC'], $this->orderable(order: ['_own' => 'asc'], columns: ['_own' => '_own']));
	}//end testAPropertyThatStartsWithAnUnderscoreStays()

	public function testADigitOnlyPropertyStays(): void {
		$this->assertSame([7 => 'DESC'], $this->orderable(order: [7 => 'desc'], columns: ['7' => 7]));
	}//end testADigitOnlyPropertyStays()

	public function testARequestLeftWithNothingOrdersOnTheUuidNotTheScore(): void {
		$query = (new MagicUnionOrder(isPostgres: false))->orderable(query: ['_search' => 'x', '_order' => ['nope' => 'asc']], columns: []);

		$this->assertSame(['_uuid'], array_column($this->keys($query), 'row'));
	}//end testARequestLeftWithNothingOrdersOnTheUuidNotTheScore()

	public function testNoOrderAskedStaysNoOrder(): void {
		$this->assertSame([], $this->orderable(order: [], columns: []));
	}//end testNoOrderAskedStaysNoOrder()

	public function testAnOrderOnTheUuidIsNotClosedTwice(): void {
		$this->assertSame([['row' => '_uuid', 'sql' => '_uuid', 'dir' => 'DESC']], $this->keys(['_order' => ['_uuid' => 'desc']]));
	}//end testAnOrderOnTheUuidIsNotClosedTwice()

	public function testAMissingColumnSortsBeforeAZero(): void {
		$rows = [['_uuid' => 'a', 'rank' => 0], ['_uuid' => 'b']];

		$merged = $this->call('mergeUnionBatchRows', ['rows' => $rows, 'query' => ['_order' => ['rank' => 'ASC']], 'isPostgres' => false]);

		$this->assertSame(['b', 'a'], array_column($merged, '_uuid'));
	}//end testAMissingColumnSortsBeforeAZero()
}//end class
