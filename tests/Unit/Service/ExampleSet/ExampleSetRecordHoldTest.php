<?php

/**
 * What holding a followed change or preference does to the record and the report, and when the record is written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetFollowedEntry;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetRecordHoldTest extends TestCase {
	/**
	 * Hold one followed entry, returning the report, the record and how often the record was written.
	 *
	 * @param array<string, mixed> $made What following the entry made.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int}
	 */
	private function held(array $made): array {
		$writes = 0;
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(function () use (&$writes): bool {
			$writes++;
			return true;
		});
		$record = ['changes' => ['demo/c' => ['set' => [['path' => 'a', 'new' => 1]]]]];
		$report = ['changes' => ['demo/c' => 'kept']];
		$report = (new ExampleSetRecord($config))->hold(
			set: 'demo',
			followed: new ExampleSetFollowedEntry(part: 'changes', name: 'demo/c', made: $made),
			report: $report,
			record: $record
		);

		return [$report, $record, $writes];
	}//end held()

	public function testAnUnchangedEntryIsNeitherWrittenNorReported(): void {
		[$report, $record, $writes] = $this->held(['error' => '', 'entry' => ['set' => [['path' => 'a', 'new' => 1]]], 'wrote' => false]);

		$this->assertSame(['changes' => ['demo/c' => 'kept']], $report);
		$this->assertSame(['set' => [['path' => 'a', 'new' => 1]]], $record['changes']['demo/c']);
		$this->assertSame(0, $writes);
	}//end testAnUnchangedEntryIsNeitherWrittenNorReported()

	public function testARefreshedEntryIsTakenWrittenAndReported(): void {
		[$report, $record, $writes] = $this->held(['error' => '', 'entry' => ['set' => [['path' => 'a', 'new' => 2]]], 'wrote' => true]);

		$this->assertSame(['changes' => ['demo/c' => 'refreshed']], $report);
		$this->assertSame(['set' => [['path' => 'a', 'new' => 2]]], $record['changes']['demo/c']);
		$this->assertSame(1, $writes);
	}//end testARefreshedEntryIsTakenWrittenAndReported()

	public function testAFailedFollowIsReportedAndTheHeldEntryStays(): void {
		[$report, $record, $writes] = $this->held(['error' => 'locked', 'entry' => ['set' => [['path' => 'a', 'new' => 1]]], 'wrote' => false]);

		$this->assertSame(['changes' => ['demo/c' => 'failed'], 'dropped' => ['changes demo/c: not refreshed: locked']], $report);
		$this->assertSame(['set' => [['path' => 'a', 'new' => 1]]], $record['changes']['demo/c']);
		$this->assertSame(0, $writes);
	}//end testAFailedFollowIsReportedAndTheHeldEntryStays()
}//end class
