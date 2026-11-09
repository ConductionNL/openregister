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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\ShareLinkService;
use OCA\OpenRegister\Service\ShareDraft;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
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
class ShareLinkServiceTest extends TestCase {

	private GrantChangeAuditor&MockObject $auditor;

	private function buildLogger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}//end buildLogger()

	private function buildUserSession(?string $uid): IUserSession {
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

	private function buildNode(int $id, string $name, string $type = FileInfo::TYPE_FILE): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn($name);
		$node->method('getType')->willReturn($type);
		$node->method('getMimetype')->willReturn('text/plain');
		$node->method('getSize')->willReturn(123);
		return $node;
	}//end buildNode()

	private function buildFolder(array $children): Folder {
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
		Node $node,
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

	private function buildFolderHandler(?Folder $folder): object {
		return new class($folder) {
			public function __construct(
				private ?Folder $folder,
			) {
			}//end __construct()

			public function getObjectFolder(mixed $objectEntityOrId): ?Folder {
				return $this->folder;
			}//end getObjectFolder()
		};
	}//end buildFolderHandler()

	private function buildObjectMapper(): object {
		return new class {
			public function find(string $uuid): object {
				return new \stdClass();
			}//end find()
		};
	}//end buildObjectMapper()

	/**
	 * A restriction guard that keeps the given file ids from the caller.
	 *
	 * @param array<int, int> $unreadable The restricted file ids.
	 *
	 * @return FileRestrictionGuard
	 */
	private function buildGuard(array $unreadable): FileRestrictionGuard {
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('readableNodes')->willReturnCallback(
			static fn (array $nodes): array => array_values(
				array_filter($nodes, static fn (Node $node): bool => in_array((int)$node->getId(), $unreadable, true) === false)
			)
		);
		return $guard;
	}//end buildGuard()

	/**
	 * Build a container that resolves the named services from a map. A
	 * missing key throws a NotFoundExceptionInterface so the service's
	 * lazy lookups behave like the real NC container.
	 *
	 * @param array<string,object> $services Service-id → instance.
	 *
	 * @return ContainerInterface
	 */
	private function buildContainer(array $services): ContainerInterface {
		$services += [FileRestrictionGuardInterface::class => $this->buildGuard(unreadable: [])];
		return new class($services) implements ContainerInterface {
			public function __construct(
				private array $services,
			) {
			}//end __construct()

			public function get(string $id): object {
				if (isset($this->services[$id]) === true) {
					return $this->services[$id];
				}

				throw new class extends RuntimeException implements NotFoundExceptionInterface {
				};
			}//end get()

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}//end has()
		};
	}//end buildContainer()

	public function testGetLinkedSharesHappyPath(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);
		$share = $this->buildShare('s1', IShare::TYPE_USER, 'bob', 1, 'alice', $node);

		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			function ($uid, $type, $n) use ($share) {
				return $type === IShare::TYPE_USER ? [$share] : [];
			}
		);

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);
		$rows = $service->getLinkedShares('uuid-1');

		$this->assertCount(1, $rows);
		$this->assertSame('s1', $rows[0]['shareId']);
		$this->assertSame(IShare::TYPE_USER, $rows[0]['shareType']);
		$this->assertSame('bob', $rows[0]['shareWith']);
		$this->assertTrue($rows[0]['canRevoke']);
		$this->assertSame(10, $rows[0]['fileId']);
	}//end testGetLinkedSharesHappyPath()

	public function testGetLinkedSharesNoFolderReturnsEmpty(): void {
		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $this->createMock(IManager::class),
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler(null),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);
		$this->assertSame([], $service->getLinkedShares('uuid-1'));
	}//end testGetLinkedSharesNoFolderReturnsEmpty()

	public function testCreateShareHappyPath(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);

		$newShare = $this->createMock(IShare::class);
		$newShare->method('getId')->willReturn('s99');
		$newShare->method('getShareType')->willReturn(IShare::TYPE_USER);
		$newShare->method('getToken')->willReturn(null);

		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturn($newShare);
		$manager->expects($this->once())->method('createShare')->with($newShare)->willReturn($newShare);
		$newShare->expects($this->once())->method('setSharedBy')->with('openregister');

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
			] + $this->managedShareServices(folder: $folder, mayUpdate: true)
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);
		$result = $service->createShare('uuid-1', (new ShareDraft(10, IShare::TYPE_USER))->withRecipient('bob', 1));

		$this->assertSame('s99', $result->getId());
	}//end testCreateShareHappyPath()

	/**
	 * The services a share of the app's account needs, with a real object.
	 *
	 * @param Folder $folder    The object's folder.
	 * @param bool   $mayUpdate What the update right answers.
	 *
	 * @return array<string, object>
	 */
	private function managedShareServices(Folder $folder, bool $mayUpdate): array {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('openregister');
		$files = new class($folder, $account) {
			public function __construct(private Folder $folder, private IUser $account) {
			}

			public function getObjectFolder(mixed $objectEntityOrId): ?Folder {
				return $this->folder;
			}

			public function getUser(): IUser {
				return $this->account;
			}
		};

		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setSchema('3');
		$mapper = new class($entity) {
			public function __construct(private ObjectEntity $entity) {
			}

			public function find(string $uuid): ObjectEntity {
				return $this->entity;
			}
		};

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action) use ($mayUpdate): bool {
				$this->assertSame('update', $action);
				return $mayUpdate;
			}
		);
		$this->auditor = $this->createMock(GrantChangeAuditor::class);

		return [
			'OCA\\OpenRegister\\Service\\FileService' => $files,
			'OCA\\OpenRegister\\Db\\MagicMapper' => $mapper,
			'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemas,
			'OCA\\OpenRegister\\Service\\Object\\PermissionHandlerInterface' => $permissions,
			'OCA\\OpenRegister\\Service\\Rbac\\GrantChangeAuditor' => $this->auditor,
		];
	}//end managedShareServices()

	public function testCreateShareNeedsTheObjectUpdateRight(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('createShare');

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
			] + $this->managedShareServices(folder: $this->buildFolder([$node]), mayUpdate: false)
		);

		$service = new ShareLinkService($this->buildLogger(), $container);

		$this->expectExceptionCode(403);
		$service->createShare('uuid-1', (new ShareDraft(10, IShare::TYPE_USER))->withRecipient('bob', 1));
	}//end testCreateShareNeedsTheObjectUpdateRight()

	public function testCreateShareIsOnTheAuditTrail(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$newShare = $this->createMock(IShare::class);
		$newShare->method('getId')->willReturn('s99');
		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturn($newShare);
		$manager->method('createShare')->willReturn($newShare);

		$services = $this->managedShareServices(folder: $this->buildFolder([$node]), mayUpdate: true);
		$this->auditor->expects($this->once())->method('recordGrant');
		$container = $this->buildContainer(
			['OCP\\Share\\IManager' => $manager, 'OCP\\IUserSession' => $this->buildUserSession('alice')] + $services
		);

		$service = new ShareLinkService($this->buildLogger(), $container);
		$service->createShare('uuid-1', (new ShareDraft(10, IShare::TYPE_USER))->withRecipient('bob', 1));
	}//end testCreateShareIsOnTheAuditTrail()

	/**
	 * Revoke a share the app's account made, as alice.
	 *
	 * @param bool $mayUpdate What the update right answers.
	 *
	 * @param int  $deletes   How many deletes to expect.
	 *
	 * @return void
	 */
	private function revokeManagedShare(bool $mayUpdate, int $deletes): void {
		$node = $this->buildNode(10, 'doc.txt');
		$share = $this->buildShare('s5', IShare::TYPE_USER, 'bob', 1, 'openregister', $node);
		$manager = $this->createMock(IManager::class);
		$manager->method('getShareById')->willReturn($share);
		$manager->expects($this->exactly($deletes))->method('deleteShare');

		$container = $this->buildContainer(
			['OCP\\Share\\IManager' => $manager, 'OCP\\IUserSession' => $this->buildUserSession('alice')]
			+ $this->managedShareServices(folder: $this->buildFolder([$node]), mayUpdate: $mayUpdate)
		);

		(new ShareLinkService($this->buildLogger(), $container))->revokeShare('uuid-1', 's5');
	}//end revokeManagedShare()

	public function testAShareOfTheAppAccountIsRevokedWithTheUpdateRight(): void {
		$this->revokeManagedShare(mayUpdate: true, deletes: 1);
	}//end testAShareOfTheAppAccountIsRevokedWithTheUpdateRight()

	public function testAShareOfTheAppAccountIsNotRevokedWithoutTheUpdateRight(): void {
		$this->expectExceptionCode(404);
		$this->revokeManagedShare(mayUpdate: false, deletes: 0);
	}//end testAShareOfTheAppAccountIsNotRevokedWithoutTheUpdateRight()

	public function testCreateShareFileNotInFolderThrows404(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $this->createMock(IManager::class),
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$service->createShare('uuid-1', (new ShareDraft(999, IShare::TYPE_USER))->withRecipient('bob', 1));
	}//end testCreateShareFileNotInFolderThrows404()

	public function testCreateShareMissingRecipientThrows400(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);

		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturn($this->createMock(IShare::class));

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$service->createShare('uuid-1', (new ShareDraft(10, IShare::TYPE_USER))->withRecipient(null, 1));
	}//end testCreateShareMissingRecipientThrows400()

	public function testRevokeShareHappyPath(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);
		$share = $this->buildShare('s1', IShare::TYPE_USER, 'bob', 1, 'alice', $node);

		$manager = $this->createMock(IManager::class);
		$manager->method('getShareById')->with('s1')->willReturn($share);
		$manager->expects($this->once())->method('deleteShare')->with($share);

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);
		$service->revokeShare('uuid-1', 's1');
		// No exception == pass; deleteShare expectation asserts the call.
		$this->addToAssertionCount(1);
	}//end testRevokeShareHappyPath()

	public function testRevokeShareOutsideFolderIsNotFound(): void {
		$folderNode = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$folderNode]);
		// Share backed by a node id (999) NOT present in the folder.
		$alienNode = $this->buildNode(999, 'other.txt');
		$share = $this->buildShare('s7', IShare::TYPE_USER, 'bob', 1, 'alice', $alienNode);

		$manager = $this->createMock(IManager::class);
		$manager->method('getShareById')->with('s7')->willReturn($share);
		$manager->expects($this->never())->method('deleteShare');

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$service->revokeShare('uuid-1', 's7');
	}//end testRevokeShareOutsideFolderIsNotFound()

	public function testRevokeShareByTheOwnerOfASharedNodeIsAllowed(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);
		$share = $this->buildShare('s9', IShare::TYPE_USER, 'dave', 1, 'bob', $node);
		$share->method('getShareOwner')->willReturn('alice');

		$manager = $this->createMock(IManager::class);
		$manager->method('getShareById')->with('s9')->willReturn($share);
		$manager->expects($this->once())->method('deleteShare')->with($share);

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService($this->buildLogger(), $container);
		$service->revokeShare('uuid-1', 's9');
	}//end testRevokeShareByTheOwnerOfASharedNodeIsAllowed()

	public function testRevokeShareOfSomebodyElseIsNotFound(): void {
		$node = $this->buildNode(10, 'doc.txt');
		$folder = $this->buildFolder([$node]);
		$share = $this->buildShare('s8', IShare::TYPE_USER, 'bob', 1, 'alice', $node);

		$manager = $this->createMock(IManager::class);
		$manager->method('getShareById')->with('s8')->willReturn($share);
		$manager->expects($this->never())->method('deleteShare');

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('carol'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService($this->buildLogger(), $container);

		$this->expectExceptionCode(404);
		$service->revokeShare('uuid-1', 's8');
	}//end testRevokeShareOfSomebodyElseIsNotFound()

	/**
	 * A service over a folder holding file 10 and file 11, with file 11 restricted.
	 *
	 * @param IManager $manager The share manager.
	 *
	 * @return ShareLinkService
	 */
	private function serviceOverARestrictedFile(IManager $manager): ShareLinkService {
		$folder = $this->buildFolder([$this->buildNode(10, 'open.txt'), $this->buildNode(11, 'restricted.txt')]);
		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $manager,
				'OCP\\IUserSession' => $this->buildUserSession('carol'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
				FileRestrictionGuardInterface::class => $this->buildGuard(unreadable: [11]),
			]
		);

		return new ShareLinkService($this->buildLogger(), $container);
	}//end serviceOverARestrictedFile()

	public function testARestrictedFileIsNotListedAsShareable(): void {
		$files = $this->serviceOverARestrictedFile($this->createMock(IManager::class))->getShareableFiles('uuid-1');

		$this->assertSame([10], array_column($files, 'fileId'));
	}//end testARestrictedFileIsNotListedAsShareable()

	public function testARestrictedFileCannotBeShared(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('createShare');

		$this->expectExceptionCode(404);
		$this->serviceOverARestrictedFile($manager)->createShare('uuid-1', (new ShareDraft(11, IShare::TYPE_USER))->withRecipient('bob', 1));
	}//end testARestrictedFileCannotBeShared()

	public function testTheSharesOfARestrictedFileAreNotListed(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			function (string $user, int $type, Node $node): array {
				$this->assertNotSame(11, (int)$node->getId(), 'a restricted file is not asked about');
				return [];
			}
		);

		$this->assertSame([], $this->serviceOverARestrictedFile($manager)->getLinkedShares('uuid-1'));
	}//end testTheSharesOfARestrictedFileAreNotListed()

	public function testWithoutTheGuardNothingIsListed(): void {
		$folder = $this->buildFolder([$this->buildNode(10, 'open.txt')]);
		$services = [
			'OCP\\IUserSession' => $this->buildUserSession('carol'),
			'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
			'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				return $services[$id] ?? throw new RuntimeException('not bound');
			}
		);

		$service = new ShareLinkService($this->buildLogger(), $container);

		$this->assertSame([], $service->getShareableFiles('uuid-1'));
	}//end testWithoutTheGuardNothingIsListed()

	public function testGetShareableFilesListsOnlyFiles(): void {
		$file = $this->buildNode(10, 'doc.txt', FileInfo::TYPE_FILE);
		$subdir = $this->buildNode(11, 'subdir', FileInfo::TYPE_FOLDER);
		$folder = $this->buildFolder([$file, $subdir]);

		$container = $this->buildContainer(
			[
				'OCP\\Share\\IManager' => $this->createMock(IManager::class),
				'OCP\\IUserSession' => $this->buildUserSession('alice'),
				'OCA\\OpenRegister\\Service\\FileService' => $this->buildFolderHandler($folder),
				'OCA\\OpenRegister\\Db\\MagicMapper' => $this->buildObjectMapper(),
			]
		);

		$service = new ShareLinkService( $this->buildLogger(), $container);
		$files = $service->getShareableFiles('uuid-1');

		$this->assertCount(1, $files);
		$this->assertSame(10, $files[0]['fileId']);
		$this->assertSame('doc.txt', $files[0]['fileName']);
	}//end testGetShareableFilesListsOnlyFiles()
}//end class
