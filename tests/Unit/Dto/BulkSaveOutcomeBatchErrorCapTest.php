<?php

/**
 * How many batch-level errors BulkSaveOutcome repeats for unaccounted rows.
 *
 * At most five messages are echoed, in order, skipping per-row safeguard
 * entries and non-array entries before the cap is counted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Dto
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dto;

use OCA\OpenRegister\Dto\BulkRowCount;
use OCA\OpenRegister\Dto\BulkSaveOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The batch error cap, golden.
 *
 * @covers \OCA\OpenRegister\Dto\BulkSaveOutcome
 */
final class BulkSaveOutcomeBatchErrorCapTest extends TestCase {

	/**
	 * Five messages at most, safeguard and junk entries not counted; a missing message is empty.
	 *
	 * @return void
	 */
	public function testAtMostFiveBatchErrorsAreRepeated(): void {
		$errors = [['type' => 'BulkSafeguardException', 'error' => 'hidden'], 'junk'];
		foreach (['e1', 'e2', 'e3', 'e4'] as $message) {
			$errors[] = ['error' => $message];
		}

		$errors[] = ['type' => 'X'];
		$errors[] = ['error' => 'e6'];
		$outcome = BulkSaveOutcome::fromSaveResult(['errors' => $errors], new BulkRowCount(requested: 2, accounted: 0));

		$this->assertSame(
			'2 object(s) were not written and the save path recorded no per-object reason. Reported errors: e1; e2; e3; e4; ',
			$outcome->failures()[0]['error']
		);
	}//end testAtMostFiveBatchErrorsAreRepeated()
}//end class
