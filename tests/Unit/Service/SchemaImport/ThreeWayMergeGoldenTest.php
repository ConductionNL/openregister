<?php

/**
 * Golden test for ThreeWayMerge.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-import/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\SchemaImport;

use OCA\OpenRegister\Service\SchemaImport\MergeInstruction;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full result of ThreeWayMerge::compute(), including the order of
 * every bucket and of the merged map, for every row of the merge table.
 *
 * @covers \OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge
 */
class ThreeWayMergeGoldenTest extends TestCase {
	private const A = ['type' => 'string'];
	private const L = ['type' => 'string', 'maxLength' => 5];
	private const S = ['type' => 'integer'];
	private const N = ['type' => 'boolean'];
	private const M = ['type' => 'number'];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<string>, 4: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'every row of the merge table' => [
				[
					'b_only' => self::A,
					'rem' => self::A,
					'srcchg' => self::A,
					'localchg' => self::A,
					'both' => self::A,
					'bothres' => self::A,
					'same' => self::A,
					'readd' => self::A,
					'nullbase' => null,
				],
				[
					'local' => self::L,
					'rem' => self::A,
					'srcchg' => self::A,
					'localchg' => self::L,
					'both' => self::L,
					'bothres' => self::L,
					'same' => self::A,
					'newboth' => self::N,
					'newdiff' => self::N,
					'nullbase' => null,
				],
				[
					'srcchg' => self::S,
					'localchg' => self::A,
					'both' => self::S,
					'bothres' => self::S,
					'same' => self::A,
					'readd' => self::S,
					'newsrc' => self::S,
					'newboth' => self::N,
					'newdiff' => self::M,
					'nullbase' => self::S,
				],
				['bothres', 'newdiff', 'unknown'],
				[
					'added' => ['readd', 'newsrc'],
					'removed' => ['rem'],
					'changed' => ['srcchg', 'bothres', 'nullbase', 'newdiff'],
					'keptLocal' => ['localchg', 'same', 'local'],
					'conflicts' => ['both', 'newboth'],
					'merged' => [
						'local' => self::L,
						'rem' => self::A,
						'srcchg' => self::S,
						'localchg' => self::L,
						'both' => self::L,
						'bothres' => self::S,
						'same' => self::A,
						'newboth' => self::N,
						'newdiff' => self::M,
						'nullbase' => self::S,
						'readd' => self::S,
						'newsrc' => self::S,
					],
					'applied' => false,
				],
			],
			'nested key order is ignored' => [
				['p' => ['a' => 1, 'b' => ['x' => 1, 'y' => 2]]],
				['p' => ['b' => ['y' => 2, 'x' => 1], 'a' => 1]],
				['p' => ['a' => 1, 'b' => ['x' => 1, 'y' => 3]]],
				[],
				[
					'added' => [],
					'removed' => [],
					'changed' => ['p'],
					'keptLocal' => [],
					'conflicts' => [],
					'merged' => ['p' => ['a' => 1, 'b' => ['x' => 1, 'y' => 3]]],
					'applied' => true,
				],
			],
			'list order counts as a change' => [
				['p' => ['enum' => ['a', 'b']]],
				['p' => ['enum' => ['a', 'b']]],
				['p' => ['enum' => ['b', 'a']]],
				[],
				[
					'added' => [],
					'removed' => [],
					'changed' => ['p'],
					'keptLocal' => [],
					'conflicts' => [],
					'merged' => ['p' => ['enum' => ['b', 'a']]],
					'applied' => true,
				],
			],
			'nothing anywhere' => [
				[],
				[],
				[],
				[],
				['added' => [], 'removed' => [], 'changed' => [], 'keptLocal' => [], 'conflicts' => [], 'merged' => [], 'applied' => true],
			],
			'baseline only' => [
				['gone' => self::A],
				[],
				[],
				[],
				['added' => [], 'removed' => [], 'changed' => [], 'keptLocal' => [], 'conflicts' => [], 'merged' => [], 'applied' => true],
			],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $baseline Baseline.
	 * @param array<string, mixed> $current  Current.
	 * @param array<string, mixed> $incoming Incoming.
	 * @param list<string>         $resolved Resolved names.
	 * @param array<string, mixed> $expected Expected result.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCompute(array $baseline, array $current, array $incoming, array $resolved, array $expected): void {
		$this->assertSame($expected, (new ThreeWayMerge())->compute(new MergeInstruction($baseline, $current, $incoming, $resolved)));
	}//end testCompute()

	/**
	 * @return void
	 */
	public function testResolvedDefaultsToNone(): void {
		$result = (new ThreeWayMerge())->compute(new MergeInstruction(['p' => self::A], ['p' => self::L], ['p' => self::S]));
		$this->assertSame(['p'], $result['conflicts']);
		$this->assertSame(['p' => self::L], $result['merged']);
	}//end testResolvedDefaultsToNone()
}//end class
