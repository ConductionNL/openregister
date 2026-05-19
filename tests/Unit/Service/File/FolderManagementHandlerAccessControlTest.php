<?php

/**
 * FolderManagementHandlerAccessControlTest
 *
 * Unit tests covering the @self.folder access-control feature added by the
 * validate-self-folder-access change. Every requirement from the spec
 * (spec.md) and the acceptance criteria in tasks.md#task-7 has a corresponding
 * test here.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Access-control unit tests for FolderManagementHandler.
 *
 * Tests the assertFolderIsAccessible() path introduced by the
 * validate-self-folder-access spec. Each test corresponds to a
 * numbered acceptance criterion in tasks.md#task-7.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
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

    /**
     * @var IUser&MockObject
     */
    private IUser $aliceUser;

    /**
     * @var Folder&MockObject
     */
    private Folder $aliceUserFolder;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->rootFolder         = $this->createMock(IRootFolder::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

        $this->aliceUser = $this->createMock(IUser::class);
        $this->aliceUser->method('getUID')->willReturn('alice');

        $this->aliceUserFolder = $this->createMock(Folder::class);

        // Default: session user is alice.
        $this->userSession->method('getUser')->willReturn($this->aliceUser);

        // Default: root folder resolves alice's user folder.
        $this->rootFolder->method('getUserFolder')
            ->with('alice')
            ->willReturn($this->aliceUserFolder);
    }//end setUp()

    /**
     * Build the handler under test, optionally overriding the auditTrailMapper.
     *
     * @param AuditTrailMapper|null $auditTrailMapper Optional audit trail mapper.
     *
     * @return FolderManagementHandler
     */
    private function buildHandler(?AuditTrailMapper $auditTrailMapper=null): FolderManagementHandler
    {
        return new FolderManagementHandler(
            rootFolder: $this->rootFolder,
            objectEntityMapper: $this->objectEntityMapper,
            registerMapper: $this->registerMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
            fileService: null,
            auditTrailMapper: $auditTrailMapper ?? $this->auditTrailMapper
        );
    }//end buildHandler()

    /**
     * Build an ObjectEntity with a numeric folder property.
     *
     * @param string $folderId The folder node ID string.
     *
     * @return ObjectEntity
     */
    private function buildObjectEntityWithFolder(string $folderId): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setFolder($folderId);
        $entity->setUuid('test-uuid-1234');

        return $entity;
    }//end buildObjectEntityWithFolder()

    // =========================================================================
    // Task 7.1 — Owned / readable folder bind succeeds
    // =========================================================================

    /**
     * Binding to a folder the user owns (readable) must succeed.
     * The returned folder is the resolved Folder node — no auto-create path.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testOwnedFolderBindSucceeds(): void
    {
        $ownedFolder = $this->createMock(Folder::class);
        $ownedFolder->method('isReadable')->willReturn(true);
        $ownedFolder->method('getId')->willReturn(42);

        $this->aliceUserFolder->method('getById')
            ->with(42)
            ->willReturn([$ownedFolder]);

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('42');

        // objectEntityMapper->update() is called to persist the folder ID after create.
        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $result = $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );

        $this->assertSame($ownedFolder, $result);
    }//end testOwnedFolderBindSucceeds()

    /**
     * Binding to a shared-readable folder (shared with alice from bob) succeeds.
     * "Shared-readable" means the folder appears in alice's user mount and isReadable().
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testSharedReadableFolderBindSucceeds(): void
    {
        $sharedFolder = $this->createMock(Folder::class);
        $sharedFolder->method('isReadable')->willReturn(true);
        $sharedFolder->method('getId')->willReturn(99);

        $this->aliceUserFolder->method('getById')
            ->with(99)
            ->willReturn([$sharedFolder]);

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('99');

        $result = $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );

        $this->assertSame($sharedFolder, $result);
    }//end testSharedReadableFolderBindSucceeds()

    // =========================================================================
    // Task 7.1 — Cross-user folder bind throws FolderAccessDeniedException
    // =========================================================================

    /**
     * Binding to a cross-user folder that is NOT in alice's mount MUST throw.
     * The audit trail entry MUST be written before throwing.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testCrossUserFolderBindThrowsFolderAccessDeniedException(): void
    {
        // Bob's folder does not appear in alice's user mount.
        $this->aliceUserFolder->method('getById')
            ->with(100)
            ->willReturn([]);

        // The audit trail entry MUST be written before the exception is thrown.
        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('100');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );
    }//end testCrossUserFolderBindThrowsFolderAccessDeniedException()

    // =========================================================================
    // Task 7.1 — Non-existent numeric ID throws
    // =========================================================================

    /**
     * A numeric folder ID that doesn't exist in anyone's mount must throw.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testNonExistentNumericIdThrows(): void
    {
        $this->aliceUserFolder->method('getById')
            ->with(999999)
            ->willReturn([]);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('999999');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );
    }//end testNonExistentNumericIdThrows()

    // =========================================================================
    // Task 7.1 — File node (not a folder) throws
    // =========================================================================

    /**
     * If the node ID resolves to a File (not a Folder), throw.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testFileNodeInsteadOfFolderThrows(): void
    {
        $fileNode = $this->createMock(File::class);

        $this->aliceUserFolder->method('getById')
            ->with(51)
            ->willReturn([$fileNode]);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('51');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );
    }//end testFileNodeInsteadOfFolderThrows()

    // =========================================================================
    // Task 7.1 — Trashed / unreadable folder throws
    // =========================================================================

    /**
     * A folder that is in the user's mount but isReadable() = false (e.g. trashed) must throw.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testUnaccessibleFolderThrows(): void
    {
        $trashedFolder = $this->createMock(Folder::class);
        $trashedFolder->method('isReadable')->willReturn(false);

        $this->aliceUserFolder->method('getById')
            ->with(77)
            ->willReturn([$trashedFolder]);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('77');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );
    }//end testUnaccessibleFolderThrows()

    // =========================================================================
    // Task 7.1 — Empty folder property → auto-create (no exception)
    // =========================================================================

    /**
     * An ObjectEntity with an empty folder property goes through the existing
     * auto-create path without any access check or exception.
     * The test verifies the access-control branch is NOT entered (audit mapper
     * must NOT be called) and FolderAccessDeniedException is NOT thrown.
     * Other exceptions from the auto-create path are expected in unit tests
     * without a full folder mock.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testEmptyFolderPropertyGoesToAutoCreate(): void
    {
        // AuditTrailMapper must NOT be called — the access-control branch is bypassed.
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $handler = $this->buildHandler();

        $entity = new ObjectEntity();
        $entity->setFolder('');
        $entity->setUuid('auto-uuid');
        $entity->setRegister(1);

        // Register the entity so the mapper-based path can find it.
        $this->registerMapper->method('find')->willThrowException(new \Exception('No register'));

        // If FolderAccessDeniedException is thrown, the test fails.
        // Other exceptions (from auto-create path) are acceptable in unit tests.
        try {
            $handler->createObjectFolderById(
                objectEntity: $entity,
                currentUser: $this->aliceUser
            );
            $this->assertTrue(true);
        } catch (FolderAccessDeniedException $e) {
            $this->fail('FolderAccessDeniedException must NOT be thrown for empty folder property.');
        } catch (\Exception $e) {
            // Auto-create path throws because register/folder mocks are minimal.
            // The important assertion is that the audit mapper was never called.
            $this->assertNotInstanceOf(FolderAccessDeniedException::class, $e);
        }
    }//end testEmptyFolderPropertyGoesToAutoCreate()

    // =========================================================================
    // Task 7.1 — Legacy non-numeric folder string → auto-create (no exception)
    // =========================================================================

    /**
     * An entity with a non-numeric legacy folder string uses the auto-create path.
     * No access check runs, no audit entry is written, and FolderAccessDeniedException
     * is NOT thrown.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testLegacyNonNumericFolderGoesToAutoCreate(): void
    {
        // AuditTrailMapper must NOT be called — the access-control branch is bypassed.
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $handler = $this->buildHandler();

        $entity = new ObjectEntity();
        $entity->setFolder('legacy/path/string');
        $entity->setUuid('legacy-uuid');
        $entity->setRegister(2);

        $this->registerMapper->method('find')->willThrowException(new \Exception('No register'));

        // If FolderAccessDeniedException is thrown, the test fails.
        try {
            $handler->createObjectFolderById(
                objectEntity: $entity,
                currentUser: $this->aliceUser
            );
            $this->assertTrue(true);
        } catch (FolderAccessDeniedException $e) {
            $this->fail('FolderAccessDeniedException must NOT be thrown for non-numeric folder property.');
        } catch (\Exception $e) {
            // Other exceptions from the auto-create path are expected in unit tests.
            $this->assertNotInstanceOf(FolderAccessDeniedException::class, $e);
        }
    }//end testLegacyNonNumericFolderGoesToAutoCreate()

    // =========================================================================
    // Task 7.1 — Explicit $currentUser argument overrides session user
    // =========================================================================

    /**
     * When an explicit IUser is passed as $currentUser, that user's mount is
     * checked — not the session user's.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testExplicitCurrentUserOverridesSessionUser(): void
    {
        $bobUser = $this->createMock(IUser::class);
        $bobUser->method('getUID')->willReturn('bob');

        $bobFolder = $this->createMock(Folder::class);
        $bobFolder->method('isReadable')->willReturn(true);
        $bobFolder->method('getId')->willReturn(42);

        $bobUserFolder = $this->createMock(Folder::class);
        $bobUserFolder->method('getById')
            ->with(42)
            ->willReturn([$bobFolder]);

        // Build a fresh rootFolder that supports both alice and bob lookups.
        $freshRootFolder = $this->createMock(IRootFolder::class);
        $freshRootFolder->method('getUserFolder')
            ->willReturnCallback(
                static function (string $uid) use ($bobUserFolder): Folder {
                    if ($uid === 'bob') {
                        return $bobUserFolder;
                    }

                    throw new \Exception('Unexpected user: '.$uid);
                }
            );

        $handler = new FolderManagementHandler(
            rootFolder: $freshRootFolder,
            objectEntityMapper: $this->objectEntityMapper,
            registerMapper: $this->registerMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
            fileService: null,
            auditTrailMapper: $this->auditTrailMapper
        );

        $entity = $this->buildObjectEntityWithFolder('42');

        // Pass bob as the explicit currentUser — alice is session user.
        $result = $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $bobUser
        );

        $this->assertSame($bobFolder, $result);
    }//end testExplicitCurrentUserOverridesSessionUser()

    // =========================================================================
    // Task 7.1 — No user in context denies the bind
    // =========================================================================

    /**
     * If no IUser is passed and the session user is null, the bind must be denied.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testNullUserDeniesNumericFolderBind(): void
    {
        // Override session to return null.
        $noSessionUserSession = $this->createMock(IUserSession::class);
        $noSessionUserSession->method('getUser')->willReturn(null);

        $handler = new FolderManagementHandler(
            rootFolder: $this->rootFolder,
            objectEntityMapper: $this->objectEntityMapper,
            registerMapper: $this->registerMapper,
            userSession: $noSessionUserSession,
            groupManager: $this->groupManager,
            logger: $this->logger,
            fileService: null,
            auditTrailMapper: $this->auditTrailMapper
        );

        $entity = $this->buildObjectEntityWithFolder('42');

        // Audit entry must still be written (best-effort).
        $this->auditTrailMapper->expects($this->once())->method('insert');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: null
        // No explicit user either.
        );
    }//end testNullUserDeniesNumericFolderBind()

    // =========================================================================
    // Task 7.1 — Audit-trail entry written on denial
    // =========================================================================

    /**
     * Every denial must write exactly one audit-trail entry BEFORE throwing.
     * Covered by cross-user test above; this test adds an explicit assertion
     * on insert() call count for readability.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testAuditTrailEntryWrittenOnDenial(): void
    {
        $this->aliceUserFolder->method('getById')
            ->with(200)
            ->willReturn([]);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->isInstanceOf(AuditTrail::class));

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('200');

        try {
            $handler->createObjectFolderById(
                objectEntity: $entity,
                currentUser: $this->aliceUser
            );
        } catch (FolderAccessDeniedException $e) {
            // Expected — the important assertion is on insert() above.
        }
    }//end testAuditTrailEntryWrittenOnDenial()

    // =========================================================================
    // Task 7.2 — Audit-write failure does not swallow denial
    // =========================================================================

    /**
     * Even when the AuditTrailMapper throws, the FolderAccessDeniedException
     * must still propagate.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testAuditWriteFailureDoesNotSwallowDenial(): void
    {
        $this->aliceUserFolder->method('getById')
            ->with(300)
            ->willReturn([]);

        // Audit mapper throws — but FolderAccessDeniedException must still be raised.
        $this->auditTrailMapper->method('insert')
            ->willThrowException(new \RuntimeException('DB error'));

        // Logger must receive a warning when audit write fails.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('300');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );
    }//end testAuditWriteFailureDoesNotSwallowDenial()

    // =========================================================================
    // Task 7.2 — FolderAccessDeniedException extends \Exception only
    // =========================================================================

    /**
     * FolderAccessDeniedException must extend \Exception directly and must NOT
     * extend any Nextcloud exception type such as NotPermittedException.
     * This ensures catch-blocks for Nextcloud primitives don't accidentally
     * absorb folder-access denials.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testExceptionClassHierarchy(): void
    {
        $exc = new FolderAccessDeniedException(folderId: '42');

        $this->assertInstanceOf(\Exception::class, $exc);

        // Must NOT be a subclass of any Nextcloud file exception.
        $this->assertNotInstanceOf(\OCP\Files\NotPermittedException::class, $exc);
        $this->assertNotInstanceOf(\OCP\Files\NotFoundException::class, $exc);
    }//end testExceptionClassHierarchy()

    /**
     * FolderAccessDeniedException must expose getFolderId() to allow controllers
     * to build the structured 403 response body.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testExceptionExposesGetFolderId(): void
    {
        $exc = new FolderAccessDeniedException(folderId: '77');

        $this->assertSame('77', $exc->getFolderId());
    }//end testExceptionExposesGetFolderId()

    // =========================================================================
    // Task 7.2 — Default-deny invariant: unknown failure mode → deny
    // =========================================================================

    /**
     * If getUserFolder() throws an unexpected exception, the bind must still be
     * denied (FolderAccessDeniedException) — there is no fail-open fallback.
     *
     * @spec openspec/changes/validate-self-folder-access/tasks.md#task-7
     */
    #[Test]
    public function testUnexpectedLookupExceptionCausesDefaultDeny(): void
    {
        $this->rootFolder->method('getUserFolder')
            ->with('alice')
            ->willThrowException(new \RuntimeException('Unexpected storage error'));

        $this->auditTrailMapper->expects($this->once())->method('insert');

        $handler = $this->buildHandler();
        $entity  = $this->buildObjectEntityWithFolder('42');

        $this->expectException(FolderAccessDeniedException::class);

        $handler->createObjectFolderById(
            objectEntity: $entity,
            currentUser: $this->aliceUser
        );
    }//end testUnexpectedLookupExceptionCausesDefaultDeny()
}//end class
