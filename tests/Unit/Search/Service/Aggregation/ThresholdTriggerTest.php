<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\Aggregation\ThresholdTrigger}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

use OCA\OpenRegister\Search\Service\Aggregation\ThresholdTrigger;
use PHPUnit\Framework\TestCase;

/**
 * Reading a threshold trigger from configuration and comparing a value to it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\ThresholdTrigger
 */
class ThresholdTriggerTest extends TestCase {

	/**
	 * A trigger needs an aggregation, an operator and a value; zero counts as a value.
	 *
	 * @return void
	 */
	public function testATriggerNeedsAllThreeParts(): void {
		$trigger = ThresholdTrigger::fromConfig(['aggregation' => 'openCases', 'op' => 'gte', 'value' => 0]);

		$this->assertNotNull($trigger);
		$this->assertSame(['openCases', 'gte', 0], [$trigger->aggregation, $trigger->op, $trigger->threshold]);
		$this->assertNull(ThresholdTrigger::fromConfig(['op' => 'gt', 'value' => 5]));
		$this->assertNull(ThresholdTrigger::fromConfig(['aggregation' => 'openCases', 'value' => 5]));
		$this->assertNull(ThresholdTrigger::fromConfig(['aggregation' => 'openCases', 'op' => 'gt']));
		$this->assertNull(ThresholdTrigger::fromConfig(['aggregation' => 'openCases', 'op' => 'gt', 'value' => null]));
	}//end testATriggerNeedsAllThreeParts()

	/**
	 * @return array<string, array{0: string, 1: int|float, 2: bool}>
	 */
	public static function comparisons(): array {
		return [
			'gt above' => ['gt', 11, true],
			'gt equal' => ['gt', 10, false],
			'gte equal' => ['gte', 10, true],
			'gte below' => ['gte', 9.99, false],
			'lt below' => ['lt', 9, true],
			'lt equal' => ['lt', 10, false],
			'lte equal' => ['lte', 10.0, true],
			'lte above' => ['lte', 10.01, false],
			'eq int and float' => ['eq', 10.0, true],
			'eq other' => ['eq', 11, false],
			'ne other' => ['ne', 11, true],
			'ne equal' => ['ne', 10, false],
			'unknown operator' => ['between', 10, false],
		];
	}//end comparisons()

	/**
	 * Each operator compares the value numerically to a threshold of 10.
	 *
	 * @dataProvider comparisons
	 *
	 * @param string    $op       The operator.
	 * @param int|float $actual   The value.
	 * @param bool      $expected Whether the trigger is met.
	 *
	 * @return void
	 */
	public function testEachOperatorComparesNumerically(string $op, int|float $actual, bool $expected): void {
		$this->assertSame($expected, (new ThresholdTrigger(aggregation: 'a', op: $op, threshold: 10))->isMetBy(actual: $actual));
	}//end testEachOperatorComparesNumerically()

	/**
	 * A numeric string threshold is compared as a number; anything else is never met.
	 *
	 * @return void
	 */
	public function testOnlyANumericThresholdCanBeMet(): void {
		$this->assertTrue((new ThresholdTrigger(aggregation: 'a', op: 'gt', threshold: '2.5'))->isMetBy(actual: 3));
		$this->assertFalse((new ThresholdTrigger(aggregation: 'a', op: 'ne', threshold: 'many'))->isMetBy(actual: 3));
		$this->assertFalse((new ThresholdTrigger(aggregation: 'a', op: 'gte', threshold: [1]))->isMetBy(actual: 3));
	}//end testOnlyANumericThresholdCanBeMet()
}//end class
