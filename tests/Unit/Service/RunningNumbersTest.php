<?php
/**
 * Unit tests for RunningNumbers: the Core running-number port on the sequence service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\RunningNumbersInterface;
use OCA\OpenRegister\Service\RunningNumbers;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceServiceInterface;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\RunningNumbers
 * @uses   \OCA\OpenRegister\Service\SequenceScope
 */
class RunningNumbersTest extends TestCase {

	public function testItAnswersTheCorePort(): void {
		$numbers = new RunningNumbers(sequences: $this->createMock(SequenceServiceInterface::class));

		$this->assertInstanceOf(RunningNumbersInterface::class, $numbers);
	}//end testItAnswersTheCorePort()

	public function testItReservesFromTheInstanceWideScopeOfTheKey(): void {
		$seen = null;
		$sequences = $this->createMock(SequenceServiceInterface::class);
		$sequences->expects($this->once())->method('reserveNext')->willReturnCallback(
			static function (SequenceScope $scope) use (&$seen): int {
				$seen = $scope->toArray();
				return 42;
			}
		);

		$reserved = (new RunningNumbers(sequences: $sequences))->reserveNext(scopeKey: 'gen:case|2026');

		$this->assertSame(42, $reserved);
		$this->assertSame(['registerId' => 0, 'schemaId' => 0, 'scopeKey' => 'gen:case|2026'], $seen);
	}//end testItReservesFromTheInstanceWideScopeOfTheKey()

	public function testItAdvancesTheInstanceWideScopeOfTheKey(): void {
		$sequences = $this->createMock(SequenceServiceInterface::class);
		$sequences->expects($this->once())->method('advanceTo')->with(
			$this->callback(static fn (SequenceScope $scope): bool => $scope->toArray() === ['registerId' => 0, 'schemaId' => 0, 'scopeKey' => 'gen:case|2026']),
			120
		);

		(new RunningNumbers(sequences: $sequences))->advanceTo(scopeKey: 'gen:case|2026', reserved: 120);
	}//end testItAdvancesTheInstanceWideScopeOfTheKey()

	public function testACounterFailureReachesTheCaller(): void {
		$sequences = $this->createMock(SequenceServiceInterface::class);
		$sequences->method('reserveNext')->willThrowException(new DbException('locked'));

		$this->expectException(DbException::class);

		(new RunningNumbers(sequences: $sequences))->reserveNext(scopeKey: 'gen:case|2026');
	}//end testACounterFailureReachesTheCaller()
}//end class
