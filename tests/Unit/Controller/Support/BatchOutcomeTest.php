<?php

/**
 * Unit test for BatchOutcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\BatchOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The summary line of a batch over objects.
 *
 * @covers \OCA\OpenRegister\Controller\Support\BatchOutcome
 */
class BatchOutcomeTest extends TestCase {
	public function testTheMessageCountsDoneAndFailed(): void {
		$this->assertSame('Locked 3 objects, 1 failed', (new BatchOutcome(3, 1, 0))->message('Locked'));
	}

	public function testMissingObjectsAreAddedOnlyWhenThereAreAny(): void {
		$this->assertSame('Unlocked 0 objects, 2 failed (2 not found)', (new BatchOutcome(0, 2, 2))->message('Unlocked'));
	}

	public function testTheCountsAreKept(): void {
		$outcome = new BatchOutcome(5, 4, 3);

		$this->assertSame([5, 4, 3], [$outcome->done, $outcome->failed, $outcome->notFound]);
	}
}
