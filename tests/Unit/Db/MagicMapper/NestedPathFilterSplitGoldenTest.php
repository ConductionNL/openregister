<?php

/**
 * Golden test for NestedPathFilter::split() and ::expression(): which filters
 * become path filters, in which order, and which paths are refused.
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
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\NestedPathFilter;
use OCA\OpenRegister\Db\MagicMapper\NestedPathTarget;
use PHPUnit\Framework\TestCase;

/**
 * Pins split() and expression() across the spellings a filter arrives in.
 */
class NestedPathFilterSplitGoldenTest extends TestCase {

	/**
	 * The schema properties the filters are split against.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'title'   => ['type' => 'string'],
		'dunning' => ['type' => 'object'],
		'tags'    => ['type' => 'array'],
		'broken'  => 'object',
	];

	/**
	 * Every spelling, in one map, keeps its order and lands on the right side.
	 *
	 * @return void
	 */
	public function testSplitSortsEverySpelling(): void {
		[$plain, $nested] = (new NestedPathFilter())->split(
			[
				'title'             => 'x',
				'title.sub'         => 'y',
				'tags.first'        => 'z',
				'broken.a'          => 1,
				'dunning'           => ['stage' => ['gte' => 2], 'meta' => ['level' => ['deep' => true]], 7 => 'n'],
				'dunning.list'      => [1, 2],
				'dunning.bag'       => ['in' => [1]],
				'dunning.map'       => ['a' => 'b'],
				'dunning.empty'     => [],
				'unknown.path'      => 3,
				0                   => 'zero',
			],
			self::PROPERTIES
		);

		$this->assertSame(
			[
				'title'        => 'x',
				'title.sub'    => 'y',
				'tags.first'   => 'z',
				'broken.a'     => 1,
				'unknown.path' => 3,
				0              => 'zero',
			],
			$plain
		);
		$this->assertSame(
			[
				['root' => 'dunning', 'path' => ['stage'], 'value' => ['gte' => 2]],
				['root' => 'dunning', 'path' => ['meta', 'level', 'deep'], 'value' => true],
				['root' => 'dunning', 'path' => ['7'], 'value' => 'n'],
				['root' => 'dunning', 'path' => ['list'], 'value' => [1, 2]],
				['root' => 'dunning', 'path' => ['bag'], 'value' => ['in' => [1]]],
				['root' => 'dunning', 'path' => ['map', 'a'], 'value' => 'b'],
				['root' => 'dunning', 'path' => ['empty'], 'value' => []],
			],
			$nested
		);
	}//end testSplitSortsEverySpelling()

	/**
	 * An object property's own value that is not a path map stays a column filter.
	 *
	 * @return void
	 */
	public function testAnObjectRootWithoutAPathMapStaysPlain(): void {
		$filters = [
			'dunning' => ['gte' => 2, 'stage' => 1],
		];
		$this->assertSame([$filters, []], (new NestedPathFilter())->split($filters, self::PROPERTIES));

		foreach ([[], [1, 2], 'IS NULL', null, 5] as $value) {
			$this->assertSame([['dunning' => $value], []], (new NestedPathFilter())->split(['dunning' => $value], self::PROPERTIES));
		}
	}//end testAnObjectRootWithoutAPathMapStaysPlain()

	/**
	 * The path expression on both platforms, and the refusals.
	 *
	 * @return void
	 */
	public function testExpression(): void {
		$filter = new NestedPathFilter();
		$this->assertSame("(t.d #>> '{a,b-c,D_9}')", $filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, true), ['a', 'b-c', 'D_9']));
		$this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(t.d, '$.a.b-c.D_9'))", $filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, false), ['a', 'b-c', 'D_9']));
		$this->assertNull($filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, true), []));
		$this->assertNull($filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, false), []));
		$this->assertNull($filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, true), ['a', '']));
		$this->assertNull($filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, false), ['a.b']));
		$this->assertNull($filter->expression(new NestedPathTarget('t.d', static fn (string $v): string => $v, true), ['ok', 'x y']));
	}//end testExpression()
}//end class
