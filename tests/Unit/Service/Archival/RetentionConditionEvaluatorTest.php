<?php

/**
 * Unit tests for RetentionConditionEvaluator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace Unit\Service\Archival;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator;
use PHPUnit\Framework\TestCase;

final class RetentionConditionEvaluatorTest extends TestCase {

	private RetentionConditionEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new RetentionConditionEvaluator();
	}//end setUp()

	public function testNumericLessThanTrue(): void {
		self::assertTrue($this->evaluator->evaluate('statusCode < 400', ['statusCode' => 200]));
	}//end testNumericLessThanTrue()

	public function testNumericLessThanFalse(): void {
		self::assertFalse($this->evaluator->evaluate('statusCode < 400', ['statusCode' => 500]));
	}//end testNumericLessThanFalse()

	public function testGreaterOrEqual(): void {
		self::assertTrue($this->evaluator->evaluate('statusCode >= 400', ['statusCode' => 500]));
		self::assertTrue($this->evaluator->evaluate('statusCode >= 400', ['statusCode' => 400]));
		self::assertFalse($this->evaluator->evaluate('statusCode >= 400', ['statusCode' => 399]));
	}//end testGreaterOrEqual()

	public function testEqualityStringDoubleQuoted(): void {
		self::assertTrue($this->evaluator->evaluate('status == "success"', ['status' => 'success']));
		self::assertFalse($this->evaluator->evaluate('status == "success"', ['status' => 'failed']));
	}//end testEqualityStringDoubleQuoted()

	public function testEqualityStringSingleQuoted(): void {
		self::assertTrue($this->evaluator->evaluate("status == 'success'", ['status' => 'success']));
	}//end testEqualityStringSingleQuoted()

	public function testInequality(): void {
		self::assertTrue($this->evaluator->evaluate('status != "success"', ['status' => 'failed']));
		self::assertFalse($this->evaluator->evaluate('status != "success"', ['status' => 'success']));
	}//end testInequality()

	public function testBoolLiteral(): void {
		self::assertTrue($this->evaluator->evaluate('archived == true', ['archived' => true]));
		self::assertTrue($this->evaluator->evaluate('archived != true', ['archived' => false]));
	}//end testBoolLiteral()

	public function testNullLiteral(): void {
		self::assertTrue($this->evaluator->evaluate('foo == null', ['foo' => null]));
		self::assertFalse($this->evaluator->evaluate('foo == null', ['foo' => 'x']));
	}//end testNullLiteral()

	public function testFloatLiteral(): void {
		self::assertTrue($this->evaluator->evaluate('latency < 1.5', ['latency' => 0.7]));
		self::assertFalse($this->evaluator->evaluate('latency < 1.5', ['latency' => 2.1]));
	}//end testFloatLiteral()

	public function testMissingFieldReturnsFalse(): void {
		self::assertFalse($this->evaluator->evaluate('statusCode < 400', ['foo' => 'bar']));
	}//end testMissingFieldReturnsFalse()

	public function testMalformedConditionThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->evaluator->evaluate('statusCode 400', ['statusCode' => 200]);
	}//end testMalformedConditionThrows()

	public function testUnknownLiteralThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->evaluator->evaluate('status == bareword', ['status' => 'x']);
	}//end testUnknownLiteralThrows()

	public function testTwoCharOpDoesNotGetEatenByOneCharPrefix(): void {
		// <= must not match as <; >= must not match as >; == must not be eaten.
		self::assertTrue($this->evaluator->evaluate('n <= 5', ['n' => 5]));
		self::assertTrue($this->evaluator->evaluate('n >= 5', ['n' => 5]));
		self::assertTrue($this->evaluator->evaluate('n == 5', ['n' => 5]));
	}//end testTwoCharOpDoesNotGetEatenByOneCharPrefix()
}//end class
