<?php

/**
 * A submission names who submits it and which idempotency scope its key belongs to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The submitter and key scope of a submission.
 */
class FormSubmissionTest extends TestCase {

	public function testASignedInSubjectIsTheSubmitterOverAnyOwner(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$submission = new FormSubmission(writes: [], payload: [], subject: $user, owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'));

		$this->assertSame('user:alice', $submission->submitter());
	}//end testASignedInSubjectIsTheSubmitterOverAnyOwner()

	public function testAScopedOwnerIsTheSubmitterWithoutASubject(): void {
		$submission = new FormSubmission(writes: [], payload: [], owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'));

		$this->assertSame('scope:portal:bsn-1', $submission->submitter());
	}//end testAScopedOwnerIsTheSubmitterWithoutASubject()

	public function testNobodyIsTheAnonymousSubmitter(): void {
		$this->assertSame('anonymous', (new FormSubmission(writes: [], payload: []))->submitter());
	}//end testNobodyIsTheAnonymousSubmitter()

	public function testTheFormIdIsTheKeyScope(): void {
		$this->assertSame('form-1', (new FormSubmission(writes: [['register' => 'r', 'schema' => 's']], payload: [], scope: 'form-1'))->keyScope());
	}//end testTheFormIdIsTheKeyScope()

	public function testWithoutAFormTheFirstDestinationIsTheKeyScope(): void {
		$submission = new FormSubmission(writes: [['register' => 'r', 'schema' => 's'], ['register' => 'x', 'schema' => 'y']], payload: []);

		$this->assertSame('destination:["r","s"]', $submission->keyScope());
		$this->assertSame('destination:[null,null]', (new FormSubmission(writes: [], payload: []))->keyScope());
	}//end testWithoutAFormTheFirstDestinationIsTheKeyScope()
}//end class
