<?php

/**
 * Unit tests for ConditionGroupParser (list-query-condition-groups task 1.1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\ConditionGroup;
use OCA\OpenRegister\Db\MagicMapper\ConditionGroupParser;
use OCA\OpenRegister\Db\MagicMapper\ConditionLeaf;
use OCA\OpenRegister\Exception\ConditionGroupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The parser turns `_where` into a validated tree with node paths, and refuses
 * a malformed one (design D-3) with the path of the row that failed.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\ConditionGroupParser
 * @uses \OCA\OpenRegister\Db\MagicMapper\ConditionGroup
 * @uses \OCA\OpenRegister\Db\MagicMapper\ConditionLeaf
 * @uses \OCA\OpenRegister\Exception\ConditionGroupException
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */
class ConditionGroupParserTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var ConditionGroupParser
	 */
	private ConditionGroupParser $parser;

	protected function setUp(): void {
		$this->parser = new ConditionGroupParser();
	}//end setUp()

	public function testTheDesignExampleParsesIntoGroupsAndLeavesWithPaths(): void {
		$where = [
			'type' => 'AND',
			'children' => [
				['field' => 'place', 'operator' => 'eq', 'value' => 'Zuiddrecht'],
				[
					'type' => 'OR',
					'children' => [
						['field' => 'industry', 'operator' => 'in', 'value' => ['horeca']],
						['field' => 'industry', 'operator' => 'in', 'value' => ['detailhandel']],
					],
				],
			],
		];

		$root = $this->parser->parse($where);

		$this->assertSame('AND', $root->type);
		$this->assertSame('$', $root->path);
		$this->assertInstanceOf(ConditionLeaf::class, $root->children[0]);
		$this->assertSame('$.children[0]', $root->children[0]->path);
		$this->assertSame('place', $root->children[0]->field);

		$or = $root->children[1];
		$this->assertInstanceOf(ConditionGroup::class, $or);
		$this->assertSame('OR', $or->type);
		$this->assertSame('$.children[1]', $or->path);
		$this->assertSame('$.children[1].children[0]', $or->children[0]->path);
		$this->assertCount(3, $root->leaves());
	}//end testTheDesignExampleParsesIntoGroupsAndLeavesWithPaths()

	public function testAJsonStringIsDecoded(): void {
		$root = $this->parser->parse('{"type":"OR","children":[{"field":"place","value":"A"}]}');

		$this->assertSame('OR', $root->type);
		$this->assertSame('eq', $root->children[0]->operator);
	}//end testAJsonStringIsDecoded()

	public function testEqIsTheDefaultOperatorAndLeafBecomesAFlatFilter(): void {
		$leaf = $this->parser->parse(['type' => 'AND', 'children' => [['field' => 'place', 'value' => 'A']]])->children[0];

		$this->assertSame('eq', $leaf->operator);
		$this->assertSame(['place' => 'A'], $leaf->asFlatFilter());
	}//end testEqIsTheDefaultOperatorAndLeafBecomesAFlatFilter()

	public function testANonEqLeafWrapsTheOperatorInItsFlatFilter(): void {
		$leaf = $this->parser->parse(['type' => 'AND', 'children' => [['field' => 'age', 'operator' => 'gte', 'value' => 18]]])->children[0];

		$this->assertSame(['age' => ['gte' => 18]], $leaf->asFlatFilter());
	}//end testANonEqLeafWrapsTheOperatorInItsFlatFilter()

	public function testIsnullNeedsNoValue(): void {
		$leaf = $this->parser->parse(['type' => 'AND', 'children' => [['field' => 'closed', 'operator' => 'isnull']]])->children[0];

		$this->assertSame('isnull', $leaf->operator);
		$this->assertSame(['closed' => ['isnull' => null]], $leaf->asFlatFilter());
	}//end testIsnullNeedsNoValue()

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: string}>
	 */
	public static function refusals(): array {
		$deep = ['field' => 'a', 'value' => 1];
		for ($i = 0; $i < 6; $i++) {
			$deep = ['type' => 'AND', 'children' => [$deep]];
		}

		return [
			'not json' => ['{not json', 'not valid JSON', '$'],
			'not a group' => [['field' => 'place', 'value' => 'A'], 'type', '$'],
			'a scalar' => [42, 'must be a condition group', '$'],
			'unknown type' => [['type' => 'XOR', 'children' => [['field' => 'a', 'value' => 1]]], 'AND" or "OR', '$'],
			'empty children' => [['type' => 'AND', 'children' => []], 'non-empty "children"', '$'],
			'children not a list' => [['type' => 'AND', 'children' => ['a' => 1]], 'non-empty "children"', '$'],
			'leaf without a field' => [['type' => 'AND', 'children' => [['operator' => 'eq', 'value' => 1]]], 'needs a "field"', '$.children[0]'],
			'unknown operator' => [
				['type' => 'AND', 'children' => [['field' => 'a', 'operator' => 'between', 'value' => 1]]],
				'not a condition operator',
				'$.children[0]',
			],
			'leaf without a value' => [['type' => 'AND', 'children' => [['field' => 'a', 'operator' => 'gte']]], 'needs a "value"', '$.children[0]'],
			'too deep' => [$deep, 'nested deeper', '$.children[0].children[0].children[0].children[0].children[0]'],
			'nested path named' => [
				['type' => 'AND', 'children' => [['field' => 'a', 'value' => 1], ['type' => 'OR', 'children' => [['operator' => 'eq', 'value' => 1]]]]],
				'needs a "field"',
				'$.children[1].children[0]',
			],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testAMalformedTreeIsRefusedWithItsPath(mixed $where, string $messageFragment, string $path): void {
		try {
			$this->parser->parse($where);
			$this->fail('expected the parse to be refused');
		} catch (ConditionGroupException $e) {
			$this->assertStringContainsString($messageFragment, $e->getMessage());
			$this->assertSame($path, $e->getPath());
			$this->assertSame(['error' => $e->getMessage(), 'path' => $path], $e->toResponseBody());
		}
	}//end testAMalformedTreeIsRefusedWithItsPath()

	public function testMoreThanTwoHundredLeavesIsRefused(): void {
		$children = [];
		for ($i = 0; $i <= ConditionGroupParser::MAX_LEAVES; $i++) {
			$children[] = ['field' => 'a', 'value' => $i];
		}

		$this->expectException(ConditionGroupException::class);
		$this->parser->parse(['type' => 'AND', 'children' => $children]);
	}//end testMoreThanTwoHundredLeavesIsRefused()

	public function testExactlyTwoHundredLeavesIsAllowed(): void {
		$children = [];
		for ($i = 0; $i < ConditionGroupParser::MAX_LEAVES; $i++) {
			$children[] = ['field' => 'a', 'value' => $i];
		}

		$this->assertCount(ConditionGroupParser::MAX_LEAVES, $this->parser->parse(['type' => 'AND', 'children' => $children])->leaves());
	}//end testExactlyTwoHundredLeavesIsAllowed()

	public function testFiveGroupsDeepIsAllowedAndSixIsNot(): void {
		$leaf = ['field' => 'a', 'value' => 1];
		$atDepth = static function (int $depth) use ($leaf): array {
			$node = $leaf;
			for ($i = 0; $i < $depth; $i++) {
				$node = ['type' => 'AND', 'children' => [$node]];
			}

			return $node;
		};

		$this->assertCount(1, $this->parser->parse($atDepth(5))->leaves());

		$this->expectException(ConditionGroupException::class);
		$this->parser->parse($atDepth(6));
	}//end testFiveGroupsDeepIsAllowedAndSixIsNot()

	/**
	 * The parser's operator set must stay the handler's COMPARISON_OPERATORS
	 * plus `eq`, so a leaf and the equivalent flat filter accept the same
	 * operators. If the handler's list changes, this fails rather than letting
	 * the two drift.
	 *
	 * @return void
	 */
	public function testTheOperatorSetMatchesTheHandlersComparisonOperators(): void {
		$handler = new ReflectionClass('OCA\\OpenRegister\\Db\\MagicMapper\\MagicSearchHandler');
		$comparison = $handler->getConstant('COMPARISON_OPERATORS');

		$expected = array_merge([ConditionGroupParser::OPERATOR_EQ], $comparison);
		sort($expected);
		$actual = ConditionGroupParser::OPERATORS;
		sort($actual);

		$this->assertSame($expected, $actual, 'ConditionGroupParser::OPERATORS drifted from the handler\'s COMPARISON_OPERATORS');
	}//end testTheOperatorSetMatchesTheHandlersComparisonOperators()
}//end class
