<?php

/**
 * Unit tests for the sharer's view of an object folder.
 *
 * The view lets a user who may share an object reach its folder, which core
 * requires of whoever creates a share. These tests pin that it never carries
 * more than the user holds on the object's files, never the re-share bit, and
 * that it ends once its holder may no longer share.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions and mock builders.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\MountRefresh;
use OCA\OpenRegister\Service\Rbac\SharerFolderAccess;
use OCP\Constants;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IAttributes;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SharerFolderAccessTest extends TestCase {

	private IManager&MockObject $shareManager;

	private ObjectFileAccess&MockObject $fileAccess;

	private GrantManageGuard&MockObject $grantManage;

	private IUserManager&MockObject $userManager;

	protected function setUp(): void {
		parent::setUp();
		$this->shareManager = $this->createMock(IManager::class);
		$this->fileAccess = $this->createMock(ObjectFileAccess::class);
		$this->grantManage = $this->createMock(GrantManageGuard::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user(uid: $uid));
		$this->shareManager->method('getSharesBy')->willReturn([]);
	}//end setUp()

	private function access(): SharerFolderAccess {
		return new SharerFolderAccess(
			shareManager: $this->shareManager,
			fileAccess: $this->fileAccess,
			grantManage: $this->grantManage,
			userManager: $this->userManager,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			mounts: new MountRefresh(
				providers: $this->mountProviders(),
				cache: $this->createMock(IUserMountCache::class),
				manager: $this->createMock(IMountManager::class)
			)
		);
	}//end access()

	private function mountProviders(): IMountProviderCollection {
		$providers = $this->createMock(IMountProviderCollection::class);
		$providers->method('getMountsForUser')->willReturn([]);
		return $providers;
	}//end mountProviders()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	private function folder(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($this->user(uid: 'openregister'));
		$folder->method('getId')->willReturn(42);
		return $folder;
	}//end folder()

	private function holds(bool $read, bool $add, bool $delete): void {
		$this->fileAccess->method('mayRead')->willReturn($read);
		$this->fileAccess->method('mayChangeFiles')->willReturnCallback(
			static fn (ObjectEntity $object, string $verb): bool => ($verb === ObjectFileAccess::ADD_FILES ? $add : $delete)
		);
	}//end holds()

	private function view(string $holder): IShare {
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturnCallback(
			static fn (string $scope, string $key): mixed => ($scope === 'openregister' && $key === 'sharerAccess') ? true : null
		);
		$view = $this->createMock(IShare::class);
		$view->method('getShareType')->willReturn(IShare::TYPE_USER);
		$view->method('getSharedBy')->willReturn('openregister');
		$view->method('getShareOwner')->willReturn('openregister');
		$view->method('getSharedWith')->willReturn($holder);
		$view->method('getAttributes')->willReturn($attributes);
		return $view;
	}//end view()

	public function testAReaderCannotShareMoreThanTheyHold(): void {
		$this->holds(read: true, add: false, delete: false);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->expectException(NotAuthorizedException::class);
		$this->access()->ensureFor(object: new ObjectEntity(), folder: $this->folder(), uid: 'alice', permissions: Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
	}//end testAReaderCannotShareMoreThanTheyHold()

	public function testAUserWhoCannotReadTheObjectGetsNoView(): void {
		$this->holds(read: false, add: true, delete: true);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->expectException(NotAuthorizedException::class);
		$this->access()->ensureFor(object: new ObjectEntity(), folder: $this->folder(), uid: 'alice', permissions: Constants::PERMISSION_READ);
	}//end testAUserWhoCannotReadTheObjectGetsNoView()

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: int}>
	 */
	public static function heldRights(): array {
		return [
			'read only' => [false, false, Constants::PERMISSION_READ],
			'read and add' => [true, false, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE],
			'everything' => [true, true, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE],
		];
	}//end heldRights()

	/**
	 * @dataProvider heldRights
	 */
	public function testANewViewCarriesExactlyTheHeldRightsMarkedAndNeverReshare(bool $add, bool $delete, int $expected): void {
		$this->holds(read: true, add: $add, delete: $delete);

		$attributes = $this->createMock(IAttributes::class);
		$attributes->expects($this->once())->method('setAttribute')->with('openregister', 'sharerAccess', true);

		$view = $this->createMock(IShare::class);
		$view->method('newAttributes')->willReturn($attributes);
		$view->expects($this->once())->method('setSharedBy')->with('openregister');
		$view->expects($this->once())->method('setSharedWith')->with('alice');
		$view->expects($this->once())->method('setShareType')->with(IShare::TYPE_USER);
		$view->expects($this->once())->method('setPermissions')->with($this->callback(
			static fn (int $permissions): bool => $permissions === $expected && ($permissions & Constants::PERMISSION_SHARE) === 0
		));
		$view->expects($this->once())->method('setAttributes')->with($attributes);

		$this->shareManager->method('newShare')->willReturn($view);
		$this->shareManager->expects($this->once())->method('createShare')->with($view)->willReturn($view);
		$this->shareManager->expects($this->once())->method('acceptShare')->with($view, 'alice');

		$this->access()->ensureFor(object: new ObjectEntity(), folder: $this->folder(), uid: 'alice', permissions: Constants::PERMISSION_READ);
	}//end testANewViewCarriesExactlyTheHeldRightsMarkedAndNeverReshare()

	public function testTheFolderOwnerNeedsNoView(): void {
		$this->fileAccess->expects($this->never())->method('mayRead');
		$this->shareManager->expects($this->never())->method('createShare');

		$this->access()->ensureFor(object: new ObjectEntity(), folder: $this->folder(), uid: 'openregister', permissions: Constants::PERMISSION_READ);
	}//end testTheFolderOwnerNeedsNoView()

	public function testAViewIsEndedWhenItsHolderMayNoLongerShare(): void {
		$this->grantManage->method('admittedAs')->willReturn(null);
		$view = $this->view(holder: 'alice');
		$this->shareManager->expects($this->once())->method('deleteShare')->with($view);

		$this->assertSame(1, $this->access()->endStaleViews(object: new ObjectEntity(), views: [$view]));
	}//end testAViewIsEndedWhenItsHolderMayNoLongerShare()

	public function testAViewIsKeptWhileItsHolderMayShare(): void {
		$this->grantManage->method('admittedAs')->willReturn(GrantManageGuard::AS_OWNER);
		$this->shareManager->expects($this->never())->method('deleteShare');

		$this->assertSame(0, $this->access()->endStaleViews(object: new ObjectEntity(), views: [$this->view(holder: 'alice')]));
	}//end testAViewIsKeptWhileItsHolderMayShare()

	public function testAViewOnAGoneObjectIsEnded(): void {
		$this->grantManage->expects($this->never())->method('admittedAs');
		$this->shareManager->expects($this->once())->method('deleteShare');

		$this->assertSame(1, $this->access()->endStaleViews(object: null, views: [$this->view(holder: 'alice')]));
	}//end testAViewOnAGoneObjectIsEnded()

	/**
	 * A share manager that answers the holder's grants on the folder, and records what it deletes.
	 *
	 * @param array<int, IShare> $grants  The holder's grants, keyed by share type.
	 * @param array<int, IShare> $deleted Receives the deleted shares, in order.
	 *
	 * @return void
	 */
	private function grantsMadeBy(string $holder, array $grants, array &$deleted): void {
		$this->shareManager = $this->createMock(IManager::class);
		$this->shareManager->method('shareProviderExists')->willReturn(true);
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			static fn (string $uid, int $type, mixed $path, bool $reshares): array => ($uid === $holder && $reshares === false && isset($grants[$type]) === true) ? [$grants[$type]] : []
		);
		$this->shareManager->method('deleteShare')->willReturnCallback(static function (IShare $share) use (&$deleted): void {
			$deleted[] = $share;
		});
	}//end grantsMadeBy()

	public function testEndingAViewRevokesTheGrantsAndLinksItsHolderMade(): void {
		$this->grantManage->method('admittedAs')->willReturn(null);
		$grant = $this->createMock(IShare::class);
		$link = $this->createMock(IShare::class);
		$deleted = [];
		$this->grantsMadeBy(holder: 'alice', grants: [IShare::TYPE_USER => $grant, IShare::TYPE_LINK => $link], deleted: $deleted);
		$view = $this->view(holder: 'alice');

		$this->assertSame(1, $this->access()->endStaleViews(object: new ObjectEntity(), views: [$view]));
		$this->assertSame([$grant, $link, $view], $deleted, 'the grants end first, so core passes nothing on, then the view');
	}//end testEndingAViewRevokesTheGrantsAndLinksItsHolderMade()

	public function testAKeptViewKeepsItsHoldersGrants(): void {
		$this->grantManage->method('admittedAs')->willReturn(GrantManageGuard::AS_OWNER);
		$deleted = [];
		$this->grantsMadeBy(holder: 'alice', grants: [IShare::TYPE_USER => $this->createMock(IShare::class)], deleted: $deleted);

		$this->assertSame(0, $this->access()->endStaleViews(object: new ObjectEntity(), views: [$this->view(holder: 'alice')]));
		$this->assertSame([], $deleted);
	}//end testAKeptViewKeepsItsHoldersGrants()

	public function testARemovedUsersViewsEndWithTheirGrants(): void {
		$grant = $this->createMock(IShare::class);
		$deleted = [];
		$this->grantsMadeBy(holder: 'alice', grants: [IShare::TYPE_GROUP => $grant], deleted: $deleted);
		$view = $this->view(holder: 'alice');
		$plainShare = $this->createMock(IShare::class);
		$plainShare->method('getAttributes')->willReturn(null);
		$this->shareManager->method('getSharedWith')->willReturn([$view, $plainShare]);

		$this->assertSame(1, $this->access()->endAllViewsOf(uid: 'alice'));
		$this->assertSame([$grant, $view], $deleted, 'a share that is not a sharer view stays');
	}//end testARemovedUsersViewsEndWithTheirGrants()
}//end class
