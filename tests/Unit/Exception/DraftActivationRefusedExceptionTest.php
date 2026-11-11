<?php

/**
 * A refused activation always answers with an error status and a reason a client can branch on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Decision 216: each reason has one status, and none reads as success.
 *
 * @covers \OCA\OpenRegister\Exception\DraftActivationRefusedException
 */
class DraftActivationRefusedExceptionTest extends TestCase {

	/**
	 * Every reason maps to its own 4xx status.
	 */
	public function testEachReasonHasItsStatus(): void {
		$expected = [
			DraftActivationRefusedException::NOT_FOUND => 404,
			DraftActivationRefusedException::NOT_A_DRAFT => 409,
			DraftActivationRefusedException::NOT_THE_OWNER => 403,
			DraftActivationRefusedException::INVALID => 422,
		];

		foreach ($expected as $reason => $status) {
			$refused = new DraftActivationRefusedException(message: 'No.', reason: $reason);
			$this->assertSame($status, $refused->getStatus());
			$this->assertSame($reason, $refused->getReason());
			$this->assertGreaterThanOrEqual(400, $refused->getStatus());
		}
	}//end testEachReasonHasItsStatus()

	/**
	 * The body carries the message, the reason and the findings as given.
	 */
	public function testTheBodyCarriesMessageReasonAndFindings(): void {
		$findings = [['property' => 'subject', 'code' => 'required', 'message' => 'The required properties (subject) are missing']];
		$refused = new DraftActivationRefusedException(message: 'Not complete.', reason: DraftActivationRefusedException::INVALID, findings: $findings);

		$this->assertSame($findings, $refused->getFindings());
		$this->assertSame(['message' => 'Not complete.', 'reason' => 'invalid', 'findings' => $findings], $refused->toBody());
	}//end testTheBodyCarriesMessageReasonAndFindings()

	/**
	 * A reason outside the list is a programming error, not a refusal with a made-up status.
	 */
	public function testAnUnknownReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new DraftActivationRefusedException(message: 'No.', reason: 'because');
	}//end testAnUnknownReasonIsRefused()
}//end class
