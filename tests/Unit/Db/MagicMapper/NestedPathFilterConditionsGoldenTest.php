<?php

/**
 * Golden test pinning the SQL NestedPathFilter::conditions writes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\NestedPathFilter;
use OCA\OpenRegister\Db\MagicMapper\NestedPathTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins each condition and every value handed to the binder, in order, on both platforms.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 */
final class NestedPathFilterConditionsGoldenTest extends TestCase {

	/**
	 * The filter values and what they write.
	 *
	 * @return array<string, array{0: mixed, 1: bool, 2: list<string>, 3: list<string>}> The cases.
	 */
	public static function provideValues(): array {
		$pgNumeric = "(CASE WHEN E ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(E AS NUMERIC) END)";

		return [
			'null'                 => [null, false, ['E IS NULL'], []],
			'IS NULL'              => ['IS NULL', true, ['E IS NULL'], []],
			'IS NOT NULL'          => ['IS NOT NULL', false, ['E IS NOT NULL'], []],
			'scalar'               => ['open', false, ['E = :b0'], ['open']],
			'true'                 => [true, true, ['E = :b0'], ['true']],
			'false'                => [false, false, ['E = :b0'], ['false']],
			'number'               => [12, false, ['E = :b0'], ['12']],
			'empty list'           => [[], false, ['1=0'], []],
			'list'                 => [['a', 2, true, ['x']], false, ['E IN (:b0, :b1, :b2, :b3)'], ['a', '2', 'true', '']],
			'map without operator' => [['k' => 'v'], false, ['E IN (:b0)'], ['v']],
			'range mysql numeric'  => [
				['lt' => 9, 'gt' => '1.5', 'lte' => 8, 'gte' => 2],
				false,
				[
					'CAST(E AS DECIMAL(30,10)) >= :b0',
					'CAST(E AS DECIMAL(30,10)) <= :b1',
					'CAST(E AS DECIMAL(30,10)) > :b2',
					'CAST(E AS DECIMAL(30,10)) < :b3',
				],
				['2', '8', '1.5', '9'],
			],
			'range postgres'       => [
				['gte' => 3, 'lt' => 'm'],
				true,
				[$pgNumeric.' >= :b0', 'E < :b1'],
				['3', 'm'],
			],
			'range text and null'  => [['gte' => 'a', 'lte' => null], false, ['E >= :b0'], ['a']],
			'set operators'        => [
				['ne' => false, 'in' => 'x', 'notIn' => ['y', 'z']],
				false,
				['E <> :b0', 'E IN (:b1)', 'E NOT IN (:b2, :b3)'],
				['false', 'x', 'y', 'z'],
			],
			'empty in and notIn'   => [['in' => [], 'notIn' => []], false, ['1=0'], []],
			'isnull true'          => [['isnull' => 'true'], false, ['E IS NULL'], []],
			'isnull false'         => [['isnull' => false], false, ['E IS NOT NULL'], []],
			'isnull null'          => [['isnull' => null], true, ['E IS NOT NULL'], []],
			'like'                 => [['like' => 'A_b%c\\'], false, ['LOWER(E) LIKE :b0'], ['%a\\_b\\%c\\\\%']],
			'empty like'           => [['like' => ''], false, [], []],
			'orNull alone'         => [['orNull' => true], false, [], []],
			'every operator'       => [
				['like' => 'q', 'isnull' => 0, 'notIn' => ['n'], 'in' => ['i'], 'ne' => 'e', 'lt' => 5, 'gte' => 'a'],
				true,
				[
					'E >= :b0',
					$pgNumeric.' < :b1',
					'E <> :b2',
					'E IN (:b3)',
					'E NOT IN (:b4)',
					'E IS NOT NULL',
					'LOWER(E) LIKE :b5',
				],
				['a', '5', 'e', 'i', 'n', '%q%'],
			],
		];
	}//end provideValues()

	/**
	 * Each value writes its pinned conditions and binds its pinned values in order.
	 *
	 * @param mixed        $value      The filter value.
	 * @param bool         $isPostgres Whether the platform is PostgreSQL.
	 * @param list<string> $conditions The expected conditions.
	 * @param list<string> $bound      The expected values handed to the binder.
	 *
	 * @return void
	 */
	#[DataProvider('provideValues')]
	public function testConditions(mixed $value, bool $isPostgres, array $conditions, array $bound): void {
		$seen = [];
		$bind = static function (string $text) use (&$seen): string {
			$seen[] = $text;
			return ':b'.(count($seen) - 1);
		};

		$actual = (new NestedPathFilter())->conditions(target: new NestedPathTarget(expression: 'E', bind: $bind, isPostgres: $isPostgres), value: $value);

		$this->assertSame($conditions, $actual);
		$this->assertSame($bound, $seen);
	}//end testConditions()
}//end class
