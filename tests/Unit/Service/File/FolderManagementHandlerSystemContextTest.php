<?php

declare(strict_types=1);

/**
 * Tests for the two questions `FolderManagementHandler` asks about a folder.
 *
 * `assertFolderIsAccessible()` answers "can the acting user read this?", which
 * is right for a caller-supplied `@self.folder`. Two callers could never get a
 * useful answer out of it:
 *
 *  - a repair step or cron job, which has no `IUser` at all and so hit the
 *    default-deny, and
 *  - any user re-saving an object whose folder somebody else created, because
 *    the folder lives in the creator's home and nobody else's.
 *
 * Both failed silently: the step caught the denial and logged "could not run"
 * while `occ upgrade` reported success, and the user got a bare 403.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @spec openspec/changes/validate-self-folder-access/specs/self-folder-access-control/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * System-context recognition and managed-folder re-validation.
 */
class FolderManagementHandlerSystemContextTest extends TestCase {

	/**
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IUserMountCache&MockObject
	 */
	private IUserMountCache $mountCache;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * @var FileService&MockObject
	 */
	private FileService $fileService;

	private FolderManagementHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->mountCache = $this->createMock(IUserMountCache::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->fileService = $this->createMock(FileService::class);

		$this->handler = new FolderManagementHandler(
			rootFolder: $this->rootFolder,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			userSession: $this->userSession,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->auditTrailMapper,
			mountCache: $this->mountCache
		);
		$this->handler->setFileService($this->fileService);

	}//end setUp()

	/**
	 * Build a user mock with the given UID.
	 *
	 * @param string $uid The user identifier.
	 *
	 * @return IUser&MockObject
	 */
	private function makeUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end makeUser()

