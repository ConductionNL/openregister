<?php

/**
 * Tests for the dry-run switch and progress callback of a search index pass.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;


use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexPass
 */
class SearchIndexPassTest extends TestCase {

	public function testADryRunChangesNothingAndIsPlanned(): void {
		$pass = new SearchIndexPass(apply: false, progress: null);

		$this->assertFalse($pass->apply);
		$this->assertSame(0, $pass->changed(succeeded: 4));
		$this->assertSame('planned', $pass->state(failures: []));
	}

	public function testARealPassCountsWhatSucceeded(): void {
		$pass = new SearchIndexPass(apply: true, progress: null);

		$this->assertSame(3, $pass->changed(succeeded: 3));
		$this->assertSame('completed', $pass->state(failures: []));
		$this->assertSame('failed', $pass->state(failures: [['index' => 'idx_a']]));
	}

	public function testAnnounceCallsTheProgressCallbackWithEachStep(): void {
		$steps = [];
		$pass  = new SearchIndexPass(
			apply: false,
			progress: static function (string $table, string $index, int $position, int $total) use (&$steps): void {
				$steps[] = [$table, $index, $position, $total];
			}
		);

		$pass->announce(step: ['t_1_2', 'idx_a', 1, 2]);
		$pass->announce(step: ['t_1_2', 'idx_b', 2, 2]);

		$this->assertSame([['t_1_2', 'idx_a', 1, 2], ['t_1_2', 'idx_b', 2, 2]], $steps);
	}

	public function testAnnounceWithoutACallbackDoesNothing(): void {
		$pass = new SearchIndexPass(apply: true, progress: null);
		$pass->announce(step: ['t', 'i', 1, 1]);

		$this->assertTrue($pass->apply);
	}
}//end class
