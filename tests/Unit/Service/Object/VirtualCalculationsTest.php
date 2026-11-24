<?php

/**
 * Unit tests for VirtualCalculations.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionEvaluatorInterface;
use OCA\OpenRegister\Service\Object\VirtualCalculations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A render's virtual calculations are evaluated through the Core expression port.
 */
final class VirtualCalculationsTest extends TestCase {

	/**
	 * The declarations come from the schema's configuration; none is an empty list.
	 *
	 * @return void
	 */
	public function testTheDeclarationsComeFromTheSchemaConfiguration(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-calculations' => ['total' => ['expression' => 1]]]);

		self::assertSame(['total' => ['expression' => 1]], VirtualCalculations::declaredBy(schema: $schema));
		self::assertSame([], VirtualCalculations::declaredBy(schema: null));
		self::assertSame([], VirtualCalculations::declaredBy(schema: new Schema()));
	}//end testTheDeclarationsComeFromTheSchemaConfiguration()

	/**
	 * A virtual calculation is evaluated, a materialised one is skipped, and a date comes back as ISO 8601.
	 *
	 * @return void
	 */
	public function testVirtualCalculationsAreEvaluatedAndMaterialisedOnesSkipped(): void {
		$evaluator = $this->createMock(ExpressionEvaluatorInterface::class);
		$evaluator->expects($this->exactly(2))->method('evaluate')->willReturnCallback(
			static function (array $object, mixed $expression): mixed {
				if ($expression === 'when') {
					return new DateTimeImmutable('2026-01-02T03:04:05+00:00');
				}

				return ($object['a'] + 1);
			}
		);

		$values = (new VirtualCalculations(evaluator: $evaluator, logger: $this->createMock(LoggerInterface::class)))->values(
			calculations: [
				'next'   => ['expression' => 'plus'],
				'when'   => ['expression' => 'when'],
				'stored' => ['expression' => 'plus', 'materialise' => true],
				'broken' => 'not-a-declaration',
			],
			payload: ['a' => 41]
		);

		self::assertSame(['next' => 42, 'when' => '2026-01-02T03:04:05+00:00'], $values);
	}//end testVirtualCalculationsAreEvaluatedAndMaterialisedOnesSkipped()

	/**
	 * A calculation that fails is left out and logged, the others still answer.
	 *
	 * @return void
	 */
	public function testAFailedCalculationIsLeftOutAndLogged(): void {
		$evaluator = $this->createMock(ExpressionEvaluatorInterface::class);
		$evaluator->method('evaluate')->willReturnCallback(
			static function (array $object, mixed $expression): mixed {
				if ($expression === 'bad') {
					throw new RuntimeException('unknown operator');
				}

				return 'ok';
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$values = (new VirtualCalculations(evaluator: $evaluator, logger: $logger))->values(
			calculations: ['bad' => ['expression' => 'bad'], 'good' => ['expression' => 'good']],
			payload: []
		);

		self::assertSame(['good' => 'ok'], $values);
	}//end testAFailedCalculationIsLeftOutAndLogged()
}//end class
