<?php

/**
 * MagicFacetHandlerReferenceFacetTest: `_facets[_ref][<property>][<field>]` on one table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableTarget;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The facet handler asks the search handler for the joined, filtered query of
 * each referenced field, counts its groups into buckets under
 * `_ref.<property>.<field>`, and leaves the other facets as they were.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ReferenceFacetConfig
 * @uses   \OCA\OpenRegister\Db\MagicMapper\FacetResults
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableTarget
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TermsFacetTable
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicFacetHandlerReferenceFacetTest extends TestCase {

	/**
	 * The search handler the facet handler builds its queries with.
	 *
	 * @var MagicSearchHandler&MockObject
	 */
	private MagicSearchHandler&MockObject $search;

	/**
	 * Set up the search handler mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->search = $this->createMock(MagicSearchHandler::class);
	}//end setUp()

	/**
	 * The facet target of the request table.
	 *
	 * @return MagicTableTarget
	 */
	private static function target(): MagicTableTarget {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(7);
		$target            = new MagicTableTarget(register: $register, schema: $schema);
		$target->tableName = 'openregister_table_1_7';

		return $target;
	}//end target()

	/**
	 * A grouped query whose rows are the given ones, recording how it was limited.
	 *
	 * @param array<int, array{facet_value: mixed, doc_count: int}> $rows  The rows.
	 * @param array<int, string>                                    $calls The shared call log.
	 *
	 * @return IQueryBuilder
	 */
	private function grouped(array $rows, array &$calls): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return (array_shift($rows) ?? false);
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('orderBy')->willReturnCallback(static function (mixed $sort, mixed $order) use (&$calls, $qb): IQueryBuilder {
			$calls[] = 'orderBy ' . $sort . ' ' . $order;
			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(static function (?int $max) use (&$calls, $qb): IQueryBuilder {
			$calls[] = 'setMaxResults ' . $max;
			return $qb;
		});
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}//end grouped()

	/**
	 * The handler under test, with the search handler mock.
	 *
	 * @return MagicFacetHandler
	 */
	private function handler(): MagicFacetHandler {
		return new MagicFacetHandler($this->createMock(IDBConnection::class), new NullLogger(), $this->search, null);
	}//end handler()

	/**
	 * A facet over the applicant's place counts requests per place, with the missing count and the title.
	 *
	 * @return void
	 */
	public function testAReferenceFacetCountsTheReferencedValues(): void {
		$calls = [];
		$this->search->expects($this->once())->method('referenceFacetQuery')
			->with(
				['woonplaats' => 'x'],
				$this->callback(static fn (MagicReadTable $table): bool => $table->registerId === 1 && $table->tableName === 'openregister_table_1_7' && $table->schema->getId() === 7),
				'aanvrager',
				'woonplaats'
			)
			->willReturn(
				$this->grouped(
					rows: [
						['facet_value' => 'Zuiddrecht', 'doc_count' => 4],
						['facet_value' => null, 'doc_count' => 2],
						['facet_value' => 'Oosterhout', 'doc_count' => 1],
					],
					calls: $calls
				)
			);

		$facets = $this->handler()->getSimpleFacets(
			target: self::target(),
			query: ['woonplaats' => 'x', '_facets' => ['_ref' => ['aanvrager' => ['woonplaats' => ['type' => 'terms', 'title' => 'Woonplaats']]]]]
		);

		$this->assertSame(
			[
				'type' => 'terms',
				'buckets' => [
					['key' => 'Zuiddrecht', 'results' => 4, 'label' => 'Zuiddrecht'],
					['key' => 'Oosterhout', 'results' => 1, 'label' => 'Oosterhout'],
				],
				'missing' => ['results' => 2],
				'title' => 'Woonplaats',
			],
			$facets['_ref']['aanvrager']['woonplaats']
		);
		$this->assertSame(['orderBy doc_count DESC', 'setMaxResults 10000'], $calls);
		$this->assertArrayHasKey('_ref.aanvrager.woonplaats', $facets['_metrics']['per_facet_ms']);
	}//end testAReferenceFacetCountsTheReferencedValues()

	/**
	 * A referenced field holding a list counts each element once.
	 *
	 * @return void
	 */
	public function testAListValueOfTheReferencedFieldIsSplitIntoBuckets(): void {
		$calls = [];
		$this->search->method('referenceFacetQuery')->willReturn(
			$this->grouped(rows: [['facet_value' => '["a","b"]', 'doc_count' => 2], ['facet_value' => 'a', 'doc_count' => 1]], calls: $calls)
		);

		$facets = $this->handler()->getSimpleFacets(target: self::target(), query: ['_facets' => ['_ref' => ['aanvrager' => ['labels']]]]);

		$this->assertSame(
			[['key' => 'a', 'results' => 3, 'label' => 'a'], ['key' => 'b', 'results' => 2, 'label' => 'b']],
			$facets['_ref']['aanvrager']['labels']['buckets']
		);
	}//end testAListValueOfTheReferencedFieldIsSplitIntoBuckets()

	/**
	 * A field the caller may not read is omitted, not answered with empty buckets.
	 *
	 * @return void
	 */
	public function testAnUnreadableReferencedFieldIsOmitted(): void {
		$this->search->method('referenceFacetQuery')->willReturn(null);

		$facets = $this->handler()->getSimpleFacets(target: self::target(), query: ['_facets' => ['_ref' => ['aanvrager' => ['woonplaats']]]]);

		$this->assertArrayNotHasKey('_ref', $facets);
	}//end testAnUnreadableReferencedFieldIsOmitted()

	/**
	 * A refusal from the search handler travels; it is not turned into an empty facet.
	 *
	 * @return void
	 */
	public function testARefusalTravels(): void {
		$this->search->method('referenceFacetQuery')->willThrowException(new InvalidArgumentException("_facets[_ref][aanvrager]: the referenced schema has no field 'woonplaatz'."));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("no field 'woonplaatz'");
		$this->handler()->getSimpleFacets(target: self::target(), query: ['_facets' => ['_ref' => ['aanvrager' => ['woonplaatz']]]]);
	}//end testARefusalTravels()

	/**
	 * Without a search handler there is no filtered query to join through, so the facet is refused.
	 *
	 * @return void
	 */
	public function testWithoutASearchHandlerAReferenceFacetIsRefused(): void {
		$handler = new MagicFacetHandler($this->createMock(IDBConnection::class), new NullLogger(), null, null);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A facet through a reference is not available on this route.');
		$handler->getSimpleFacets(target: self::target(), query: ['_facets' => ['_ref' => ['aanvrager' => ['woonplaats']]]]);
	}//end testWithoutASearchHandlerAReferenceFacetIsRefused()

	/**
	 * A facet request across several schemas carrying `_ref` is refused before any query runs.
	 *
	 * @return void
	 */
	public function testAReferenceFacetAcrossSeveralSchemasIsRefused(): void {
		$this->search->expects($this->never())->method('referenceFacetQuery');
		$target = self::target();

		$this->expectException(RelatedRowFilterNotSupportedException::class);
		$this->handler()->getSimpleFacetsUnion(
			tableConfigs: [['tableName' => 'openregister_table_1_7', 'register' => $target->register, 'schema' => $target->schema]],
			query: ['_facets' => ['_ref' => ['aanvrager' => ['woonplaats']]]]
		);
	}//end testAReferenceFacetAcrossSeveralSchemasIsRefused()
}//end class
