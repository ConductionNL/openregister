<?php

/**
 * Unit tests for range filters on the members of a calendar-range property.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md#requirement-a-calendar-range-property-holds-a-start-and-an-end-as-one-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\NestedPathFilter;
use OCA\OpenRegister\Db\MagicMapper\NestedPathTarget;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * `?period.start.gte=…&period.end.lte=…` on a calendar-range property.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses \OCA\OpenRegister\Db\MagicMapper\NestedPathTarget
 */
final class NestedPathFilterCalendarRangeTest extends TestCase {

	/**
	 * The schema properties the filters are split against.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'period' => ['type' => 'calendar-range'],
		'cover'  => ['type' => 'image-url'],
		'title'  => ['type' => 'string'],
	];

	/**
	 * The filter map the request layer rebuilds from the spec's query string.
	 *
	 * @var array<string,mixed>
	 */
	private const MAY = [
		'period' => [
			'start' => ['gte' => '2026-05-01'],
			'end'   => ['lte' => '2026-05-31'],
		],
	];

	/**
	 * Both members of a calendar-range become path filters with their operator bags.
	 *
	 * @return void
	 */
	public function testTheRangeMembersBecomePathFilters(): void {
		[$plain, $nested] = (new NestedPathFilter())->split(self::MAY, self::PROPERTIES);

		$this->assertSame([], $plain);
		$this->assertSame(
			[
				['root' => 'period', 'path' => ['start'], 'value' => ['gte' => '2026-05-01']],
				['root' => 'period', 'path' => ['end'], 'value' => ['lte' => '2026-05-31']],
			],
			$nested
		);
	}//end testTheRangeMembersBecomePathFilters()

	/**
	 * The dotted spelling a PHP caller passes splits the same way.
	 *
	 * @return void
	 */
	public function testTheDottedSpellingSplitsTheSameWay(): void {
		[$plain, $nested] = (new NestedPathFilter())->split(['period.start' => ['gt' => '2026-04-30'], 'title' => 'x'], self::PROPERTIES);

		$this->assertSame(['title' => 'x'], $plain);
		$this->assertSame([['root' => 'period', 'path' => ['start'], 'value' => ['gt' => '2026-04-30']]], $nested);
	}//end testTheDottedSpellingSplitsTheSameWay()

	/**
	 * A type the change does not make filterable by member stays a column filter.
	 *
	 * @return void
	 */
	public function testAnImageUrlStaysAColumnFilter(): void {
		$filters = ['cover.url' => 'https://example.org/a.png'];

		$this->assertSame([$filters, []], (new NestedPathFilter())->split($filters, self::PROPERTIES));
	}//end testAnImageUrlStaysAColumnFilter()

	/**
	 * A calendar-range value that is not a path map stays a column filter.
	 *
	 * @return void
	 */
	public function testARangeWithoutAMemberStaysAColumnFilter(): void {
		$this->assertSame([['period' => 'IS NULL'], []], (new NestedPathFilter())->split(['period' => 'IS NULL'], self::PROPERTIES));
	}//end testARangeWithoutAMemberStaysAColumnFilter()

	/**
	 * The member comparisons read the JSON text on both platforms and bind a date as text.
	 *
	 * @return void
	 */
	public function testTheMemberComparisonsOnBothPlatforms(): void {
		$filter = new NestedPathFilter();
		$seen   = [];
		$bind   = static function (string $text) use (&$seen): string {
			$seen[] = $text;
			return ':b'.(count($seen) - 1);
		};

		$pg = $filter->expression(new NestedPathTarget('t.period', $bind, true), ['start']);
		$this->assertSame("(t.period #>> '{start}')", $pg);
		$this->assertSame(
			["(t.period #>> '{start}') >= :b0"],
			$filter->conditions(new NestedPathTarget($pg, $bind, true), ['gte' => '2026-05-01'])
		);

		$my = $filter->expression(new NestedPathTarget('t.period', $bind, false), ['end']);
		$this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(t.period, '$.end'))", $my);
		$this->assertSame(
			["JSON_UNQUOTE(JSON_EXTRACT(t.period, '$.end')) <= :b1"],
			$filter->conditions(new NestedPathTarget($my, $bind, false), ['lte' => '2026-05-31'])
		);
		$this->assertSame(['2026-05-01', '2026-05-31'], $seen);
	}//end testTheMemberComparisonsOnBothPlatforms()

	/**
	 * Run against stored rows, the spec's query returns only the May bookings.
	 *
	 * SQLite has JSON_EXTRACT; JSON_UNQUOTE is the identity there, since its
	 * JSON_EXTRACT already returns a string member unquoted.
	 *
	 * @return void
	 */
	#[RequiresPhpExtension('pdo_sqlite')]
	public function testTheSpecQueryReturnsOnlyTheMayBookings(): void {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn (?string $text): ?string => $text, 1);
		$pdo->exec('CREATE TABLE booking (name TEXT, period TEXT)');
		$rows = [
			'april' => ['start' => '2026-04-10T09:00:00Z', 'end' => '2026-04-10T17:00:00Z'],
			'straddle' => ['start' => '2026-04-29T09:00:00Z', 'end' => '2026-05-02T17:00:00Z'],
			'may-early' => ['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-01T17:00:00Z'],
			'may-late' => ['start' => '2026-05-20T09:00:00Z', 'end' => '2026-05-30T17:00:00Z'],
			'june' => ['start' => '2026-06-03T09:00:00Z', 'end' => '2026-06-04T17:00:00Z'],
		];
		$insert = $pdo->prepare('INSERT INTO booking (name, period) VALUES (?, ?)');
		foreach ($rows as $name => $period) {
			$insert->execute([$name, json_encode($period)]);
		}

		$filter = new NestedPathFilter();
		$params = [];
		$bind   = static function (string $text) use (&$params): string {
			$params[] = $text;
			return '?';
		};

		[, $nested] = $filter->split(self::MAY, self::PROPERTIES);
		$where      = [];
		foreach ($nested as $path) {
			$expression = $filter->expression(new NestedPathTarget('booking.'.$path['root'], $bind, false), $path['path']);
			$this->assertNotNull($expression);
			array_push($where, ...$filter->conditions(new NestedPathTarget($expression, $bind, false), $path['value']));
		}

		$statement = $pdo->prepare('SELECT name FROM booking WHERE '.implode(' AND ', $where).' ORDER BY name');
		$statement->execute($params);

		$this->assertSame(['may-early', 'may-late'], $statement->fetchAll(PDO::FETCH_COLUMN));
	}//end testTheSpecQueryReturnsOnlyTheMayBookings()
}//end class
