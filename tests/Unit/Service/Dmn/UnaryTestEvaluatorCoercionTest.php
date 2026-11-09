<?php

/**
 * Unit test for UnaryTestEvaluator's coercion of inputs: 0 and 1 as booleans,
 * date objects as their timestamp, and a date that is neither text nor a date
 * object refused as a type mismatch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dmn
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

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses   \OCA\OpenRegister\Service\Dmn\DecisionEvaluationException
 */
class UnaryTestEvaluatorCoercionTest extends TestCase {

	/**
	 * 0 and 1 coerce to booleans; 2 is refused.
	 *
	 * @return void
	 */
	public function testZeroAndOneAreBooleans(): void {
		$evaluator = new UnaryTestEvaluator();
		$this->assertTrue($evaluator->coerce(value: 1, type: 'boolean'));
		$this->assertFalse($evaluator->coerce(value: 0, type: 'boolean'));

		$this->expectException(DecisionEvaluationException::class);
		$evaluator->coerce(value: 2, type: 'boolean');
	}//end testZeroAndOneAreBooleans()

	/**
	 * A date object coerces to its timestamp, the same as its text.
	 *
	 * @return void
	 */
	public function testADateObjectIsItsTimestamp(): void {
		$evaluator = new UnaryTestEvaluator();
		$moment    = new DateTimeImmutable('2026-01-02 00:00:00', new DateTimeZone('UTC'));

		$this->assertSame(1767312000, $evaluator->coerce(value: $moment, type: 'date'));
		$this->assertSame(1767312000, $evaluator->coerce(value: '2026-01-02T00:00:00Z', type: 'date'));
	}//end testADateObjectIsItsTimestamp()

	/**
	 * A date that is a number, or blank text, is a type mismatch naming the expected type.
	 *
	 * @return void
	 */
	public function testADateThatIsNoTextIsRefused(): void {
		$evaluator = new UnaryTestEvaluator();
		foreach ([20260102, '  '] as $value) {
			try {
				$evaluator->coerce(value: $value, type: 'date');
				$this->fail('a date that is no date text must be refused');
			} catch (DecisionEvaluationException $e) {
				$this->assertSame('type_mismatch', $e->getErrorCode());
				$this->assertSame(['expected' => 'date', 'value' => $value], $e->getDetails());
			}
		}
	}//end testADateThatIsNoTextIsRefused()
}//end class
