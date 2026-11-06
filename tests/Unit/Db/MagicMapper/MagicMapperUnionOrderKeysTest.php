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
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
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

	public function testAMissingColumnSortsBeforeAZero(): void {
		$rows = [['_uuid' => 'a', 'rank' => 0], ['_uuid' => 'b']];

		$merged = $this->call('mergeUnionBatchRows', ['rows' => $rows, 'query' => ['_order' => ['rank' => 'ASC']], 'isPostgres' => false]);

		$this->assertSame(['b', 'a'], array_column($merged, '_uuid'));
	}//end testAMissingColumnSortsBeforeAZero()
}//end class
