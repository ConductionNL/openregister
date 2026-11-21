<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffSuccessUpdateChecks}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
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

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry;
use OCA\OpenRegister\Service\Handoff\HandoffSuccessUpdateChecks;
use PHPUnit\Framework\TestCase;

/**
 * An `onSuccess.set` block may only name properties the source schema defines.
 *
 * @covers \OCA\OpenRegister\Service\Handoff\HandoffSuccessUpdateChecks
 * @uses   \OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry
 */
class HandoffSuccessUpdateChecksTest extends TestCase {

	/**
	 * Build an entry with an optional onSuccess block.
	 *
	 * @param array<string, mixed> $extra Extra entry keys.
	 *
	 * @return HandoffAnnotationEntry
	 */
	private function entry(array $extra): HandoffAnnotationEntry {
		return new HandoffAnnotationEntry(
			array_merge(['id' => 'close'], $extra),
			1,
			null,
			['status' => ['type' => 'string'], 'closedAt' => ['type' => 'string']]
		);

	}//end entry()

	/**
	 * No onSuccess block means nothing to check.
	 *
	 * @return void
	 */
	public function testAbsentOnSuccessIsValid(): void {
		$this->assertSame([], (new HandoffSuccessUpdateChecks())->errors($this->entry([])));

	}//end testAbsentOnSuccessIsValid()

	/**
	 * A set naming only defined properties is valid.
	 *
	 * @return void
	 */
	public function testDefinedPropertiesAreValid(): void {
		$errors = (new HandoffSuccessUpdateChecks())->errors(
			$this->entry(['onSuccess' => ['set' => ['status' => 'closed', 'closedAt' => 'now']]])
		);

		$this->assertSame([], $errors);

	}//end testDefinedPropertiesAreValid()

	/**
	 * A scalar onSuccess, a missing set, a scalar set and an empty set are all one fault.
	 *
	 * @return void
	 */
	public function testMalformedSetIsOneFault(): void {
		$checks   = new HandoffSuccessUpdateChecks();
		$expected = [[
			'code'    => 'handoff-bad-success-update',
			'message' => 'Handoff "close": `onSuccess` must carry a non-empty `set` map.',
		]];

		$this->assertSame($expected, $checks->errors($this->entry(['onSuccess' => 'yes'])));
		$this->assertSame($expected, $checks->errors($this->entry(['onSuccess' => ['unset' => ['a']]])));
		$this->assertSame($expected, $checks->errors($this->entry(['onSuccess' => ['set' => 'status']])));
		$this->assertSame($expected, $checks->errors($this->entry(['onSuccess' => ['set' => []]])));

	}//end testMalformedSetIsOneFault()

	/**
	 * Each undefined property is its own error, in the order named.
	 *
	 * @return void
	 */
	public function testUndefinedPropertiesAreEachReported(): void {
		$errors = (new HandoffSuccessUpdateChecks())->errors(
			$this->entry(['onSuccess' => ['set' => ['bogus' => 1, 'status' => 'x', 'other' => 2]]])
		);

		$this->assertSame(
			[
				[
					'code'    => 'handoff-bad-success-update',
					'message' => 'Handoff "close": onSuccess.set names property "bogus" which the source schema does not define.',
				],
				[
					'code'    => 'handoff-bad-success-update',
					'message' => 'Handoff "close": onSuccess.set names property "other" which the source schema does not define.',
				],
			],
			$errors
		);

	}//end testUndefinedPropertiesAreEachReported()
}//end class
