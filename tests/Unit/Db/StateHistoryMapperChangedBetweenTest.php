<?php

/**
 * StateHistoryMapper::findObjectUuidsChangedBetween reads its bounds from the period.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\StateChangePeriod;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\StateHistoryMapper
 */
final class StateHistoryMapperChangedBetweenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The lower bound is `gte` on the period's start and the upper bound `lte` on its end.
	 *
	 * @return void
	 */
	public function testThePeriodBoundsTheEnteredAtColumn(): void {
		$this->queuedRows = [[['object_uuid' => 'u-1'], ['object_uuid' => 'u-2']]];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		// The start is mutable and the end immutable, so the log tells the two bounds apart.
		$period = new StateChangePeriod(new DateTime('2026-01-01T00:00:00+00:00'), new DateTimeImmutable('2026-02-01T00:00:00+00:00'));

		$uuids = (new StateHistoryMapper($db))->findObjectUuidsChangedBetween('status', $period);

		$this->assertSame(['u-1', 'u-2'], $uuids);
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('selectDistinct(object_uuid)', $log);
		$this->assertMatchesRegularExpression('/param :p2 = DateTime, datetime\n.*expr\.gte\(entered_at, :p2/', $log);
		$this->assertMatchesRegularExpression('/param :p3 = DateTimeImmutable, datetime\n.*expr\.lte\(entered_at, :p3/', $log);
	}//end testThePeriodBoundsTheEnteredAtColumn()
}//end class
