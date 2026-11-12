<?php

/**
 * A refused submit carries its status and its findings to the response.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Status, findings and the response body.
 *
 * @covers \OCA\OpenRegister\Exception\FormSubmitRefusedException
 */
class FormSubmitRefusedExceptionTest extends TestCase {

	/**
	 * The body is `{ message, findings }`; the status travels beside it.
	 */
	public function testTheBodyCarriesTheFindings(): void {
		$findings = [['property' => 'caseType', 'code' => 'required', 'message' => 'caseType is required']];
		$refused = new FormSubmitRefusedException(message: 'The form was not accepted.', status: 422, findings: $findings);

		$this->assertSame(422, $refused->getStatus());
		$this->assertSame($findings, $refused->getFindings());
		$this->assertSame(['message' => 'The form was not accepted.', 'findings' => $findings], $refused->toBody());
	}//end testTheBodyCarriesTheFindings()

	/**
	 * A status outside 4xx/5xx is refused at construction: it would read as success.
	 */
	public function testANonErrorStatusIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		new FormSubmitRefusedException(message: 'x', status: 200);
	}//end testANonErrorStatusIsRejected()
}//end class
