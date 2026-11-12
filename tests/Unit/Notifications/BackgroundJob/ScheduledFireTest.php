<?php

/**
 * Unit tests for ScheduledFire — one firing of a scheduled notification rule.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledFire;
use PHPUnit\Framework\TestCase;

final class ScheduledFireTest extends TestCase {

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}

	public function testItTakesTheSchemaIdAndTheTriggerFilter(): void {
		$fire = new ScheduledFire(
			$this->schema(42),
			'overdue',
			['filter' => ['status' => 'open'], 'cron' => '0 * * * *'],
			new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'))
		);

		$this->assertSame(42, $fire->schemaId);
		$this->assertSame(['status' => 'open'], $fire->filter);
		$this->assertSame('overdue', $fire->name);
	}

	public function testATriggerWithoutFilterHasAnEmptyFilter(): void {
		$fire = new ScheduledFire($this->schema(1), 'r', [], new DateTimeImmutable());

		$this->assertSame([], $fire->filter);
	}

	public function testSeenAtIsAMutableCopyOfTheScanTimeInItsZone(): void {
		$now  = new DateTimeImmutable('2026-03-04 10:11:12.345678', new DateTimeZone('Europe/Amsterdam'));
		$fire = new ScheduledFire($this->schema(1), 'r', [], $now);

		$this->assertSame('2026-03-04 10:11:12.345678', $fire->seenAt->format('Y-m-d H:i:s.u'));
		$this->assertSame('Europe/Amsterdam', $fire->seenAt->getTimezone()->getName());

		$fire->seenAt->modify('+1 day');
		$this->assertSame('2026-03-04', $now->format('Y-m-d'));
	}

	public function testCountersStartAtZero(): void {
		$fire = new ScheduledFire($this->schema(1), 'r', [], new DateTimeImmutable());

		$this->assertSame([0, 0, 0], [$fire->matched, $fire->dispatched, $fire->deduplicated]);
		$this->assertSame('', $fire->objectUuid);
		$this->assertSame('', $fire->fingerprint);
		$this->assertSame([], $fire->watchedFields);
	}

	public function testTheOffsetKeyNamesSchemaAndRule(): void {
		$fire = new ScheduledFire($this->schema(7), 'daily-digest', [], new DateTimeImmutable());

		$this->assertSame('sched_offset:7:daily-digest', $fire->offsetKey());
	}
}
