<?php

declare(strict_types=1);

/**
 * Access-control tests for `FolderManagementHandler::createObjectFolderById()`.
 *
 * Covers the `self-folder-access-control` capability: the default-deny
 * invariant, the "self" definition (explicit IUser → session user → deny),
 * the schemas-extension scenario set, audit-trail emission on denial, and
 * the preserved auto-create path for empty / legacy non-numeric folder values.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @spec openspec/changes/validate-self-folder-access/specs/self-folder-access-control/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for `@self.folder` access-control on `createObjectFolderById()`.
 */
class FolderManagementHandlerAccessControlTest extends TestCase
{

    /**
     * @var IRootFolder&MockObject
     */
    private IRootFolder $rootFolder;

    /**
     * @var MagicMapper&MockObject
     */
    private MagicMapper $objectEntityMapper;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper $auditTrailMapper;

    private FolderManagementHandler $handler;


    protected function setUp(): void
    {
        parent::setUp();

        $this->rootFolder         = $this->createMock(IRootFolder::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->auditTrailMapper   = $this->createMock(AuditTrailMapper::class);

        $this->handler = new FolderManagementHandler(
            rootFolder: $this->rootFolder,
            objectEntityMapper: $this->objectEntityMapper,
            registerMapper: $this->registerMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
            auditTrailMapper: $this->auditTrailMapper
        );

    }//end setUp()


    /**
     * Build an ObjectEntity with the given folder property.
     *
     * @param string|null $folder Folder property to set (numeric ID, legacy path, null).
     *
     * @return ObjectEntity
     */
    private function makeObjectEntity(?string $folder): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('uuid-test-1');
        $entity->setRegister('1');
        $entity->setSchema('1');
        if ($folder !== null) {
            $entity->setFolder($folder);
        }

        return $entity;

    }//end makeObjectEntity()


    /**
     * Mock a user with the given UID and have the session return them.
     *
     * @param string $uid User identifier.
     *
     * @return IUser&MockObject
     */
    private function mockSessionUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

