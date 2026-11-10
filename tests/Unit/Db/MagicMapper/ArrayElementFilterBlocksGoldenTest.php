<?php

/**
 * Golden test for ArrayElementFilter::blocks() and ::condition(): the blocks a
 * query yields, every refusal message, and the SQL on both platforms.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\ArrayElementFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins blocks() and condition() of ArrayElementFilter.
 */
class ArrayElementFilterBlocksGoldenTest extends TestCase {

	/**
	 * Several properties, numbered and unnumbered, keep their order.
	 *
	 * @return void
	 */
	public function testBlocksKeepTheirOrder(): void {
		$blocks = (new ArrayElementFilter())->blocks(
			[
				'_element' => [
					'a' => ['x' => 1, 'y' => ['gte' => 2]],
					'b' => [['p' => 1], ['q' => 2]],
					7   => ['z' => 3],
				],
			]
		);

		$this->assertSame(
			[
				['root' => 'a', 'fields' => ['x' => 1, 'y' => ['gte' => 2]]],
				['root' => 'b', 'fields' => ['p' => 1]],
				['root' => 'b', 'fields' => ['q' => 2]],
				['root' => '7', 'fields' => ['z' => 3]],
			],
			$blocks
		);
	}//end testBlocksKeepTheirOrder()

	/**
	 * The malformed queries and the message each one is refused with.
	 *
	 * @return array<string,array{0: array<string,mixed>, 1: string}>
	 */
	public static function refusals(): array {
		$top   = "'_element' must name an array property and the fields one of its elements carries.";
		$block = static fn (string $root): string => "'_element[{$root}]' must name the fields one of its elements carries.";
		$index = static fn (int $index): string => "'_element' block {$index} must name the fields one element carries.";
		return [
			'scalar'           => [['_element' => 'x'], $top],
			'null'             => [['_element' => null], $top],
			'empty'            => [['_element' => []], $top],
			'scalar block'     => [['_element' => ['a' => 'x']], $block('a')],
			'empty block'      => [['_element' => ['a' => []]], $block('a')],
			'second bad'       => [['_element' => ['a' => ['x' => 1], 'b' => 3]], $block('b')],
			'numbered scalar'  => [['_element' => ['a' => [['x' => 1], 'y']]], $index(1)],
			'numbered empty'   => [['_element' => ['a' => [[]]]], $index(0)],
			'numbered list'    => [['_element' => ['a' => [[1, 2]]]], $index(0)],
		];
	}//end refusals()

	/**
	 * Each malformed query is refused with its message.
	 *
	 * @param array<string,mixed> $query   The query.
	 * @param string              $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testRefusals(array $query, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		(new ArrayElementFilter())->blocks($query);
	}//end testRefusals()

	/**
	 * The condition on both platforms, an empty block, and a bad second field.
	 *
	 * @return void
	 */
	public function testCondition(): void {
		$filter = new ArrayElementFilter();
		$bind   = static fn (string $value): string => "'{$value}'";

		$this->assertSame(
			"EXISTS (SELECT 1 FROM "
			."json_array_elements(CASE WHEN json_typeof(t.p::json) = 'array' THEN t.p::json ELSE '[]'::json END) AS cn_el(e) WHERE "
			."(cn_el.e #>> '{a}') = '1' AND (cn_el.e #>> '{b}') IN ('2', '3'))",
			$filter->condition('t.p', ['a' => 1, 'b' => [2, 3]], $bind, true)
		);
		$this->assertSame(
			"EXISTS (SELECT 1 FROM "
			."JSON_TABLE(CASE WHEN JSON_TYPE(t.p) = 'ARRAY' THEN t.p ELSE '[]' END, '\$[*]' COLUMNS (e JSON PATH '\$')) AS cn_el WHERE "
			."JSON_UNQUOTE(JSON_EXTRACT(cn_el.e, '$.a')) IS NULL)",
			$filter->condition('t.p', ['a' => null], $bind, false)
		);
		$this->assertSame(
			"EXISTS (SELECT 1 FROM "
			."json_array_elements(CASE WHEN json_typeof(t.p::json) = 'array' THEN t.p::json ELSE '[]'::json END) AS cn_el(e) WHERE "
			.")",
			$filter->condition('t.p', [], $bind, true)
		);
		$this->assertNull($filter->condition('t.p', ['a' => 1, 'b c' => 2], $bind, false));
	}//end testCondition()
}//end class
