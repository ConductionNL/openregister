<?php

/**
 * DeletedFilter: which tables and markers a filtered read of the deleted list keeps (REQ-SYNC-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper\DeletedFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeletedFilter::class)]
class DeletedFilterTest extends TestCase {

	public function testANamedRegisterAndSchemaKeepOnlyTheirTable(): void {
		$filter = new DeletedFilter(registerId: 3, schemaId: 7);

		$this->assertTrue($filter->admitsTable(['registerId' => 3, 'schemaId' => 7]));
		$this->assertFalse($filter->admitsTable(['registerId' => 3, 'schemaId' => 8]));
		$this->assertFalse($filter->admitsTable(['registerId' => 4, 'schemaId' => 7]));
		$this->assertTrue((new DeletedFilter(schemaId: 7))->admitsTable(['registerId' => 9, 'schemaId' => 7]));
		$this->assertTrue((new DeletedFilter())->admitsTable(['registerId' => 9, 'schemaId' => 9]));
	}

	public function testTheMomentIsComparedAsATimeNotAsText(): void {
		$filter = new DeletedFilter(deletedSince: new DateTimeImmutable('2026-10-05T00:00:00+00:00'));

		$this->assertTrue($filter->readsMarkers());
		$this->assertTrue($filter->admitsMarker('{"deletedAt":"2026-10-06T09:00:00+00:00","deletedBy":"alice"}'));
		$this->assertTrue($filter->admitsMarker('{"deletedAt":"2026-10-05T00:00:00+00:00"}'));
		$this->assertFalse($filter->admitsMarker('{"deletedAt":"2026-10-04T23:59:59+00:00"}'));
		// 01:30 at +02:00 is 23:30 the day before in UTC: text says after, time says before.
		$this->assertFalse($filter->admitsMarker('{"deletedAt":"2026-10-05T01:30:00+02:00"}'));
		$this->assertTrue($filter->admitsMarker(['deletedAt' => '2026-10-07T00:00:00Z']));
	}

	public function testOlderMarkerShapesAreRead(): void {
		$filter = new DeletedFilter(deletedSince: new DateTimeImmutable('2026-10-05T00:00:00+00:00'));

		$this->assertTrue($filter->admitsMarker('{"deleted":"2026-10-06T00:00:00+00:00"}'));
		$this->assertTrue($filter->admitsMarker('2026-10-06 10:00:00'));
		$this->assertFalse($filter->admitsMarker('2026-10-01 10:00:00'));
	}

	public function testAMarkerWithoutAReadableMomentIsNotAdmittedToAWindow(): void {
		$filter = new DeletedFilter(deletedSince: new DateTimeImmutable('2026-10-05T00:00:00+00:00'));

		foreach (['{"deletedBy":"alice"}', '{"deletedAt":"soon"}', null, '', 42] as $marker) {
			$this->assertFalse($filter->admitsMarker($marker));
		}

		$this->assertTrue((new DeletedFilter())->admitsMarker(null));
		$this->assertFalse((new DeletedFilter())->readsMarkers());
	}
}
