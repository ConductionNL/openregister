<?php

/**
 * Unit tests for CalendarLinkDuplicates: which rows of a triple are surplus.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\CalendarLinkDuplicates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalendarLinkDuplicates::class)]
class CalendarLinkDuplicatesTest extends TestCase {

	private function surplus(array $rows): array {
		$duplicates = new CalendarLinkDuplicates();
		foreach ($rows as $row) {
			$duplicates->add(row: $row);
		}

		return $duplicates->surplusIds();
	}

	private static function row(int $id, string $object, string $event, string $calendar, ?string $linkedAt): array {
		return ['id' => $id, 'object_uuid' => $object, 'event_uid' => $event, 'calendar_uri' => $calendar, 'linked_at' => $linkedAt];
	}

	public function testUniqueRowsHaveNoSurplus(): void {
		$this->assertSame(
			[],
			$this->surplus(
				[
					self::row(1, 'o1', 'e1', 'personal', '2026-01-01 10:00:00'),
					self::row(2, 'o1', 'e1', 'work', '2026-01-01 10:00:00'),
					self::row(3, 'o2', 'e1', 'personal', '2026-01-01 10:00:00'),
					self::row(4, 'o1', 'e2', 'personal', '2026-01-01 10:00:00'),
				]
			)
		);
	}

	public function testTheOldestRowOfATripleIsKeptWhateverItsId(): void {
		$this->assertSame(
			[5, 9],
			$this->surplus(
				[
					self::row(5, 'o1', 'e1', 'personal', '2026-03-01 10:00:00'),
					self::row(7, 'o1', 'e1', 'personal', '2026-01-01 09:00:00'),
					self::row(9, 'o1', 'e1', 'personal', '2026-02-01 10:00:00'),
				]
			)
		);
	}

	public function testTheLowestIdBreaksATie(): void {
		$this->assertSame(
			[8],
			$this->surplus(
				[
					self::row(8, 'o1', 'e1', 'personal', '2026-01-01 10:00:00'),
					self::row(3, 'o1', 'e1', 'personal', '2026-01-01 10:00:00'),
				]
			)
		);
	}

	public function testARowWithoutALinkTimeNeverDisplacesOneWithIt(): void {
		$this->assertSame(
			[1],
			$this->surplus(
				[
					self::row(1, 'o1', 'e1', 'personal', null),
					self::row(2, 'o1', 'e1', 'personal', '2026-01-01 10:00:00'),
				]
			)
		);
		$this->assertSame(
			[4],
			$this->surplus(
				[
					self::row(3, 'o1', 'e1', 'personal', '2026-01-01 10:00:00'),
					self::row(4, 'o1', 'e1', 'personal', ''),
				]
			)
		);
	}

	public function testRowsWithoutLinkTimesFallBackToTheLowestId(): void {
		$this->assertSame([6], $this->surplus([self::row(6, 'o', 'e', '', null), self::row(2, 'o', 'e', '', null)]));
	}

	public function testSeveralTriplesAreResolvedIndependentlyAndSorted(): void {
		$this->assertSame(
			[10, 11, 12],
			$this->surplus(
				[
					self::row(12, 'o2', 'e2', 'c', '2026-05-01 00:00:00'),
					self::row(1, 'o1', 'e1', 'c', '2026-01-01 00:00:00'),
					self::row(11, 'o1', 'e1', 'c', '2026-01-02 00:00:00'),
					self::row(2, 'o2', 'e2', 'c', '2026-04-01 00:00:00'),
					self::row(10, 'o1', 'e1', 'c', '2026-01-03 00:00:00'),
				]
			)
		);
	}
}
