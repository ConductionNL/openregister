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

	public function testRescopedKeepsTheSubjectAndOwnerWithoutAReference(): void {
		$user      = $this->createMock(IUser::class);
		$owner     = DraftOwner::portalSubject(subjectRef: 'bsn-1');
		$submitter = (new FormSubmitter(subject: $user, scope: 'form-1', draftId: 'd-1', owner: $owner))->rescoped(scope: 'journey-1', draftId: 'd-2');

		$this->assertSame($user, $submitter->subject);
		$this->assertSame('journey-1', $submitter->scope);
		$this->assertSame('d-2', $submitter->draftId);
		$this->assertSame($owner, $submitter->owner);
	}//end testRescopedKeepsTheSubjectAndOwnerWithoutAReference()

	public function testRescopedDropsTheOwnerWhenAskedAndNoReferenceIsGiven(): void {
		$owner     = DraftOwner::portalSubject(subjectRef: 'bsn-1');
		$submitter = (new FormSubmitter(owner: $owner))->rescoped(scope: 'journey-1', draftId: null, ownerScope: 'journey-run', keepOwner: false);

		$this->assertNull($submitter->owner);
	}//end testRescopedDropsTheOwnerWhenAskedAndNoReferenceIsGiven()

	public function testRescopedAssertsTheScopedOwnerOfAReference(): void {
		$kept      = DraftOwner::portalSubject(subjectRef: 'bsn-1');
		$submitter = (new FormSubmitter(owner: $kept))->rescoped(scope: 'journey-1', draftId: null, ownerScope: 'journey-run', ownerReference: 'abc', keepOwner: false);

		$this->assertSame(DraftOwner::scoped(scope: 'journey-run', reference: 'abc')->value(), $submitter->owner?->value());
		$this->assertSame(FormSubmitter::scopedOwnerValue(scope: 'journey-run', reference: 'abc'), $submitter->owner?->value());
		$this->assertNotSame($kept->value(), $submitter->owner?->value());
	}//end testRescopedAssertsTheScopedOwnerOfAReference()
}//end class
