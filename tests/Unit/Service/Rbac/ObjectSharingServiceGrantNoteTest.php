<?php

/**
 * Tests for the note a grant carries on the platform share (platform-share-provider 1.3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectGrantRecords;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectShareSurface;
use OCA\OpenRegister\Service\Rbac\ObjectSharingContext;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\SharerFolderAccess;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A grant's note lands on the share core creates; a grant without one sets none.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectSharingService
 * @covers \OCA\OpenRegister\Service\Rbac\GrantTerms
 */
final class ObjectSharingServiceGrantNoteTest extends TestCase {

	private IShare&MockObject $share;

	private ObjectSharingService $sharing;

	private ObjectEntity $record;

	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pjansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturn($this->createMock(Folder::class));

		$this->share = $this->createMock(IShare::class);
		$this->share->method('getId')->willReturn('41');
		$this->share->method('getFullId')->willReturn('ocinternal:41');
		$shares = $this->createMock(IManager::class);
		$shares->method('newShare')->willReturn($this->share);
		$shares->method('createShare')->willReturnArgument(0);

		$this->sharing = new ObjectSharingService(
			shares: new ObjectShareSurface(folders: $folders, shareManager: $shares, sharerAccess: $this->createMock(SharerFolderAccess::class)),
			records: new ObjectGrantRecords(authorizationWriter: $this->createMock(ObjectAuthorizationWriter::class), scopeResolver: new ObjectScopeResolver(), grantResolver: $this->createMock(ObjectGrantResolver::class), inheritedGrants: $this->createMock(InheritedGrantLister::class)),
			context: new ObjectSharingContext(userSession: $session, groupManager: $groups, logger: new NullLogger()),
			grantManage: new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class))
		);

		$this->record = new ObjectEntity();
		$this->record->setUuid('710d44a4-0000-4000-8000-000000000003');
		$this->record->setOwner('pjansen');
	}

	public function testTheNoteIsSetOnTheShareCoreCreates(): void {
		$this->share->expects($this->once())->method('setNote')->with('Graag voor vrijdag');

		$grant = $this->sharing->grantWithTerms(
			object: $this->record,
			request: new GrantRequest(type: 'user', shareWith: 'tvisser', terms: new GrantTerms(permissions: 1, note: 'Graag voor vrijdag'))
		);

		$this->assertSame('ocinternal:41', $grant['id']);
	}

	public function testABlankOrAbsentNoteSetsNone(): void {
		$this->share->expects($this->never())->method('setNote');

		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'tvisser', terms: new GrantTerms(permissions: 1)));
		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'tvisser', terms: new GrantTerms(permissions: 1, note: '   ')));
	}

	public function testDatingTheTermsKeepsTheNote(): void {
		$dated = (new GrantTerms(permissions: 3, note: 'Zie bijlage'))->dated(expiresAt: '2026-12-31', sharedAt: null);

		$this->assertSame('Zie bijlage', $dated->note);
		$this->assertSame(3, $dated->permissions);
		$this->assertSame('2026-12-31', $dated->expiresAt?->format('Y-m-d'));
	}
}
