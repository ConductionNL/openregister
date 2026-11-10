<?php

/**
 * Characterisation tests for SharesProvider: the ShareLinkService delegate and its
 * fallback, the folder walk (dedup, per-type failure, node cap, unreadable share),
 * the row contract (canRevoke for the owner and for anyone else), the public-token
 * create options and the delete routing.
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
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use DateTimeImmutable;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\CaseTokenTarget;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
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
use RuntimeException;

/**
 * Pins SharesProvider outcomes before its units are split.
 */
class SharesProviderGoldenTest extends TestCase {

	/**
	 * A provider over the given container services.
	 *
	 * @param array<string, object> $services Services by id.
	 *
	 * @return SharesProvider
	 */
	private function provider(array $services): SharesProvider {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (array_key_exists($id, $services) === true) {
					return $services[$id];
				}

				throw new class extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new SharesProvider(
			l10n: $l10n,
			container: $container,
		);
	}//end provider()

	/**
	 * The services of a folder walk as the given user over the given nodes.
	 *
	 * @param IManager    $manager The share manager.
	 * @param array<Node> $nodes   The folder's children.
	 * @param string|null $uid     The signed-in user, or null.
	 *
	 * @return array<string, object>
	 */
	private function walkServices(IManager $manager, array $nodes, ?string $uid): array {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($nodes);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$fileService = new class($folder) {
			public function __construct(private Folder $folder) {
			}//end __construct()

			public function getObjectFolder(mixed $entityOrId): Folder {
				return $this->folder;
			}//end getObjectFolder()
		};

		return [
			'OCP\\Share\\IManager' => $manager,
			'OCA\\OpenRegister\\Service\\FileService' => $fileService,
			'OCP\\IUserSession' => $session,
		];
	}//end walkServices()

