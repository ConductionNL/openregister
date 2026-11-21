<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCP\Comments\ICommentsManager;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what LinkedEntityEnricher returns for `_todos` and `_calendar` ids, and the
 * queries it runs for them, against fixtures/linked-entity-enricher-calendar-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\LinkedEntityEnricher
 * @uses \OCA\OpenRegister\Service\Object\LinkedEntityQuery
 */
class LinkedEntityEnricherCalendarGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/linked-entity-enricher-calendar-golden.json';

	private const VEVENT = "BEGIN:VEVENT\nSUMMARY:Board meeting\nDTSTART;TZID=Europe/Amsterdam:20260301T100000\n"
		. "DTEND:20260301T110000\nLOCATION:Room 4\nEND:VEVENT";

	private const VTODO = "BEGIN:VTODO\nSUMMARY:Write minutes\nSTATUS:COMPLETED\nDUE:20260302\nEND:VTODO";

	/**
	 * Rows by calendar uid; `throw` makes execute() fail, absent uids find nothing.
	 */
	private const ROWS = [
		'event' => ['calendardata' => self::VEVENT],
		'todo' => ['calendardata' => self::VTODO],
		'bare' => ['calendardata' => "BEGIN:VTODO\nEND:VTODO"],
		'nodata' => [],
	];

	private const IDS = ['7/event', '7/todo', '7/bare', '7/nodata', '7/missing', '7/throw', 'no-slash', 'x/event', '12/a/b'];

	public function testTodosAndCalendarMatchGolden(): void {
		$queries = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(function (string $sql) use (&$queries): IPreparedStatement {
			$stmt = $this->createMock(IPreparedStatement::class);
			$row = false;
			$stmt->method('execute')->willReturnCallback(function (array $params) use ($sql, &$queries, &$row): IResult {
				$queries[] = [preg_replace('/\s+/', ' ', $sql), $params];
				if ($params[1] === 'throw') {
					throw new RuntimeException('database down');
				}

				$row = self::ROWS[$params[1]] ?? false;
				return $this->createMock(IResult::class);
			});
			$stmt->method('fetch')->willReturnCallback(function () use (&$row) {
				return $row;
			});
			return $stmt;
		});

		$enricher = new LinkedEntityEnricher(
			$db,
			$this->createMock(ICommentsManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class)
		);

		$actual = [
			'enriched' => $enricher->enrich(
				['_todos' => self::IDS, '_calendar' => self::IDS, '_talk' => ['t']],
				['_todos' => '1', '_calendar' => '1']
			),
			'queries' => $queries,
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testTodosAndCalendarMatchGolden()
}//end class
