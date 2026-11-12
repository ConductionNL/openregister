<?php

/**
 * The authority a submit's writes run under: RBAC, ownership and the stamped owner.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormWriteContext;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Each kind of writer, and the flags its saves carry.
 */
class FormWriteContextTest extends TestCase {

	public function testAnAnonymousWriteIsUnderRbacAndUnowned(): void {
		$context = new FormWriteContext(subject: null);

		$this->assertTrue($context->rbac());
		$this->assertTrue($context->unowned());
		$this->assertNull($context->explicitOwner());
	}//end testAnAnonymousWriteIsUnderRbacAndUnowned()

	public function testAUsersWriteIsUnderRbacAndOwned(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$context = new FormWriteContext(subject: $user, owner: DraftOwner::ofUser(user: $user));

		$this->assertFalse($context->isScoped());
		$this->assertTrue($context->rbac());
		$this->assertFalse($context->unowned());
		$this->assertNull($context->explicitOwner());
	}//end testAUsersWriteIsUnderRbacAndOwned()

	public function testAScopedNewDraftSkipsRbacAndStampsTheOwner(): void {
		$context = new FormWriteContext(subject: null, status: 'draft', owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'));

		$this->assertTrue($context->isScoped());
		$this->assertFalse($context->rbac());
		$this->assertFalse($context->unowned());
		$this->assertSame('portal:bsn-1', $context->explicitOwner());
	}//end testAScopedNewDraftSkipsRbacAndStampsTheOwner()

	public function testAScopedUpdateSkipsRbacAndStampsNothing(): void {
		$context = new FormWriteContext(subject: null, status: 'active', uuid: 'd-1', owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'));

		$this->assertFalse($context->rbac());
		$this->assertNull($context->explicitOwner());
	}//end testAScopedUpdateSkipsRbacAndStampsNothing()
}//end class
