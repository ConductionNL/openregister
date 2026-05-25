<?php

/**
 * Unit tests for ShareLinkService (Tier-2 shares — OCP\Share\IManager,
 * NO link table / NO cache).
 *
 * Covers:
 *   - getLinkedShares() happy-path: walks the object's folder, calls
 *     `IManager::getSharesBy()` per child × per share type, normalises
 *     rows and dedupes by share id
 *   - getLinkedShares() no-folder: returns []
 *   - createShare() happy-path: builds + persists via IManager::newShare()
 *     / createShare(), file must live in the object's folder
 *   - createShare() file-not-in-folder: 404
 *   - createShare() missing recipient for user share: 400
 *   - revokeShare() happy-path: IManager::deleteShare()
 *   - revokeShare() ownership 403 when the share's node is outside the
 *     object's folder
 *   - getShareableFiles() lists only file nodes
 *
 * NC's `OCP\Share\IManager`, `OCP\Share\IShare`, and the OR
 * `FolderManagementHandler` are pulled lazily through a `ContainerInterface`
 * the test injects, so the service's runtime path is exercisable without
 * the full NC server container.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-shares/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use Exception;
use OCA\OpenRegister\Service\ShareLinkService;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ShareLinkService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ShareLinkServiceTest extends TestCase
{
    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()

    private function buildLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }//end buildLogger()

    private function buildUserSession(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;
    }//end buildUserSession()

    private function buildNode(int $id, string $name, string $type=FileInfo::TYPE_FILE): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        $node->method('getName')->willReturn($name);
        $node->method('getType')->willReturn($type);
        $node->method('getMimetype')->willReturn('text/plain');
        $node->method('getSize')->willReturn(123);
        return $node;
    }//end buildNode()

    private function buildFolder(array $children): Folder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($children);
        return $folder;
    }//end buildFolder()

    private function buildShare(
        string $id,
        int $type,
        string $with,
        int $perms,
        string $owner,
        Node $node
    ): IShare {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn($id);
        $share->method('getShareType')->willReturn($type);
        $share->method('getSharedWith')->willReturn($with);
        $share->method('getSharedWithDisplayName')->willReturn($with);
        $share->method('getPermissions')->willReturn($perms);
        $share->method('getSharedBy')->willReturn($owner);
        $share->method('getNode')->willReturn($node);
        $share->method('getPassword')->willReturn(null);
        $share->method('getExpirationDate')->willReturn(null);
        $share->method('getShareTime')->willReturn(null);
        $share->method('getToken')->willReturn(null);
        return $share;
    }//end buildShare()

    private function buildFolderHandler(?Folder $folder): object
    {
        return new class($folder) {
            public function __construct(private ?Folder $folder)
            {
            }//end __construct()

            public function getObjectFolder(mixed $objectEntityOrId): ?Folder
            {
                return $this->folder;
            }//end getObjectFolder()
        };
    }//end buildFolderHandler()

    private function buildObjectMapper(): object
    {
        return new class {
            public function find(string $uuid): object
            {
                return new \stdClass();
            }//end find()
        };
    }//end buildObjectMapper()

    /**
     * Build a container that resolves the named services from a map. A
     * missing key throws a NotFoundExceptionInterface so the service's
     * lazy lookups behave like the real NC container.
     *
     * @param array<string,object> $services Service-id → instance.
     *
     * @return ContainerInterface
     */
    private function buildContainer(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            public function __construct(private array $services)
            {
            }//end __construct()

            public function get(string $id): object
            {
                if (isset($this->services[$id]) === true) {
                    return $this->services[$id];
                }

                throw new class extends RuntimeException implements NotFoundExceptionInterface {
                };
            }//end get()

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }//end has()
        };
    }//end buildContainer()

    public function testGetLinkedSharesHappyPath(): void
    {
        $node   = $this->buildNode(10, 'doc.txt');
        $folder = $this->buildFolder([$node]);
        $share  = $this->buildShare('s1', IShare::TYPE_USER, 'bob', 1, 'alice', $node);

        $manager = $this->createMock(IManager::class);
        $manager->method('getSharesBy')->willReturnCallback(
            function ($uid, $type, $n) use ($share) {
                return $type === IShare::TYPE_USER ? [$share] : [];
            }
        );

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $manager,
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);
        $rows    = $service->getLinkedShares('uuid-1');

        $this->assertCount(1, $rows);
        $this->assertSame('s1', $rows[0]['shareId']);
        $this->assertSame(IShare::TYPE_USER, $rows[0]['shareType']);
        $this->assertSame('bob', $rows[0]['shareWith']);
        $this->assertTrue($rows[0]['canRevoke']);
        $this->assertSame(10, $rows[0]['fileId']);
    }//end testGetLinkedSharesHappyPath()

    public function testGetLinkedSharesNoFolderReturnsEmpty(): void
    {
        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $this->createMock(IManager::class),
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler(null),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);
        $this->assertSame([], $service->getLinkedShares('uuid-1'));
    }//end testGetLinkedSharesNoFolderReturnsEmpty()

    public function testCreateShareHappyPath(): void
    {
        $node   = $this->buildNode(10, 'doc.txt');
        $folder = $this->buildFolder([$node]);

        $newShare = $this->createMock(IShare::class);
        $newShare->method('getId')->willReturn('s99');
        $newShare->method('getShareType')->willReturn(IShare::TYPE_USER);
        $newShare->method('getToken')->willReturn(null);

        $manager = $this->createMock(IManager::class);
        $manager->method('newShare')->willReturn($newShare);
        $manager->expects($this->once())->method('createShare')->with($newShare)->willReturn($newShare);

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $manager,
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);
        $result  = $service->createShare('uuid-1', 1, 1, 10, IShare::TYPE_USER, 'bob', 1, null, null);

        $this->assertSame('s99', $result->getId());
    }//end testCreateShareHappyPath()

    public function testCreateShareFileNotInFolderThrows404(): void
    {
        $node   = $this->buildNode(10, 'doc.txt');
        $folder = $this->buildFolder([$node]);

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $this->createMock(IManager::class),
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);
        $service->createShare('uuid-1', 1, 1, 999, IShare::TYPE_USER, 'bob', 1, null, null);
    }//end testCreateShareFileNotInFolderThrows404()

    public function testCreateShareMissingRecipientThrows400(): void
    {
        $node   = $this->buildNode(10, 'doc.txt');
        $folder = $this->buildFolder([$node]);

        $manager = $this->createMock(IManager::class);
        $manager->method('newShare')->willReturn($this->createMock(IShare::class));

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $manager,
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);
        $service->createShare('uuid-1', 1, 1, 10, IShare::TYPE_USER, null, 1, null, null);
    }//end testCreateShareMissingRecipientThrows400()

    public function testRevokeShareHappyPath(): void
    {
        $node   = $this->buildNode(10, 'doc.txt');
        $folder = $this->buildFolder([$node]);
        $share  = $this->buildShare('s1', IShare::TYPE_USER, 'bob', 1, 'alice', $node);

        $manager = $this->createMock(IManager::class);
        $manager->method('getShareById')->with('s1')->willReturn($share);
        $manager->expects($this->once())->method('deleteShare')->with($share);

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $manager,
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);
        $service->revokeShare('uuid-1', 's1');
        // No exception == pass; deleteShare expectation asserts the call.
        $this->addToAssertionCount(1);
    }//end testRevokeShareHappyPath()

    public function testRevokeShareOutsideFolderThrows403(): void
    {
        $folderNode = $this->buildNode(10, 'doc.txt');
        $folder     = $this->buildFolder([$folderNode]);
        // Share backed by a node id (999) NOT present in the folder.
        $alienNode = $this->buildNode(999, 'other.txt');
        $share     = $this->buildShare('s7', IShare::TYPE_USER, 'bob', 1, 'alice', $alienNode);

        $manager = $this->createMock(IManager::class);
        $manager->method('getShareById')->with('s7')->willReturn($share);
        $manager->expects($this->never())->method('deleteShare');

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $manager,
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(403);
        $service->revokeShare('uuid-1', 's7');
    }//end testRevokeShareOutsideFolderThrows403()

    public function testGetShareableFilesListsOnlyFiles(): void
    {
        $file   = $this->buildNode(10, 'doc.txt', FileInfo::TYPE_FILE);
        $subdir = $this->buildNode(11, 'subdir', FileInfo::TYPE_FOLDER);
        $folder = $this->buildFolder([$file, $subdir]);

        $container = $this->buildContainer(
            [
                'OCP\\Share\\IManager'                                      => $this->createMock(IManager::class),
                'OCP\\IUserSession'                                         => $this->buildUserSession('alice'),
                'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $this->buildFolderHandler($folder),
                'OCA\\OpenRegister\\Db\\ObjectEntityMapper'                 => $this->buildObjectMapper(),
            ]
        );

        $service = new ShareLinkService($this->buildL10n(), $this->buildLogger(), $container);
        $files   = $service->getShareableFiles('uuid-1');

        $this->assertCount(1, $files);
        $this->assertSame(10, $files[0]['fileId']);
        $this->assertSame('doc.txt', $files[0]['fileName']);
    }//end testGetShareableFilesListsOnlyFiles()
}//end class