        return $user;

    }//end mockSessionUser()


    /**
     * Wire `$this->rootFolder->getUserFolder('alice')` to return a mock that
     * resolves `getById($folderId)` to the given list of nodes.
     *
     * @param string $uid       UID whose user folder is being mocked.
     * @param int    $folderId  ID being looked up.
     * @param array  $nodes     Nodes to return from `getById()`.
     *
     * @return void
     */
    private function mockUserFolderLookup(string $uid, int $folderId, array $nodes): void
    {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with($folderId)->willReturn($nodes);
        $this->rootFolder->method('getUserFolder')->with($uid)->willReturn($userFolder);

    }//end mockUserFolderLookup()


    /**
     * (a) Owned folder bind succeeds.
     */
    public function testOwnedFolderBindSucceeds(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $folder = $this->createMock(Folder::class);
        $folder->method('isReadable')->willReturn(true);
        $this->mockUserFolderLookup(uid: 'alice', folderId: 42, nodes: [$folder]);

        $entity = $this->makeObjectEntity(folder: '42');

        $result = $this->handler->createObjectFolderById(objectEntity: $entity);

        $this->assertSame($folder, $result);

    }//end testOwnedFolderBindSucceeds()


    /**
     * (b) Shared-readable folder bind succeeds.
     */
    public function testSharedReadableFolderBindSucceeds(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $shared = $this->createMock(Folder::class);
        $shared->method('isReadable')->willReturn(true);
        $this->mockUserFolderLookup(uid: 'alice', folderId: 50, nodes: [$shared]);

        $result = $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '50')
        );

        $this->assertSame($shared, $result);

    }//end testSharedReadableFolderBindSucceeds()


    /**
     * (c) Unshared cross-user folder bind throws and writes audit.
     */
    public function testCrossUserFolderBindThrows(): void
    {
        $this->mockSessionUser(uid: 'alice');

        // Bob's folder is not present in alice's user-folder lookup → empty result.
        $this->mockUserFolderLookup(uid: 'alice', folderId: 99, nodes: []);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->isInstanceOf(AuditTrail::class));

        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '99')
        );

    }//end testCrossUserFolderBindThrows()


    /**
     * (d) Non-existent numeric ID throws.
     */
    public function testNonExistentIdThrows(): void
    {
        $this->mockSessionUser(uid: 'alice');
        $this->mockUserFolderLookup(uid: 'alice', folderId: 999999, nodes: []);

        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '999999')
        );

    }//end testNonExistentIdThrows()


    /**
     * (e) File-ID (not folder) throws.
     */
    public function testFileIdInsteadOfFolderIdThrows(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $file = $this->createMock(File::class);
        $this->mockUserFolderLookup(uid: 'alice', folderId: 51, nodes: [$file]);

        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '51')
        );

    }//end testFileIdInsteadOfFolderIdThrows()


    /**
     * (f) Trashed (non-readable) folder throws.
     */
    public function testTrashedFolderThrows(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $trashed = $this->createMock(Folder::class);
        $trashed->method('isReadable')->willReturn(false);
        $this->mockUserFolderLookup(uid: 'alice', folderId: 77, nodes: [$trashed]);

        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '77')
        );

    }//end testTrashedFolderThrows()


    /**
     * (i) Explicit `$currentUser` overrides the session user.
     *
     * `bob` is the explicit acting user; the session is `alice`. The check
     * MUST go through `bob`'s user-folder lookup, not `alice`'s.
     */
    public function testExplicitCurrentUserOverridesSession(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');

        $folder = $this->createMock(Folder::class);
        $folder->method('isReadable')->willReturn(true);
        $this->mockUserFolderLookup(uid: 'bob', folderId: 42, nodes: [$folder]);

        $result = $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '42'),
            currentUser: $bob
        );

        $this->assertSame($folder, $result);

    }//end testExplicitCurrentUserOverridesSession()


    /**
     * Default-deny invariant — an unexpected exception during user-folder lookup
     * must NOT fail-open. A generic RuntimeException from `getUserFolder()`
     * (e.g. mount issue, DB hiccup) is translated into `FolderAccessDeniedException`.
     */
    public function testLookupExceptionDeniesByDefault(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $this->rootFolder->method('getUserFolder')
            ->with('alice')
            ->willThrowException(new \RuntimeException('mount unavailable'));

        $this->auditTrailMapper->expects($this->once())->method('insert');
        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '42')
        );

    }//end testLookupExceptionDeniesByDefault()


    /**
     * No-IUser context (no session user, no explicit arg) is denied per default-deny.
     */
    public function testNoActingUserDenies(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->auditTrailMapper->expects($this->once())->method('insert');
        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '42')
        );

    }//end testNoActingUserDenies()


    /**
     * (g) Empty folder property → access check is skipped (no audit, no denial).
     *
     * The auto-create branch downstream may still throw something else due to
     * unmocked register lookup, but the access-control check itself MUST NOT
     * fire — verified by asserting `auditTrailMapper::insert` is never called.
     */
    public function testEmptyFolderSkipsAccessCheck(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $this->auditTrailMapper->expects($this->never())->method('insert');

        $entity = $this->makeObjectEntity(folder: null);

        try {
            $this->handler->createObjectFolderById(objectEntity: $entity);
        } catch (FolderAccessDeniedException $e) {
            $this->fail('Empty folder property must not trigger FolderAccessDeniedException');
        } catch (\Throwable $e) {
            // Auto-create path will fail downstream (unmocked register lookup); that's fine.
            // The point of this test is the access-control check did NOT run.
        }

        // Reach this assertion only if no FolderAccessDeniedException was thrown.
        $this->addToAssertionCount(1);

    }//end testEmptyFolderSkipsAccessCheck()


    /**
     * (h) Legacy non-numeric folder property → access check is skipped.
     */
    public function testLegacyNonNumericFolderSkipsAccessCheck(): void
    {
        $this->mockSessionUser(uid: 'alice');

        $this->auditTrailMapper->expects($this->never())->method('insert');

        $entity = $this->makeObjectEntity(folder: 'legacy/path/string');

        try {
            $this->handler->createObjectFolderById(objectEntity: $entity);
        } catch (FolderAccessDeniedException $e) {
            $this->fail('Legacy non-numeric folder must not trigger FolderAccessDeniedException');
        } catch (\Throwable $e) {
            // Auto-create downstream may fail; that's outside this test's scope.
        }

        $this->addToAssertionCount(1);

    }//end testLegacyNonNumericFolderSkipsAccessCheck()


    /**
     * (j) Audit-trail entry is written on denial — and a mapper failure does
     * NOT swallow the denial.
     */
    public function testAuditFailureDoesNotSwallowDenial(): void
    {
        $this->mockSessionUser(uid: 'alice');
        $this->mockUserFolderLookup(uid: 'alice', folderId: 99, nodes: []);

        // Mapper insert blows up — must not stop the denial from propagating.
        $this->auditTrailMapper->method('insert')
            ->willThrowException(new Exception('audit table down'));
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->expectException(FolderAccessDeniedException::class);

        $this->handler->createObjectFolderById(
            objectEntity: $this->makeObjectEntity(folder: '99')
        );

    }//end testAuditFailureDoesNotSwallowDenial()


}//end class