	/**
	 * Wire a user-folder lookup for one UID.
	 *
	 * @param string $uid The UID whose home is being mocked.
	 * @param integer $folderId The id the lookup is asked for.
	 * @param array $nodes The nodes the lookup resolves to.
	 *
	 * @return void
	 */
	private function mockUserFolderLookup(string $uid, int $folderId, array $nodes): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with($folderId)->willReturn($nodes);
		$this->rootFolder->method('getUserFolder')->with($uid)->willReturn($userFolder);

	}//end mockUserFolderLookup()

	/**
	 * Wire the mount cache to report one mount path for a file id.
	 *
	 * @param integer $folderId The file id.
	 * @param string $path The path the mount cache reports.
	 *
	 * @return void
	 */
	private function mockMountPath(int $folderId, string $path): void {
		$mount = $this->createMock(ICachedMountFileInfo::class);
		$mount->method('getPath')->willReturn($path);
		$this->mountCache->method('getMountsForFileId')->with($folderId)->willReturn([$mount]);

	}//end mockMountPath()

	/**
	 * Build a bare object entity carrying a folder binding.
	 *
	 * @param string $folder The bound folder id.
	 *
	 * @return ObjectEntity
	 */
	private function makeObjectEntity(string $folder): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-system-context');
		$entity->setRegister('1');
		$entity->setSchema('1');
		$entity->setFolder($folder);

		return $entity;
	}//end makeObjectEntity()

	/**
	 * A sessionless system operation resolves the app's own principal.
	 *
	 * This is the repair step / cron case. There is no `IUser`, so before the
	 * fix this landed on the default-deny and the step could not run at all.
	 */
	public function testSystemContextResolvesTheAppPrincipal(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->fileService->method('getUser')->willReturn($this->makeUser('openregister'));

		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$this->mockUserFolderLookup(uid: 'openregister', folderId: 42, nodes: [$folder]);

		$this->auditTrailMapper->expects($this->never())->method('insert');

		$resolved = SystemOperationContext::run(
			fn (): Folder => $this->handler->assertFolderIsAccessible(folderId: '42')
		);

		$this->assertSame($folder, $resolved);

	}//end testSystemContextResolvesTheAppPrincipal()

	/**
	 * The guard is recognised, not skipped: the app principal is still checked.
	 *
	 * A system context that names a folder its own principal cannot read is
	 * refused exactly as any other caller would be — this is what separates
	 * "recognise the context" from "stop checking".
	 */
	public function testSystemContextStillChecksTheFolder(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->fileService->method('getUser')->willReturn($this->makeUser('openregister'));

		$this->mockUserFolderLookup(uid: 'openregister', folderId: 77, nodes: []);

		$this->auditTrailMapper->expects($this->once())->method('insert');
		$this->expectException(FolderAccessDeniedException::class);

		SystemOperationContext::run(
			fn (): Folder => $this->handler->assertFolderIsAccessible(folderId: '77')
		);

	}//end testSystemContextStillChecksTheFolder()

	/**
	 * OUTSIDE a system scope, a userless caller is still denied.
	 *
	 * The control for the two tests above. An anonymous request has no
	 * principal to resolve and must keep landing on the default-deny; if this
	 * ever goes green by accident, the recognition has become a blanket
	 * exemption.
	 */
	public function testUserlessRequestOutsideASystemScopeIsStillDenied(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->fileService->expects($this->never())->method('getUser');

		$this->auditTrailMapper->expects($this->once())->method('insert');
		$this->expectException(FolderAccessDeniedException::class);

		$this->handler->assertFolderIsAccessible(folderId: '42');

	}//end testUserlessRequestOutsideASystemScopeIsStillDenied()

	/**
	 * A stored binding into OpenRegister's own tree admits a non-creating user.
	 *
	 * Bob cannot read the folder through his own home — it was created in
	 * alice's — but it is a folder OpenRegister manages, so re-validating the
	 * binding must not refuse his write.
	 */
	public function testManagedBindingAdmitsAUserWhoDidNotCreateIt(): void {
		$this->userSession->method('getUser')->willReturn($this->makeUser('bob'));
		$this->mockMountPath(folderId: 167, path: '/alice/files/Open Registers/Cases Register/case-1');

		$this->handler->assertManagedFolderIsAccessible(
			folderId: '167',
			objectEntity: $this->makeObjectEntity('167')
		);

		// No exception is the assertion; make it explicit for the reader.
		$this->addToAssertionCount(1);

	}//end testManagedBindingAdmitsAUserWhoDidNotCreateIt()

	/**
	 * A stored binding OUTSIDE the managed tree is still refused.
	 *
	 * This is the planted cross-tenant binding the re-validation exists to
	 * catch: it names a node in somebody's private home, which is precisely
	 * what the managed-tree test does not match.
	 */
	public function testBindingOutsideTheManagedTreeIsStillRefused(): void {
		$this->userSession->method('getUser')->willReturn($this->makeUser('bob'));
		$this->mockUserFolderLookup(uid: 'bob', folderId: 242, nodes: []);
		$this->mockMountPath(folderId: 242, path: '/alice/files/Secret');

		$this->expectException(FolderAccessDeniedException::class);

		$this->handler->assertManagedFolderIsAccessible(
			folderId: '242',
			objectEntity: $this->makeObjectEntity('242')
		);

	}//end testBindingOutsideTheManagedTreeIsStillRefused()

	/**
	 * An ACCEPTED save writes no `folder_access_denied` audit row.
	 *
	 * `assertFolderIsAccessible()` audits before every throw, so the
	 * managed-tree question has to be asked first: asking it second would
	 * record a denial for each save this method then goes on to allow, and an
	 * audit trail that reports refusals which never happened is worse than
	 * none. Bob is not the creator and cannot read the folder through his own
	 * home, so this is exactly the case that would have left the false row.
	 */
	public function testAnAcceptedManagedBindingWritesNoDenialAudit(): void {
		$this->userSession->method('getUser')->willReturn($this->makeUser('bob'));
		$this->mockMountPath(folderId: 167, path: '/alice/files/Open Registers/Cases Register/case-1');

		$this->auditTrailMapper->expects($this->never())->method('insert');

		$this->handler->assertManagedFolderIsAccessible(
			folderId: '167',
			objectEntity: $this->makeObjectEntity('167')
		);

		$this->addToAssertionCount(1);

	}//end testAnAcceptedManagedBindingWritesNoDenialAudit()

	/**
	 * A folder the acting user can read is accepted with no session lookup.
	 *
	 * The creator's own path still works when the mount cache says nothing —
	 * the acting-user check remains the fallback, not a removed step.
	 */
	public function testCreatorStillPassesWhenTheMountCacheIsSilent(): void {
		$this->userSession->method('getUser')->willReturn($this->makeUser('alice'));
		$this->mountCache->method('getMountsForFileId')->willReturn([]);

		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$this->mockUserFolderLookup(uid: 'alice', folderId: 167, nodes: [$folder]);

		$this->handler->assertManagedFolderIsAccessible(
			folderId: '167',
			objectEntity: $this->makeObjectEntity('167')
		);

		$this->addToAssertionCount(1);

	}//end testCreatorStillPassesWhenTheMountCacheIsSilent()
}//end class
