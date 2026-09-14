<?php

/**
 * ObjectEntity::delete() honours the retention it is handed.
 *
 * It did not. The line that added the retention was commented out with a
 * `@todo` and replaced by a hard-coded 31 days, so a schema declaring a year
 * of recovery quietly had a month and the caller's argument changed nothing.
 * The window is the whole point of this path, so this pins it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ObjectEntityDeleteWindowTest extends TestCase {
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar-1');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	private function daysBetween(string $from, string $to): int {
		$start = new DateTimeImmutable($from);
		$end = new DateTimeImmutable($to);

		return (int)$start->diff($end)->days;
	}//end daysBetween()

	public function testTheStatedRetentionIsTheWindowThatGetsWritten(): void {
		$object = new ObjectEntity();
		$object->setUuid('vertrouwelijk-1');
		$object->delete($this->session(), 'no longer needed', 365);

		$deleted = $object->getDeleted();
		self::assertSame(365, $deleted['retentionPeriod']);
		self::assertSame(365, $this->daysBetween($deleted['deletedAt'], $deleted['purgeDate']));
		self::assertSame($deleted['purgeDate'], $deleted['destroyableFrom']);
	}//end testTheStatedRetentionIsTheWindowThatGetsWritten()

	public function testADifferentRetentionGivesADifferentWindow(): void {
		$short = new ObjectEntity();
		$short->delete($this->session(), null, 7);

		$long = new ObjectEntity();
		$long->delete($this->session(), null, 3650);

		self::assertSame(7, $this->daysBetween($short->getDeleted()['deletedAt'], $short->getDeleted()['purgeDate']));
		self::assertSame(3650, $this->daysBetween($long->getDeleted()['deletedAt'], $long->getDeleted()['purgeDate']));
	}//end testADifferentRetentionGivesADifferentWindow()

	public function testANonPositiveRetentionFallsBackToTheDefault(): void {
		$object = new ObjectEntity();
		$object->delete($this->session(), null, 0);

		$deleted = $object->getDeleted();
		self::assertSame(DeletionWindowService::DEFAULT_RETENTION_DAYS, $deleted['retentionPeriod']);
		self::assertSame(
			DeletionWindowService::DEFAULT_RETENTION_DAYS,
			$this->daysBetween($deleted['deletedAt'], $deleted['purgeDate'])
		);
	}//end testANonPositiveRetentionFallsBackToTheDefault()
}//end class
