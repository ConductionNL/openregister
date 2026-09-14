<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\UserLinkWriter;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The account side of people-on-objects: a Nextcloud user on an object.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
 */
class UserLinkWriterTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The accounts.
	 *
	 * @var IUserManager&MockObject
	 */
	private $users;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private $session;

	/**
	 * The writer under test.
	 *
	 * @var UserLinkWriter
	 */
	private UserLinkWriter $writer;

	/**
	 * Build the writer on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectContactAndRole', 'insert', 'update'])
			->getMock();
		$this->users = $this->createMock(IUserManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/avatar/jan/64');

		$this->writer = new UserLinkWriter($this->links, $this->users, $this->session, $urls);
	}//end setUp()

	/**
	 * Put an account and a signed-in user in place.
	 *
	 * @param string $uid The account to link.
	 *
	 * @return void
	 */
	private function accounts(string $uid = 'jan'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn('Jan de Vries');
		$user->method('getEMailAddress')->willReturn('jan@example.nl');
		$this->users->method('get')->willReturn($user);

		$linker = $this->createMock(IUser::class);
		$linker->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($linker);
	}//end accounts()

	/**
	 * A new user link takes its identity from the account and its uid from the prefix.
	 *
	 * @return void
	 */
	public function testANewUserLinkCarriesTheAccountsIdentity(): void {
		$this->accounts();
		$this->links->method('findByObjectContactAndRole')->willReturn(null);
		$this->links->expects($this->never())->method('update');
		$this->links->expects($this->once())
			->method('insert')
			->willReturnCallback(
				function (ContactLink $link): ContactLink {
					$this->assertSame('case-1', $link->getObjectUuid());
					$this->assertSame('user:jan', $link->getContactUid());
					$this->assertSame('jan', $link->getUserId());
					$this->assertSame('Jan de Vries', $link->getDisplayName());
					$this->assertSame('jan@example.nl', $link->getEmail());
					$this->assertSame('/index.php/avatar/jan/64', $link->getAvatarUrl());
					$this->assertSame('handler', $link->getRole());
					$this->assertSame('admin', $link->getLinkedBy());
					$this->assertTrue($link->isUserLink());
					return $link;
				}
			);

		$this->writer->write(objectUuid: 'case-1', registerId: 5, schemaId: 7, userId: 'jan', role: 'handler');
	}//end testANewUserLinkCarriesTheAccountsIdentity()

	/**
	 * The same user in the same role updates the row rather than adding one.
	 *
	 * @return void
	 */
	public function testTheSameUserInTheSameRoleUpdatesTheRow(): void {
		$this->accounts();
		$existing = new ContactLink();
		$existing->setObjectUuid('case-1');
		$existing->setContactUid('user:jan');
		$existing->setRole('handler');
		$this->links->method('findByObjectContactAndRole')->willReturn($existing);
		$this->links->expects($this->never())->method('insert');
		$this->links->expects($this->once())
			->method('update')
			->willReturnCallback(
				static function (ContactLink $link): ContactLink {
					return $link;
				}
			);

		$link = $this->writer->write(objectUuid: 'case-1', registerId: 5, schemaId: 7, userId: 'jan', role: 'handler');

		$this->assertSame('jan', $link->getUserId());
	}//end testTheSameUserInTheSameRoleUpdatesTheRow()

	/**
	 * An id no account has is a 404, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownUserIsRefused(): void {
		$this->users->method('get')->willReturn(null);
		$this->links->expects($this->never())->method('insert');
		$this->links->expects($this->never())->method('update');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->writer->write(objectUuid: 'case-1', registerId: 5, schemaId: 7, userId: 'ghost', role: null);
	}//end testAnUnknownUserIsRefused()

	/**
	 * Nobody signed in: no link is written.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotLink(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$this->users->method('get')->willReturn($user);
		$this->session->method('getUser')->willReturn(null);
		$this->links->expects($this->never())->method('insert');

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->writer->write(objectUuid: 'case-1', registerId: 5, schemaId: 7, userId: 'jan', role: null);
	}//end testAnAnonymousCallerCannotLink()
}//end class
