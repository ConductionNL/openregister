<?php

/**
 * Unit tests for SharesProvider (post-pivot, OCP\Share\IManager).
 *
 * Covers the contract surfaces required by the
 * `openspec/changes/integration-shares/tasks.md` change:
 *
 *  - metadata matches the leaf spec (id / label / icon / group /
 *    requiredApp / storage strategy)
 *  - `isEnabled()` returns `true` unconditionally (NC core)
 *  - `list()` happy-path: walks the object's folder, calls
 *    `IManager::getSharesBy()` per child × per share type, normalises
 *    rows and dedupes by share id
 *  - `list()` no-folder: when the FolderManagementHandler resolves to
 *    null, returns `[]` cleanly
 *  - `list()` unreachable-IManager: container can't resolve the share
 *    manager, returns `[]` and `health()` reports degraded
 *  - `health()` reports `'ok'` when the share manager resolves
 *  - `delete()` delegates to `IManager::deleteShare()` and falls back
 *    to NotImplementedException when the share manager is unreachable
 *
 * NC's `OCP\Share\IManager`, `OCP\Share\IShare`, and the OR
 * `FolderManagementHandler` are pulled lazily through a `ContainerInterface`
 * the test injects, so the provider's runtime path is exercisable
 * without spinning up the full NC server container.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
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

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Unit tests for SharesProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SharesProviderTest extends TestCase
{
    /**
     * Build an IL10N mock that passes strings through.
     *
     * @return IL10N
     */
    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()


    /**
     * Build an IAppManager mock (unused at runtime by the post-pivot
     * provider but still required by the ctor signature).
     *
     * @return IAppManager
     */
    private function buildAppManager(): IAppManager
    {
        return $this->createMock(IAppManager::class);
    }//end buildAppManager()


    /**
     * Build an IUserSession that returns a user with the given UID.
     *
     * @param string|null $uid Uid to return (null = no user).
     *
     * @return IUserSession
     */
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


    /**
     * Build a Node mock with the given id + name.
     *
     * @param int    $id   Node id.
     * @param string $name Node basename.
     *
     * @return Node
     */
    private function buildNode(int $id, string $name): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        $node->method('getName')->willReturn($name);
        return $node;
    }//end buildNode()


    /**
     * Build a Folder mock containing the given child nodes.
     *
     * @param array<int,Node> $children Children to return.
     *
     * @return Folder
     */
    private function buildFolder(array $children): Folder
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($children);
        return $folder;
    }//end buildFolder()


    /**
     * Build an IShare mock with the given fields.
     *
     * @param string $id           Share id.
     * @param int    $type         IShare::TYPE_*.
     * @param string $with         shareWith.
     * @param string $displayName  shareWithDisplayName.
     * @param int    $perms        Permission bitmask.
     * @param string $owner        Share owner uid.
     * @param Node   $node         Backing node.
     * @param bool   $hasPassword  Whether the share is password-protected.
     *
     * @return IShare
     */
    private function buildShare(
        string $id,
        int $type,
        string $with,
        string $displayName,
        int $perms,
        string $owner,
        Node $node,
        bool $hasPassword=false
    ): IShare {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn($id);
        $share->method('getShareType')->willReturn($type);
        $share->method('getSharedWith')->willReturn($with);
        $share->method('getSharedWithDisplayName')->willReturn($displayName);
        $share->method('getPermissions')->willReturn($perms);
        $share->method('getSharedBy')->willReturn($owner);
        $share->method('getNode')->willReturn($node);
        $share->method('getPassword')->willReturn($hasPassword === true ? 'secret' : null);
        $share->method('getExpirationDate')->willReturn(null);
        $share->method('getShareTime')->willReturn(null);
        $share->method('getToken')->willReturn(null);
        return $share;
    }//end buildShare()


    /**
     * Build a mock FolderManagementHandler returning the given folder.
     *
     * @param Folder|null $folder Folder to return from getObjectFolder.
     *
     * @return object
     */
    private function buildFolderHandler(?Folder $folder): object
    {
        return new class($folder) {

            public function __construct(private ?Folder $folder)
            {
            }//end __construct()


            public function getObjectFolder(string $objectId): ?Folder
            {
                return $this->folder;
            }//end getObjectFolder()


        };
    }//end buildFolderHandler()


    /**
     * Build a container that yields the given service-id → instance
     * mapping; unknown ids throw a NotFoundExceptionInterface.
     *
     * @param array<string,object> $services Service map.
     *
     * @return ContainerInterface
     */
    private function buildContainer(array $services): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($services) {
                if (array_key_exists($id, $services) === true) {
                    return $services[$id];
                }

                throw new class extends RuntimeException implements NotFoundExceptionInterface {
                };
            }
        );
        return $container;
    }//end buildContainer()


    /**
     * Provider exposes the metadata declared in the leaf spec.
     *
     * @return void
     */
    public function testMetadataMatchesLeafSpec(): void
    {
        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('shares', $provider->getId());
        $this->assertSame('Shares', $provider->getLabel());
        $this->assertSame('Share', $provider->getIcon());
        $this->assertSame('core', $provider->getGroup());
        $this->assertNull($provider->getRequiredApp());
        $this->assertSame('query-time', $provider->getStorageStrategy());
        $this->assertNull($provider->getOpenConnectorSource());
        $this->assertNull($provider->requiresPermission());
        $this->assertTrue($provider->isEnabled());
    }//end testMetadataMatchesLeafSpec()


    /**
     * `list()` happy-path: walks the object folder, queries IManager
     * per child × per share type, normalises rows and dedupes.
     *
     * @return void
     */
    public function testListSurfacesSharesFromManager(): void
    {
        $node = $this->buildNode(42, 'case-letter.pdf');

        $userShare = $this->buildShare(
            id: 's-user',
            type: IShare::TYPE_USER,
            with: 'alice',
            displayName: 'Alice',
            perms: 19,
            owner: 'bob',
            node: $node
        );
        $linkShare = $this->buildShare(
            id: 's-link',
            type: IShare::TYPE_LINK,
            with: '',
            displayName: '',
            perms: 1,
            owner: 'bob',
            node: $node,
            hasPassword: true
        );

        $shareManager = $this->createMock(IManager::class);
        $shareManager->method('getSharesBy')->willReturnCallback(
            static function ($userId, int $type, $path) use ($userShare, $linkShare) {
                if ($type === IShare::TYPE_USER) {
                    return [$userShare];
                }

                if ($type === IShare::TYPE_LINK) {
                    return [$linkShare];
                }

                return [];
            }
        );

        $folder        = $this->buildFolder([$node]);
        $folderHandler = $this->buildFolderHandler($folder);
        $userSession   = $this->buildUserSession('bob');

        $container = $this->buildContainer([
            'OCP\\Share\\IManager'                                       => $shareManager,
            'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler'  => $folderHandler,
            'OCP\\IUserSession'                                          => $userSession,
        ]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $rows = $provider->list('reg', 'sch', 'obj-uuid');

        $this->assertCount(2, $rows, 'one user share + one link share expected');

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        $this->assertArrayHasKey('s-user', $byId);
        $this->assertSame(IShare::TYPE_USER, $byId['s-user']['shareType']);
        $this->assertSame('alice', $byId['s-user']['shareWith']);
        $this->assertSame('Alice', $byId['s-user']['shareWithDisplayname']);
        $this->assertSame(19, $byId['s-user']['permissions']);
        $this->assertTrue($byId['s-user']['canRevoke'], 'owner=bob, viewer=bob → can revoke');
        $this->assertFalse($byId['s-user']['passwordProtected']);

        $this->assertArrayHasKey('s-link', $byId);
        $this->assertSame(IShare::TYPE_LINK, $byId['s-link']['shareType']);
        $this->assertTrue($byId['s-link']['passwordProtected']);
        $this->assertStringContainsString('fileid=42', $byId['s-link']['url']);
    }//end testListSurfacesSharesFromManager()


    /**
     * `list()` returns `[]` cleanly when the object's folder can't be
     * resolved (handler returns null).
     *
     * @return void
     */
    public function testListReturnsEmptyWhenFolderMissing(): void
    {
        $shareManager = $this->createMock(IManager::class);
        // IManager MUST NOT be queried when the folder is unresolved.
        $shareManager->expects($this->never())->method('getSharesBy');

        $folderHandler = $this->buildFolderHandler(null);
        $userSession   = $this->buildUserSession('alice');

        $container = $this->buildContainer([
            'OCP\\Share\\IManager'                                      => $shareManager,
            'OCA\\OpenRegister\\Service\\File\\FolderManagementHandler' => $folderHandler,
            'OCP\\IUserSession'                                         => $userSession,
        ]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsEmptyWhenFolderMissing()


    /**
     * `list()` returns `[]` when the share subsystem is unreachable
     * (container can't resolve IManager).
     *
     * @return void
     */
    public function testListReturnsEmptyWhenShareManagerUnreachable(): void
    {
        // Container throws NotFoundExceptionInterface for every lookup.
        $container = $this->buildContainer([]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-uuid'));
    }//end testListReturnsEmptyWhenShareManagerUnreachable()


    /**
     * `health()` reports `'ok'` when the share manager resolves.
     *
     * @return void
     */
    public function testHealthReportsOkWhenShareManagerAvailable(): void
    {
        $container = $this->buildContainer([
            'OCP\\Share\\IManager' => $this->createMock(IManager::class),
        ]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $health = $provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenShareManagerAvailable()


    /**
     * `health()` reports `'degraded'` when the share manager can't be
     * resolved — and never throws.
     *
     * @return void
     */
    public function testHealthReportsDegradedWhenShareManagerUnreachable(): void
    {
        $container = $this->buildContainer([]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $health = $provider->health();
        $this->assertSame('degraded', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertSame('NC share manager is unreachable', $health['message']);
    }//end testHealthReportsDegradedWhenShareManagerUnreachable()


    /**
     * `delete()` delegates to `IManager::deleteShare()` happy path.
     *
     * @return void
     */
    public function testDeleteDelegatesToShareManager(): void
    {
        $share        = $this->createMock(IShare::class);
        $shareManager = $this->createMock(IManager::class);
        $shareManager->method('getShareById')->with('s-1')->willReturn($share);
        $shareManager->expects($this->once())->method('deleteShare')->with($share);

        $container = $this->buildContainer([
            'OCP\\Share\\IManager' => $shareManager,
        ]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $provider->delete('reg', 'sch', 'obj-uuid', 's-1');
    }//end testDeleteDelegatesToShareManager()


    /**
     * `delete()` falls back to the abstract base's
     * `NotImplementedException` when the share manager can't be
     * resolved — the controller layer maps that to the right HTTP code.
     *
     * @return void
     */
    public function testDeleteThrowsNotImplementedWhenShareManagerUnreachable(): void
    {
        $container = $this->buildContainer([]);

        $provider = new SharesProvider(
            db: $this->createMock(IDBConnection::class),
            appManager: $this->buildAppManager(),
            l10n: $this->buildL10n(),
            container: $container,
        );

        $this->expectException(NotImplementedException::class);
        $provider->delete('reg', 'sch', 'obj-uuid', 's-1');
    }//end testDeleteThrowsNotImplementedWhenShareManagerUnreachable()


}//end class
