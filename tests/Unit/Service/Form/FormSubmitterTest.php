<?php

/**
 * A submitter bundles who submits and where the submit lands.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The defaults and the fields of a submitter.
 */
class FormSubmitterTest extends TestCase {

	public function testAnAnonymousSubmitterHasNoSubjectScopeDraftOrOwner(): void {
		$submitter = new FormSubmitter();

		$this->assertNull($submitter->subject);
		$this->assertSame('', $submitter->scope);
		$this->assertNull($submitter->draftId);
		$this->assertNull($submitter->owner);
	}//end testAnAnonymousSubmitterHasNoSubjectScopeDraftOrOwner()

	public function testItKeepsWhatItIsGiven(): void {
		$user  = $this->createMock(IUser::class);
		$owner = DraftOwner::portalSubject(subjectRef: 'bsn-1');
		$submitter = new FormSubmitter(subject: $user, scope: 'form-1', draftId: 'd-1', owner: $owner);

		$this->assertSame($user, $submitter->subject);
		$this->assertSame('form-1', $submitter->scope);
		$this->assertSame('d-1', $submitter->draftId);
		$this->assertSame($owner, $submitter->owner);
	}//end testItKeepsWhatItIsGiven()
}//end class
