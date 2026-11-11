<?php

/**
 * Unit test for ExampleSetOrder's cycle refusal: which apps the refusal names
 * when the fragments' "after" lists wait on each other.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\FragmentOrdering;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrderPath
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetException
 */
class ExampleSetOrderCycleTest extends TestCase {

	/**
	 * The message the order refuses with.
	 *
	 * @param array<string, array<int, string>> $after The "after" lists.
	 *
	 * @return string
	 */
	private function refusal(array $after): string {
		try {
			(new ExampleSetOrder())->order(new FragmentOrdering(after: $after, enabled: array_keys($after)));
		} catch (ExampleSetException $e) {
			return $e->getMessage();
		}

		return 'no refusal';
	}//end refusal()

	/**
	 * A three-app cycle names its three apps, sorted, and not the app that only waits on it.
	 *
	 * @return void
	 */
	public function testALongCycleNamesOnlyTheAppsOnIt(): void {
		$this->assertSame(
			'The fragments of this set wait on each other in a cycle through alpha, beta, gamma. Fix the "after" lists of these apps; nothing was written.',
			$this->refusal(after: ['openregister' => [], 'gamma' => ['alpha'], 'alpha' => ['beta'], 'beta' => ['gamma'], 'delta' => ['alpha']])
		);
	}//end testALongCycleNamesOnlyTheAppsOnIt()

	/**
	 * An app that waits on itself is a cycle of one, and a diamond leading into it does not loop the search.
	 *
	 * @return void
	 */
	public function testASelfWaitIsACycleOfOne(): void {
		$this->assertSame(
			'The fragments of this set wait on each other in a cycle through zeta. Fix the "after" lists of these apps; nothing was written.',
			$this->refusal(after: ['zeta' => ['zeta'], 'top' => ['left', 'right'], 'left' => ['zeta'], 'right' => ['zeta', 'left']])
		);
	}//end testASelfWaitIsACycleOfOne()

	/**
	 * Without a cycle there is no refusal, and among ready apps the lowest name goes first.
	 *
	 * @return void
	 */
	public function testWithoutACycleTheLowestReadyAppGoesFirst(): void {
		$this->assertSame('no refusal', $this->refusal(after: ['top' => ['left', 'right'], 'left' => [], 'right' => ['left']]));
		$this->assertSame(
			['left', 'right', 'top'],
			(new ExampleSetOrder())->order(new FragmentOrdering(after: ['top' => ['left', 'right'], 'right' => ['left'], 'left' => []], enabled: []))['order']
		);
	}//end testWithoutACycleTheLowestReadyAppGoesFirst()
}//end class
