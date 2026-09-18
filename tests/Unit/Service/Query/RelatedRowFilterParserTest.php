<?php

/**
 * Reading `_related[<schema>][<fk>]` out of a query.
 *
 * 🔴 A FILTER THAT IS QUIETLY DROPPED ANSWERS THE UNFILTERED SET. That is the
 * failure this parser is shaped against, and it is the expensive direction: a
 * misspelt block returns every case in the register, presented as the answer to
 * a narrow question, and the reader has no way to tell. So every malformed
 * shape below is asserted to THROW, not to be skipped.
 *
 * 🔑 THE SEMANTIC IS "ONE ROW THAT IS ALL OF THESE", not "rows that are each of
 * these", and the numeric-suffix tests are what pin it. Merging two numbered
 * blocks into one would ask for a single row satisfying both, which no row
 * satisfies, so the caller gets an empty list and no explanation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Query\RelatedRowFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * The wire format, and everything it refuses.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 */
class RelatedRowFilterParserTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var RelatedRowFilterParser
	 */
	private RelatedRowFilterParser $parser;

	/**
	 * Build the parser.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->parser = new RelatedRowFilterParser();
	}//end setUp()

	/**
	 * A query with no block parses to nothing, and costs nothing.
	 *
	 * The control, and the backwards-compatibility promise: every query that
	 * worked yesterday still parses to an empty list.
	 *
	 * @return void
	 */
	public function testAQueryWithoutABlockParsesToNothing(): void {
		$this->assertSame([], $this->parser->parse(query: []));
		$this->assertSame([], $this->parser->parse(query: ['_limit' => 50, 'title' => 'x']));
	}//end testAQueryWithoutABlockParsesToNothing()

	/**
	 * The worked example: a case carrying one typed property.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function testOneBlockWithOneCondition(): void {
		$filters = $this->parser->parse(
			query: ['_related' => ['caseProperty' => ['case' => ['propertyDefinition' => 'pd-7']]]]
		);

		$this->assertCount(1, $filters);
		$this->assertSame('caseProperty', $filters[0]->schema);
		$this->assertSame('case', $filters[0]->foreignKey);
		$this->assertSame(
			[['field' => 'propertyDefinition', 'operator' => 'eq', 'value' => 'pd-7']],
			$filters[0]->conditions
		);
	}//end testOneBlockWithOneCondition()

	/**
	 * Two conditions in one block are ONE row that is both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function testTwoConditionsInOneBlockAreOneRow(): void {
		$filters = $this->parser->parse(
			query: [
				'_related' => [
					'caseProperty' => [
						'case' => [
							'propertyDefinition' => 'pd-7',
							'value' => ['gte' => '100'],
						],
					],
				],
			]
		);

		$this->assertCount(1, $filters, 'two conditions on one row are ONE existence clause');
		$this->assertSame(
			[
				['field' => 'propertyDefinition', 'operator' => 'eq', 'value' => 'pd-7'],
				['field' => 'value', 'operator' => 'gte', 'value' => '100'],
			],
			$filters[0]->conditions
		);
	}//end testTwoConditionsInOneBlockAreOneRow()

	/**
	 * Numbered blocks are TWO rows, and stay two.
	 *
	 * 🔴 THE ASSERTION THAT STOPS THE MERGE. Collapsing these into one block
	 * asks for a single row that is both property definitions, which no row is,
	 * so the caller gets an empty list and no explanation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function testNumberedBlocksAreTwoRows(): void {
		$filters = $this->parser->parse(
			query: [
				'_related' => [
					'caseProperty' => [
						'case' => [
							0 => ['propertyDefinition' => 'pd-7'],
							1 => ['propertyDefinition' => 'pd-9'],
						],
					],
				],
			]
		);

		$this->assertCount(2, $filters);
		$this->assertSame('pd-7', $filters[0]->conditions[0]['value']);
		$this->assertSame('pd-9', $filters[1]->conditions[0]['value']);
		// Both still name the same schema and key: two rows of one relation.
		$this->assertSame('caseProperty', $filters[1]->schema);
		$this->assertSame('case', $filters[1]->foreignKey);
	}//end testNumberedBlocksAreTwoRows()

	/**
	 * A bare list is the `in` shorthand.
	 *
	 * @return void
	 */
	public function testABareListIsAnInCondition(): void {
		$filters = $this->parser->parse(
			query: ['_related' => ['caseProperty' => ['case' => ['value' => ['a', 'b']]]]]
		);

		$this->assertSame(
			[['field' => 'value', 'operator' => 'in', 'value' => ['a', 'b']]],
			$filters[0]->conditions
		);
	}//end testABareListIsAnInCondition()

	/**
	 * A comma-separated `in` from a query string becomes a list.
	 *
	 * A query string cannot carry an array for `value[in]=a,b`, and a parser
	 * that took the string whole would compare one field against the literal
	 * "a,b" and match nothing, silently.
	 *
	 * @return void
	 */
	public function testACommaSeparatedInBecomesAList(): void {
		$filters = $this->parser->parse(
			query: ['_related' => ['caseProperty' => ['case' => ['value' => ['in' => 'a, b']]]]]
		);

		$this->assertSame(['a', 'b'], $filters[0]->conditions[0]['value']);
	}//end testACommaSeparatedInBecomesAList()

	/**
	 * Every malformed shape THROWS rather than being dropped.
	 *
	 * 🔴 THE POINT OF THE WHOLE FILE. Each of these, skipped instead of
	 * refused, answers the unfiltered set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function testEveryMalformedBlockIsRefused(): void {
		$cases = [
			'not an object' => ['_related' => 'caseProperty'],
			'empty' => ['_related' => []],
			'no foreign key' => ['_related' => ['caseProperty' => []]],
			'foreign key with no conditions' => ['_related' => ['caseProperty' => ['case' => []]]],
			'unknown operator' => [
				'_related' => ['caseProperty' => ['case' => ['value' => ['like' => 'x%']]]],
			],
			'numbered and bare mixed' => [
				'_related' => [
					'caseProperty' => ['case' => [0 => ['a' => 1], 'b' => 2]],
				],
			],
			'numbered but empty' => [
				'_related' => ['caseProperty' => ['case' => [0 => []]]],
			],
		];

		foreach ($cases as $name => $query) {
			try {
				$this->parser->parse(query: $query);
				$this->fail(sprintf('"%s" must be refused, not dropped: a dropped filter answers everything', $name));
			} catch (InvalidArgumentException $refusal) {
				$this->assertNotSame('', $refusal->getMessage(), $name . ' must say what is wrong');
			}
		}
	}//end testEveryMalformedBlockIsRefused()

	/**
	 * The operators are the ones the object query already accepts.
	 *
	 * A filter over a related row is not a second query language. A caller who
	 * learned `gte` on the object's own fields must not have to learn something
	 * else here, and this is what says the two lists have not drifted.
	 *
	 * @return void
	 */
	public function testTheOperatorsAreTheOnesTheQueryAlreadyAccepts(): void {
		$sql = (string)file_get_contents(
			__DIR__ . '/../../../../lib/Db/ObjectHandlers/MariaDbSearchHandler.php'
		);

		foreach (RelatedRowFilterParser::OPERATORS as $operator) {
			if ($operator === 'in') {
				// `in` is a list membership rather than a binary operator, and
				// the handler builds it elsewhere.
				continue;
			}

			$this->assertStringContainsString(
				sprintf("'%s' =>", $operator),
				$sql,
				sprintf(
					'operator %s is accepted here and is not in the query handler\'s operator map, '
					. 'so this parser invented a second query language',
					$operator
				)
			);
		}
	}//end testTheOperatorsAreTheOnesTheQueryAlreadyAccepts()
}//end class
