<?php

/**
 * FlowVersionBackfillTallyTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\FlowVersionBackfillTally;
use PHPUnit\Framework\TestCase;

/**
 * The back-fill tally adds up published, already-versioned and pinned counts.
 */
class FlowVersionBackfillTallyTest extends TestCase {
	public function testEachOutcomeCountsOnceAndPinsAddUp(): void {
		$tally = new FlowVersionBackfillTally();
		$tally->record(['published' => true, 'pinned' => 2]);
		$tally->record(['published' => false, 'pinned' => 0]);
		$tally->record(['published' => true, 'pinned' => 3]);

		$this->assertSame(
			'Flow versions: published version 1 for 2 flow(s), 1 already versioned, pinned 5 in-flight run(s).',
			$tally->summary()
		);
	}

	public function testAnEmptyTallyReportsZeroes(): void {
		$this->assertSame(
			'Flow versions: published version 1 for 0 flow(s), 0 already versioned, pinned 0 in-flight run(s).',
			(new FlowVersionBackfillTally())->summary()
		);
	}
}
