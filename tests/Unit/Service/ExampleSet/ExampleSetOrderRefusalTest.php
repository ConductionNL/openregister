<?php

/**
 * Tests for ExampleSetOrder: the cycle refusal names exactly the apps on a cycle,
 * also when OpenRegister or apps that only lead into it are involved.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder;
use OCA\OpenRegister\Service\ExampleSet\FragmentOrdering;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrderPath
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetException
 * @uses   \OCA\OpenRegister\Service\ExampleSet\FragmentOrdering
 */
class ExampleSetOrderRefusalTest extends TestCase {

	/**
	 * The refusal message for these `after` lists, all apps enabled.
	 *
	 * @param array<string, array<int, string>> $after The `after` lists.
	 *
	 * @return string
	 */
	private function refusal(array $after): string {
		try {
			$result = (new ExampleSetOrder())->order(new FragmentOrdering(after: $after, enabled: array_keys($after)));
		} catch (ExampleSetException $e) {
			return $e->getMessage();
		}

		return 'ordered: '.implode(',', $result['order']);
	}

	/**
	 * Two apps waiting on each other are refused; apps leading into the cycle through a chain are not named.
	 *
	 * @return void
	 */
	public function testAppsLeadingIntoACycleAreNotNamed(): void {
		$this->assertSame(
			'The fragments of this set wait on each other in a cycle through beta, gamma. Fix the "after" lists of these apps; nothing was written.',
			$this->refusal(['openregister' => [], 'alpha' => ['beta'], 'beta' => ['gamma'], 'gamma' => ['beta'], 'omega' => ['alpha']])
		);
	}

	/**
	 * OpenRegister waiting on an app that every app waits on through OpenRegister is a cycle naming both and the rest.
	 *
	 * @return void
	 */
	public function testOpenRegisterCanBeOnACycle(): void {
		$this->assertSame(
			'The fragments of this set wait on each other in a cycle through beta, openregister. Fix the "after" lists of these apps; nothing was written.',
			$this->refusal(['openregister' => ['beta'], 'beta' => []])
		);
	}

	/**
	 * Two separate cycles are named together, sorted.
	 *
	 * @return void
	 */
	public function testTwoSeparateCyclesAreNamedTogether(): void {
		$this->assertSame(
			'The fragments of this set wait on each other in a cycle through a, b, x, y. Fix the "after" lists of these apps; nothing was written.',
			$this->refusal(['y' => ['x'], 'x' => ['y'], 'b' => ['a'], 'a' => ['b']])
		);
	}

	/**
	 * Apps that ship no fragment are ignored as waits, so the order still completes.
	 *
	 * @return void
	 */
	public function testAWaitOnAnAppWithoutFragmentDoesNotBlock(): void {
		$this->assertSame('ordered: openregister,alpha,beta', $this->refusal(['beta' => ['alpha', 'ghost'], 'alpha' => ['ghost'], 'openregister' => []]));
	}
}
