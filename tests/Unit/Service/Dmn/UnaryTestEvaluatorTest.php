<?php

/**
 * UnaryTestEvaluator Unit Tests
 *
 * Grammar matrix: every operator, ranges (inclusive/exclusive/mixed), sets
 * (quoted/unquoted), wildcard, booleans, type coercion for all four types,
 * and malformed expressions.
 *
 * @category Tests
 * @package  Unit\Service\Dmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Dmn;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 *
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionEvaluationException
 */
class UnaryTestEvaluatorTest extends TestCase {

	/**
	 * The evaluator under test.
	 *
	 * @var UnaryTestEvaluator
	 */
	private UnaryTestEvaluator $evaluator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->evaluator = new UnaryTestEvaluator();
	}//end setUp()

	// ------------------------------------------------------------------
	// Wildcard
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testEmptyExpressionIsWildcard(): void {
		self::assertTrue($this->evaluator->matches(expression: '', value: 'anything', type: 'string'));
	}//end testEmptyExpressionIsWildcard()

	/**
	 * @return void
	 */
	public function testDashExpressionIsWildcard(): void {
		self::assertTrue($this->evaluator->matches(expression: '-', value: 12345.0, type: 'number'));
	}//end testDashExpressionIsWildcard()

	/**
	 * @return void
	 */
	public function testQuotedDashIsLiteralNotWildcard(): void {
		self::assertTrue($this->evaluator->matches(expression: '"-"', value: '-', type: 'string'));
		self::assertFalse($this->evaluator->matches(expression: '"-"', value: 'anything-else', type: 'string'));
	}//end testQuotedDashIsLiteralNotWildcard()

	// ------------------------------------------------------------------
	// Comparison operators
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testLessThan(): void {
		self::assertTrue($this->evaluator->matches(expression: '< 10', value: 5.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '< 10', value: 10.0, type: 'number'));
	}//end testLessThan()

	/**
	 * @return void
	 */
	public function testLessThanOrEqual(): void {
		self::assertTrue($this->evaluator->matches(expression: '<= 10', value: 10.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '<= 10', value: 10.5, type: 'number'));
	}//end testLessThanOrEqual()

	/**
	 * @return void
	 */
	public function testGreaterThan(): void {
		self::assertTrue($this->evaluator->matches(expression: '> 10', value: 11.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '> 10', value: 10.0, type: 'number'));
	}//end testGreaterThan()

	/**
	 * @return void
	 */
	public function testGreaterThanOrEqual(): void {
		self::assertTrue($this->evaluator->matches(expression: '>= 10', value: 10.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '>= 10', value: 9.999, type: 'number'));
	}//end testGreaterThanOrEqual()

	/**
	 * @return void
	 */
	public function testEquals(): void {
		self::assertTrue($this->evaluator->matches(expression: '= gold', value: 'gold', type: 'string'));
		self::assertFalse($this->evaluator->matches(expression: '= gold', value: 'silver', type: 'string'));
	}//end testEquals()

	/**
	 * @return void
	 */
	public function testNotEquals(): void {
		self::assertTrue($this->evaluator->matches(expression: '!= gold', value: 'silver', type: 'string'));
		self::assertFalse($this->evaluator->matches(expression: '!= gold', value: 'gold', type: 'string'));
	}//end testNotEquals()

	/**
	 * @return void
	 */
	public function testOperatorWithNoOperandIsInvalidExpression(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->matches(expression: '>=   ', value: 1.0, type: 'number');
		} catch (DecisionEvaluationException $e) {
			self::assertSame('invalid_expression', $e->getErrorCode());
			throw $e;
		}
	}//end testOperatorWithNoOperandIsInvalidExpression()

	// ------------------------------------------------------------------
	// Ranges
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testInclusiveRangeBoundsMatch(): void {
		self::assertTrue($this->evaluator->matches(expression: '[0..25000]', value: 0.0, type: 'number'));
		self::assertTrue($this->evaluator->matches(expression: '[0..25000]', value: 25000.0, type: 'number'));
		self::assertTrue($this->evaluator->matches(expression: '[0..25000]', value: 12500.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '[0..25000]', value: 25000.01, type: 'number'));
	}//end testInclusiveRangeBoundsMatch()

	/**
	 * @return void
	 */
	public function testExclusiveRangeBoundsDoNotMatch(): void {
		self::assertFalse($this->evaluator->matches(expression: '(25000..40000)', value: 25000.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '(25000..40000)', value: 40000.0, type: 'number'));
		self::assertTrue($this->evaluator->matches(expression: '(25000..40000)', value: 30000.0, type: 'number'));
	}//end testExclusiveRangeBoundsDoNotMatch()

	/**
	 * @return void
	 */
	public function testMixedRangeBoundaries(): void {
		self::assertFalse($this->evaluator->matches(expression: '(25000..40000]', value: 25000.0, type: 'number'));
		self::assertTrue($this->evaluator->matches(expression: '(25000..40000]', value: 40000.0, type: 'number'));
		self::assertTrue($this->evaluator->matches(expression: '[25000..40000)', value: 25000.0, type: 'number'));
		self::assertFalse($this->evaluator->matches(expression: '[25000..40000)', value: 40000.0, type: 'number'));
	}//end testMixedRangeBoundaries()

	/**
	 * @return void
	 */
	public function testUnbalancedRangeIsInvalidExpression(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->matches(expression: '[1..', value: 5.0, type: 'number');
		} catch (DecisionEvaluationException $e) {
			// No `..` match at all falls through to bare-literal coercion,
			// which for a non-numeric literal like "[1.." on a number type
			// surfaces as type_mismatch — still a clear, typed error, never
			// a silent match/non-match.
			self::assertContains($e->getErrorCode(), ['invalid_expression', 'type_mismatch']);
			throw $e;
		}
	}//end testUnbalancedRangeIsInvalidExpression()

	/**
	 * @return void
	 */
	public function testMissingRangeBoundIsInvalidExpression(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->matches(expression: '[1..]', value: 5.0, type: 'number');
		} catch (DecisionEvaluationException $e) {
			self::assertSame('invalid_expression', $e->getErrorCode());
			throw $e;
		}
	}//end testMissingRangeBoundIsInvalidExpression()

	/**
	 * @return void
	 */
	public function testNonNumericRangeBoundIsTypeMismatch(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->matches(expression: '[abc..100]', value: 5.0, type: 'number');
		} catch (DecisionEvaluationException $e) {
			self::assertSame('type_mismatch', $e->getErrorCode());
			throw $e;
		}
	}//end testNonNumericRangeBoundIsTypeMismatch()

	// ------------------------------------------------------------------
	// Sets
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testSetMembershipUnquoted(): void {
		self::assertTrue($this->evaluator->matches(expression: 'in (gold, silver, bronze)', value: 'silver', type: 'string'));
		self::assertFalse($this->evaluator->matches(expression: 'in (gold, silver, bronze)', value: 'platinum', type: 'string'));
	}//end testSetMembershipUnquoted()

	/**
	 * @return void
	 */
	public function testSetMembershipQuotedWithCommas(): void {
		self::assertTrue($this->evaluator->matches(expression: 'in ("a b", "c,d")', value: 'c,d', type: 'string'));
		self::assertTrue($this->evaluator->matches(expression: 'in ("a b", "c,d")', value: 'a b', type: 'string'));
	}//end testSetMembershipQuotedWithCommas()

	/**
	 * @return void
	 */
	public function testSetMembershipCaseInsensitivePrefix(): void {
		self::assertTrue($this->evaluator->matches(expression: 'IN (1, 2, 3)', value: 2.0, type: 'number'));
	}//end testSetMembershipCaseInsensitivePrefix()

	// ------------------------------------------------------------------
	// Bare literal
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testBareLiteralString(): void {
		self::assertTrue($this->evaluator->matches(expression: 'gold', value: 'gold', type: 'string'));
		self::assertFalse($this->evaluator->matches(expression: 'gold', value: 'silver', type: 'string'));
	}//end testBareLiteralString()

	/**
	 * @return void
	 */
	public function testBareLiteralNumber(): void {
		self::assertTrue($this->evaluator->matches(expression: '42', value: 42.0, type: 'number'));
	}//end testBareLiteralNumber()

	/**
	 * @return void
	 */
	public function testBareLiteralBooleanTrue(): void {
		self::assertTrue($this->evaluator->matches(expression: 'true', value: true, type: 'boolean'));
		self::assertFalse($this->evaluator->matches(expression: 'true', value: false, type: 'boolean'));
	}//end testBareLiteralBooleanTrue()

	/**
	 * @return void
	 */
	public function testBareLiteralBooleanFalse(): void {
		self::assertTrue($this->evaluator->matches(expression: 'false', value: false, type: 'boolean'));
	}//end testBareLiteralBooleanFalse()

	// ------------------------------------------------------------------
	// coerce() — type coercion matrix
	// ------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testCoerceString(): void {
		self::assertSame('42', $this->evaluator->coerce(value: 42, type: 'string'));
	}//end testCoerceString()

	/**
	 * @return void
	 */
	public function testCoerceNumberFromString(): void {
		self::assertSame(42.5, $this->evaluator->coerce(value: '42.5', type: 'number'));
	}//end testCoerceNumberFromString()

	/**
	 * @return void
	 */
	public function testCoerceNumberRejectsNonNumeric(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->coerce(value: 'not-a-number', type: 'number');
		} catch (DecisionEvaluationException $e) {
			self::assertSame('type_mismatch', $e->getErrorCode());
			throw $e;
		}
	}//end testCoerceNumberRejectsNonNumeric()

	/**
	 * @return void
	 */
	public function testCoerceBooleanFromString(): void {
		self::assertTrue($this->evaluator->coerce(value: 'true', type: 'boolean'));
		self::assertFalse($this->evaluator->coerce(value: 'false', type: 'boolean'));
	}//end testCoerceBooleanFromString()

	/**
	 * @return void
	 */
	public function testCoerceBooleanRejectsGarbage(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->coerce(value: 'maybe', type: 'boolean');
		} catch (DecisionEvaluationException $e) {
			self::assertSame('type_mismatch', $e->getErrorCode());
			throw $e;
		}
	}//end testCoerceBooleanRejectsGarbage()

	/**
	 * @return void
	 */
	public function testCoerceDateFromIsoString(): void {
		$timestamp = $this->evaluator->coerce(value: '2026-01-01T00:00:00+00:00', type: 'date');
		self::assertIsInt($timestamp);
	}//end testCoerceDateFromIsoString()

	/**
	 * @return void
	 */
	public function testCoerceDateRejectsUnparsable(): void {
		$this->expectException(DecisionEvaluationException::class);
		try {
			$this->evaluator->coerce(value: 'not-a-date', type: 'date');
		} catch (DecisionEvaluationException $e) {
			self::assertSame('type_mismatch', $e->getErrorCode());
			throw $e;
		}
	}//end testCoerceDateRejectsUnparsable()

	/**
	 * @return void
	 */
	public function testDateRangeComparison(): void {
		$value = $this->evaluator->coerce(value: '2026-06-15', type: 'date');
		self::assertTrue($this->evaluator->matches(expression: '[2026-01-01..2026-12-31]', value: $value, type: 'date'));
		self::assertFalse($this->evaluator->matches(expression: '[2027-01-01..2027-12-31]', value: $value, type: 'date'));
	}//end testDateRangeComparison()
}//end class
