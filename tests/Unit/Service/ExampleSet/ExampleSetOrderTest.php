<?php

/**
 * Tests for ExampleSetOrder: `after`, OpenRegister first, absent apps and cycles.
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
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 */
class ExampleSetOrderTest extends TestCase {
	public function testAfterDecidesTheOrderAndOpenRegisterComesFirst(): void {
		$result = (new ExampleSetOrder())->order(
			after: ['alpha' => ['beta'], 'beta' => [], 'openregister' => [], 'aardvark' => []],
			enabled: ['alpha', 'beta', 'openregister', 'aardvark']
		);

		$this->assertSame(['openregister', 'aardvark', 'beta', 'alpha'], $result['order']);
		$this->assertSame([], $result['skipped']);
	}

	public function testAnAbsentAppIsSkippedAndNamed(): void {
		$result = (new ExampleSetOrder())->order(
			after: ['openregister' => [], 'alpha' => ['gamma']],
			enabled: ['openregister', 'alpha'],
			expected: ['delta', 'alpha']
		);

		$this->assertSame(['openregister', 'alpha'], $result['order']);
		$this->assertSame(['delta', 'gamma'], $result['skipped']);
	}

	public function testAnEnabledAppWithoutAFragmentIsNotSkipped(): void {
		$result = (new ExampleSetOrder())->order(after: ['openregister' => [], 'alpha' => ['beta']], enabled: ['openregister', 'alpha', 'beta']);

		$this->assertSame([], $result['skipped']);
	}

	public function testACycleIsRefusedNamingOnlyTheAppsOnIt(): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('cycle through alpha, beta.');

		(new ExampleSetOrder())->order(
			after: ['openregister' => [], 'alpha' => ['beta'], 'beta' => ['alpha'], 'gamma' => ['alpha']],
			enabled: ['openregister', 'alpha', 'beta', 'gamma']
		);
	}
}