	/**
	 * A node.
	 *
	 * @param int $id The node id.
	 *
	 * @return Node
	 */
	private function node(int $id): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn('file-' . $id);
		return $node;
	}//end node()

	/**
	 * A share.
	 *
	 * @param string $id    The share id.
	 * @param string $owner The sharer.
	 * @param Node   $node  The shared node.
	 *
	 * @return IShare
	 */
	private function share(string $id, string $owner, Node $node): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn($id);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getSharedWith')->willReturn(null);
		$share->method('getSharedWithDisplayName')->willReturn(null);
		$share->method('getPermissions')->willReturn(1);
		$share->method('getSharedBy')->willReturn($owner);
		$share->method('getNode')->willReturn($node);
		$share->method('getPassword')->willReturn('');
		$share->method('getExpirationDate')->willReturn(new DateTimeImmutable('2030-01-02T03:04:05+00:00'));
		$share->method('getShareTime')->willReturn(new DateTimeImmutable('@1700000000'));
		$share->method('getToken')->willReturn('tok-' . $id);
		return $share;
	}//end share()

	/**
	 * The full row of a walked share, and canRevoke only for its owner.
	 *
	 * @return void
	 */
	public function testWalkedRowContractAndRevokeFlag(): void {
		$node    = $this->node(id: 7);
		$own     = $this->share(id: 'a', owner: 'bob', node: $node);
		$other   = $this->share(id: 'b', owner: 'carol', node: $node);
		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			static function ($uid, int $type) use ($own, $other): array {
				if ($type === IShare::TYPE_LINK) {
					return [$own, $other];
				}

				return [];
			}
		);

		$rows = $this->provider(services: $this->walkServices(manager: $manager, nodes: [$node], uid: 'bob'))
			->list(register: 'r', schema: 's', objectId: 'o');

		$this->assertSame(
			[
				'id' => 'a',
				'shareType' => IShare::TYPE_LINK,
				'shareWith' => '',
				'shareWithDisplayname' => '',
				'permissions' => 1,
				'passwordProtected' => false,
				'expiration' => '2030-01-02T03:04:05+00:00',
				'stime' => 1700000000,
				'nodeName' => 'file-7',
				'canRevoke' => true,
				'url' => '/index.php/apps/files/?fileid=7',
				'data' => ['id' => 'a', 'token' => 'tok-a', 'owner' => 'bob', 'nodeId' => 7],
			],
			$rows[0]
		);
		$this->assertSame(['b', false], [$rows[1]['id'], $rows[1]['canRevoke']]);
		$this->assertCount(2, $rows);
	}//end testWalkedRowContractAndRevokeFlag()

	/**
	 * Duplicates collapse, a failing type is skipped, an unreadable share is dropped,
	 * a node id of 0 links to the files app, and only the first 50 nodes are walked.
	 *
	 * @return void
	 */
	public function testWalkDedupFailuresAndCap(): void {
		$nodes = [];
		for ($i = 0; $i < 55; $i++) {
			$nodes[] = $this->node(id: $i);
		}

		$broken = $this->createMock(IShare::class);
		$broken->method('getId')->willThrowException(new RuntimeException('gone'));

		$walked  = [];
		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			function ($uid, int $type, Node $node) use (&$walked, $broken): array {
				$walked[$node->getId()] = true;
				if ($type === IShare::TYPE_GROUP) {
					throw new RuntimeException('no group backend');
				}

				if ($type === IShare::TYPE_USER || $type === IShare::TYPE_LINK) {
					return [$this->share(id: 'dup', owner: 'x', node: $node), $broken];
				}

				if ($type === IShare::TYPE_EMAIL && $node->getId() === 0) {
					return [$this->share(id: 'zero', owner: 'x', node: $node)];
				}

				return [];
			}
		);

		$rows = $this->provider(services: $this->walkServices(manager: $manager, nodes: $nodes, uid: 'bob'))
			->list(register: 'r', schema: 's', objectId: 'o');

		$this->assertSame(['dup', 'zero'], array_column($rows, 'id'));
		$this->assertSame('/index.php/apps/files', $rows[1]['url']);
		$this->assertSame(0, $rows[0]['data']['nodeId']);
		$this->assertCount(50, $walked);
	}//end testWalkDedupFailuresAndCap()

	/**
	 * An anonymous caller lists nothing.
	 *
	 * @return void
	 */
	public function testAnonymousListsNothing(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('getSharesBy');

		$this->assertSame(
			[],
			$this->provider(services: $this->walkServices(manager: $manager, nodes: [$this->node(id: 1)], uid: null))
				->list(register: 'r', schema: 's', objectId: 'o')
		);
	}//end testAnonymousListsNothing()

	/**
	 * The ShareLinkService rows are re-mapped; when it throws, the walk runs instead.
	 *
	 * @return void
	 */
	public function testServiceDelegateAndFallback(): void {
		$service = new class {
			/**
			 * Whether the service throws.
			 *
			 * @var bool
			 */
			public bool $fail = false;

			public function getLinkedShares(string $objectId): array {
				if ($this->fail === true) {
					throw new RuntimeException('down');
				}

				return [
					['shareId' => 5, 'fileId' => 9, 'shareType' => 3, 'shareWith' => 'w', 'shareWithDisplayName' => 'W',
						'permissions' => 17, 'passwordProtected' => 1, 'expiration' => 'e', 'createdAt' => 'c',
						'fileName' => 'f', 'canRevoke' => 0],
					[],
				];
			}//end getLinkedShares()
		};

		$rows = $this->provider(services: ['OCA\\OpenRegister\\Service\\ShareLinkService' => $service])
			->list(register: 'r', schema: 's', objectId: 'o');
		$this->assertSame(
			[
				[
					'id' => '5', 'shareType' => 3, 'shareWith' => 'w', 'shareWithDisplayname' => 'W', 'permissions' => 17,
					'passwordProtected' => true, 'expiration' => 'e', 'stime' => 'c', 'nodeName' => 'f', 'canRevoke' => false,
					'url' => '/index.php/apps/files/?fileid=9', 'data' => ['id' => '5', 'nodeId' => 9],
				],
				[
					'id' => '', 'shareType' => 0, 'shareWith' => '', 'shareWithDisplayname' => '', 'permissions' => 0,
					'passwordProtected' => false, 'expiration' => null, 'stime' => null, 'nodeName' => '', 'canRevoke' => false,
					'url' => '/index.php/apps/files', 'data' => ['id' => '', 'nodeId' => 0],
				],
			],
			$rows
		);

		$service->fail = true;
		$node          = $this->node(id: 3);
		$manager       = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			function ($uid, int $type) use ($node): array {
				if ($type === IShare::TYPE_USER) {
					return [$this->share(id: 'w1', owner: 'bob', node: $node)];
				}

				return [];
			}
		);
		$services = $this->walkServices(manager: $manager, nodes: [$node], uid: 'bob');
		$services['OCA\\OpenRegister\\Service\\ShareLinkService'] = $service;

		$this->assertSame(['w1'], array_column($this->provider(services: $services)->list(register: 'r', schema: 's', objectId: 'o'), 'id'));
	}//end testServiceDelegateAndFallback()

	/**
	 * A failure of the walk itself lists nothing.
	 *
	 * @return void
	 */
	public function testWalkFailureListsNothing(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willThrowException(new RuntimeException('io'));
		$services = $this->walkServices(manager: $this->createMock(IManager::class), nodes: [], uid: 'bob');
		$services['OCA\\OpenRegister\\Service\\FileService'] = new class($folder) {
			public function __construct(private Folder $folder) {
			}//end __construct()

			public function getObjectFolder(mixed $entityOrId): Folder {
				return $this->folder;
			}//end getObjectFolder()
		};

		$this->assertSame([], $this->provider(services: $services)->list(register: 'r', schema: 's', objectId: 'o'));
	}//end testWalkFailureListsNothing()

	/**
	 * Create options: slugs become null, a non-numeric ttl is ignored, a label is cast;
	 * any other type answers the 501.
	 *
	 * @return void
	 */
	public function testCreateOptions(): void {
		$service = new class {
			/**
			 * The last mint arguments.
			 *
			 * @var array<int, mixed>
			 */
			public array $args = [];

			public function mintFor(CaseTokenTarget $target, ?int $ttlSeconds): array {
				$this->args = [$target->objectUuid, $target->registerId, $target->schemaId, $target->label, $ttlSeconds];
				return ['ok' => true];
			}//end mintFor()
		};
		$provider = $this->provider(services: ['OCA\\OpenRegister\\Service\\CaseTokenService' => $service]);

		$this->assertSame(['ok' => true], $provider->create(register: 'reg', schema: '4', objectId: 'o', payload: ['label' => 12, 'ttlSeconds' => 'soon']));
		$this->assertSame(['o', null, 4, '12', null], $service->args);

		$provider->create(register: '1', schema: 's', objectId: 'o', payload: ['ttlSeconds' => '60']);
		$this->assertSame(['o', 1, null, null, 60], $service->args);

		$this->expectException(NotImplementedException::class);
		$provider->create(register: '1', schema: 's', objectId: 'o', payload: ['type' => 'file']);
	}//end testCreateOptions()

	/**
	 * Delete routing: a token id without a token service answers the 501; a share id
	 * goes to the object-scoped revoke.
	 *
	 * @return void
	 */
	public function testDeleteRouting(): void {
		$links = new class {
			/**
			 * The revoked shares.
			 *
			 * @var array<int, array<int, string>>
			 */
			public array $revoked = [];

			public function revokeShare(string $objectId, string $shareId): void {
				$this->revoked[] = [$objectId, $shareId];
			}//end revokeShare()
		};
		$provider = $this->provider(services: ['OCA\\OpenRegister\\Service\\ShareLinkService' => $links]);

		$provider->delete(register: 'r', schema: 's', objectId: 'o', entityId: '42');
		$this->assertSame([['o', '42']], $links->revoked);

		$this->expectException(NotImplementedException::class);
		$provider->delete(register: 'r', schema: 's', objectId: 'o', entityId: 'token:abc');
	}//end testDeleteRouting()
}//end class
