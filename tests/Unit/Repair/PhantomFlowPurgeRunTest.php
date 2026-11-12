<?php

/**
 * PhantomFlowPurgeRunTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\PhantomFlowPurgeRun;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The purge's progress prints its counts first, then every spared row.
 */
class PhantomFlowPurgeRunTest extends TestCase {
	public function testAnnounceCountsAndListsTheSparedRowsInOrder(): void {
		$said   = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $m) use (&$said): void {
			$said[] = $m;
		});

		$run = new PhantomFlowPurgeRun();
		$run->countRemoval();
		$run->report('a (one)');
		$run->countRemoval();
		$run->report('b (two)');
		$run->announce($output);

		$this->assertSame(
			[
				'Phantom flow rows: 2 removed, 2 reported for review.',
				'  needs review: a (one)',
				'  needs review: b (two)',
			],
			$said
		);
	}

	public function testAnEmptyRunStillSaysSo(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Phantom flow rows: 0 removed, 0 reported for review.');

		(new PhantomFlowPurgeRun())->announce($output);
	}
}
