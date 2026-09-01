<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowAdvanceBudget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The three spellings of the advance budget, and everything that is refused.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
 */
class FlowAdvanceBudgetTest extends TestCase {

	/**
	 * @return array<string, array{0: mixed, 1: int|null, 2: int|string}>
	 */
	public static function accepted(): array {
		return [
			'zero' => [0, 0, 0],
			'three' => [3, 3, 3],
			'a numeric string' => ['3', 3, 3],
			'all' => ['all', null, 'all'],
			'ALL, trimmed' => [' ALL ', null, 'all'],
		];
	}//end accepted()

	#[DataProvider('accepted')]
	public function testTheThreeShapesAreAccepted(mixed $value, ?int $transitions, int|string $stored): void {
		$budget = FlowAdvanceBudget::fromValue(value: $value);

		$this->assertSame($transitions, $budget->transitions());
		$this->assertSame($stored, $budget->toStored());
		$this->assertSame($transitions === null, $budget->isUnlimited());
		$this->assertSame($transitions !== 0, $budget->advancesInRequest());
	}//end testTheThreeShapesAreAccepted()

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refused(): array {
		return [
			'null' => [null, 'null'],
			'empty string' => ['', "''"],
			'minus one' => [-1, '-1'],
			'unlimited' => ['unlimited', 'unlimited'],
			'a fraction' => [1.5, '1.5'],
			'a boolean' => [true, 'true'],
		];
	}//end refused()

	/**
	 * Every refusal names the value it refused, so the author sees what they
	 * wrote rather than a generic complaint.
	 */
	#[DataProvider('refused')]
	public function testEverythingElseIsRefusedNamingTheValue(mixed $value, string $named): void {
		try {
			FlowAdvanceBudget::fromValue(value: $value);
			$this->fail('Expected the budget to be refused.');
		} catch (UnexpectedValueException $refusal) {
			$this->assertStringContainsString($named, $refusal->getMessage());
			$this->assertStringContainsString('"all"', $refusal->getMessage(), 'the refusal states the spelling of unlimited');
		}
	}//end testEverythingElseIsRefusedNamingTheValue()

	/**
	 * Design D-4: null is not "unlimited" and not "absent". It is refused,
	 * and the message says why.
	 */
	public function testNullIsRefusedAsNullNotReadAsUnlimited(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/advance is null/');

		FlowAdvanceBudget::fromConfig(config: ['advance' => null]);
	}//end testNullIsRefusedAsNullNotReadAsUnlimited()

	/**
	 * An ABSENT key is the default, and the default is zero. This is the
	 * distinction `??` cannot make and the reason fromConfig takes the array.
	 */
	public function testAnAbsentBudgetIsZero(): void {
		$budget = FlowAdvanceBudget::fromConfig(config: ['title' => 'x']);

		$this->assertSame(0, $budget->transitions());
		$this->assertFalse($budget->advancesInRequest());
	}//end testAnAbsentBudgetIsZero()
}//end class
