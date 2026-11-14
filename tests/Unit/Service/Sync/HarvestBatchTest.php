<?php

/**
 * HarvestBatch: what a whole-document fetcher returns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Sync\HarvestBatch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\HarvestBatch
 */
class HarvestBatchTest extends TestCase {

	public function testSeenIdsAreTheItemsAndTheErrorsThatNameAnId(): void {
		$batch = new HarvestBatch(
			items: ['ds-1' => [], '42' => []],
			complete: true,
			errors: [
				['externalId' => 'ds-3', 'message' => 'SHACL'],
				['externalId' => null, 'message' => 'page 4 failed'],
				['externalId' => '', 'message' => 'no id'],
			]
		);

		$this->assertSame(['ds-1' => true, '42' => true, 'ds-3' => true], $batch->seenIds());
		$this->assertSame(['ds-3' => 'SHACL'], $batch->errorsById());
	}

	public function testCappingCutsTheItemsAndMakesTheBatchIncomplete(): void {
		$batch = new HarvestBatch(items: ['a' => [1], 'b' => [2], 'c' => [3]], complete: true, checkpoint: 'cp', errors: [['externalId' => 'x', 'message' => 'm']]);

		$capped = $batch->capped(2);

		$this->assertSame(['a' => [1], 'b' => [2]], $capped->items);
		$this->assertFalse($capped->complete);
		$this->assertSame('cp', $capped->checkpoint);
		$this->assertSame($batch->errors, $capped->errors);
	}

	public function testABatchWithinTheCapIsUnchanged(): void {
		$batch = new HarvestBatch(items: ['a' => [1]], complete: true);

		$this->assertSame($batch, $batch->capped(1));
		$this->assertTrue($batch->capped(5)->complete);
	}
}
