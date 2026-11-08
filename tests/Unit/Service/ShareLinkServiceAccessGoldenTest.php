<?php

/**
 * ShareLinkService access golden tests
 *
 * Pins the allowed and refused outcomes of listing, creating and revoking
 * shares on an object's files: every refusal code and message in check order,
 * who is asked for shares, deduplication and the node cap, the normalised row,
 * the share that gets built, and what is deleted and audited.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\ShareDraft;
use OCA\OpenRegister\Service\ShareLinkService;
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
 * Golden tests for ShareLinkService access decisions.
 */
class ShareLinkServiceAccessGoldenTest extends TestCase {

	/**
	 * Share manager mock.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $manager;

	/**
	 * Auditor mock.
	 *
	 * @var GrantChangeAuditor&MockObject
	 */
	private GrantChangeAuditor&MockObject $auditor;

	/**
	 * Recorded calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * What the permission check and the schema lookup were asked.
	 *
	 * @var list<string>
	 */
	private array $asks = [];

	/**
	 * Logged warnings.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(IManager::class);
		$this->auditor = $this->createMock(GrantChangeAuditor::class);
		$this->auditor->method('recordGrant')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = 'audit:grant:'.$args[1]['id'].':'.$args[2];
			}
		);
		$this->auditor->method('recordRevoke')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = 'audit:revoke:'.$args[1]['id'].':'.$args[2];
			}
		);
	}//end setUp()

	/**
	 * Every permission question is the update right on the resolved object.
	 *
	 * @return void
	 */
	protected function assertPostConditions(): void {
		$distinct = array_values(array_unique($this->asks));
		sort($distinct);
		if ($distinct !== []) {
			$this->assertSame(['schema:3', 'update:carol:true'], $distinct);
		}
	}//end assertPostConditions()

	/**
	 * A file node.
	 *
	 * @param int $id Node id
	 *
	 * @return Node
	 */
	private function node(int $id): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getName')->willReturn('f'.$id.'.txt');
		return $node;
	}//end node()

	/**
	 * A share.
	 *
	 * @param string    $id    Share id
	 * @param string    $by    Shared by
	 * @param Node|null $node  Node (null: getNode throws)
	 * @param array     $extra Extra method return values
	 *
	 * @return IShare&MockObject
	 */
	private function share(string $id, string $by, ?Node $node, array $extra=[]): IShare&MockObject {
		$share  = $this->createMock(IShare::class);
		$values = $extra + [
			'getId'                    => $id,
			'getShareType'             => IShare::TYPE_USER,
			'getSharedWith'            => 'bob',
			'getSharedWithDisplayName' => null,
			'getPermissions'           => 17,
			'getSharedBy'              => $by,
			'getShareOwner'            => 'owner-'.$by,
			'getPassword'              => null,
			'getExpirationDate'        => null,
			'getShareTime'             => null,
		];
		foreach ($values as $method => $value) {
			$share->method($method)->willReturn($value);
		}

		if ($node === null) {
			$share->method('getNode')->willThrowException(new RuntimeException('gone'));
		} else {
			$share->method('getNode')->willReturn($node);
		}

		return $share;
	}//end share()

	/**
	 * Build the service over a configurable world.
	 *
	 * @param string|null      $user      Session uid
	 * @param list<Node>|null  $children  Folder children (null: no folder)
	 * @param bool             $account   Whether the app account is reachable
	 * @param bool|string      $mayUpdate Update right (true/false/'throw')
	 * @param bool             $entity    Whether the object resolves to an ObjectEntity
	 *
	 * @return ShareLinkService
	 */
	private function service(?string $user, ?array $children, bool $account=true, bool|string $mayUpdate=true, bool $entity=true): ShareLinkService {
		$session = $this->createMock(IUserSession::class);
		$sessionUser = null;
		if ($user !== null) {
			$sessionUser = $this->createMock(IUser::class);
			$sessionUser->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($sessionUser);

		$folder = null;
		if ($children !== null) {
			$folder = $this->createMock(Folder::class);
			$folder->method('getDirectoryListing')->willReturn($children);
		}

		$appUser = null;
		if ($account === true) {
			$appUser = $this->createMock(IUser::class);
			$appUser->method('getUID')->willReturn('openregister');
		}

		$files = new class($folder, $appUser) {
			public function __construct(private ?Folder $folder, private ?IUser $account) {
			}

			public function getObjectFolder(mixed $objectEntityOrId): ?Folder {
				return $this->folder;
			}

			public function getUser(): IUser {
				if ($this->account === null) {
					throw new RuntimeException('no account');
				}

				return $this->account;
			}
		};

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setSchema('3');
		$object->setOwner('carol');
		$found  = $object;
		if ($entity === false) {
			$found = new \stdClass();
		}

		$mapper = new class($found) {
			public function __construct(private object $found) {
			}

			public function find(string $uuid): object {
				return $this->found;
			}
		};

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function ($id): Schema {
				$this->asks[] = 'schema:'.var_export($id, true);
				return new Schema();
			}
		);
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (...$args) use ($mayUpdate, $object): bool {
				// Recorded, not asserted: the service swallows a Throwable here.
				$this->asks[] = $args[1].':'.$args[3].':'.var_export($args[5] === $object, true);
				if ($mayUpdate === 'throw') {
					throw new RuntimeException('rbac down');
				}

				return $mayUpdate;
			}
		);
		$guard = $this->createMock(FileRestrictionGuard::class);
		$guard->method('readableNodes')->willReturnCallback(
			static fn (array $nodes): array => array_values(array_filter($nodes, static fn (Node $node): bool => (int)$node->getId() !== 13))
		);

		$services  = [
			'OCP\\Share\\IManager'                                        => $this->manager,
			'OCP\\IUserSession'                                           => $session,
			'OCA\\OpenRegister\\Service\\FileService'                     => $files,
			'OCA\\OpenRegister\\Db\\MagicMapper'                          => $mapper,
			'OCA\\OpenRegister\\Db\\SchemaMapper'                         => $schemas,
			'OCA\\OpenRegister\\Service\\Object\\PermissionHandlerInterface' => $permissions,
			'OCA\\OpenRegister\\Service\\Rbac\\GrantChangeAuditor'          => $this->auditor,
			FileRestrictionGuard::class                                   => $guard,
		];
		$container = new class($services) implements ContainerInterface {
			public function __construct(private array $services) {
			}

			public function get(string $id): object {
				if (isset($this->services[$id]) === true) {
					return $this->services[$id];
				}

				throw new class extends RuntimeException implements NotFoundExceptionInterface {
				};
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		return new ShareLinkService($logger, $container);
	}//end service()

	/**
	 * Assert a call throws an Exception with the code and message.
	 *
	 * @param callable $call    The call
	 * @param int      $code    Expected code
	 * @param string   $message Expected message
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, int $code, string $message): void {
		try {
			$call();
			$this->fail('Expected a refusal');
		} catch (Exception $e) {
			$this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()]);
		}
	}//end assertRefused()

	/**
	 * Listing: refusals and who is asked.
	 *
	 * @return void
	 */
	public function testGetLinkedSharesWhoIsAsked(): void {
		$this->assertRefused(fn () => $this->service(null, [])->getLinkedShares('uuid-1'), 401, 'No user logged in');
		$this->assertSame([], $this->service('alice', null)->getLinkedShares('uuid-1'));

		$asked = [];
		$this->manager->method('getSharesBy')->willReturnCallback(
			function (string $uid, int $type, Node $node) use (&$asked): array {
				$asked[] = $uid.':'.$type.':'.$node->getId();
				return [];
			}
		);
		$expectedFor = static function (array $uids): array {
			$out = [];
			foreach ([10, 11] as $nodeId) {
				foreach ($uids as $uid) {
					foreach ([0, 1, 3, 4, 6, 9] as $type) {
						$out[] = $uid.':'.$type.':'.$nodeId;
					}
				}
			}

			return $out;
		};
		$cases = [
			'managed'      => [true, true, true, ['alice', 'openregister']],
			'no right'     => [true, false, true, ['alice']],
			'right throws' => [true, 'throw', true, ['alice']],
			'no account'   => [false, true, true, ['alice']],
			'no entity'    => [true, true, false, ['alice']],
		];
		foreach ($cases as $name => [$account, $mayUpdate, $entity, $uids]) {
			$asked = [];
			$this->service('alice', [$this->node(10), $this->node(13), $this->node(11)], $account, $mayUpdate, $entity)->getLinkedShares('uuid-1');
			$this->assertSame($expectedFor($uids), $asked, $name);
		}
	}//end testGetLinkedSharesWhoIsAsked()

	/**
	 * Listing walks at most 50 readable nodes.
	 *
	 * @return void
	 */
	public function testGetLinkedSharesNodeCap(): void {
		$nodes = [];
		for ($i = 100; $i < 160; $i++) {
			$nodes[] = $this->node($i);
		}

		$seenNodes = [];
		$this->manager->method('getSharesBy')->willReturnCallback(
			function (string $uid, int $type, Node $node) use (&$seenNodes): array {
				$seenNodes[$node->getId()] = true;
				return [];
			}
		);
		$this->service('alice', $nodes, false)->getLinkedShares('uuid-1');
		$this->assertSame(range(100, 149), array_keys($seenNodes));
	}//end testGetLinkedSharesNodeCap()

	/**
	 * Listing: rows, dedupe, swallowed failures, canRevoke.
	 *
	 * @return void
	 */
	public function testGetLinkedSharesRows(): void {
		$node10 = $this->node(10);
		$node11 = $this->node(11);
		$full   = $this->share(
			's1',
			'alice',
			$node10,
			[
				'getShareType'             => IShare::TYPE_LINK,
				'getSharedWith'            => null,
				'getSharedWithDisplayName' => 'Public',
				'getPassword'              => 'secret',
				'getExpirationDate'        => new DateTime('2031-02-03T04:05:06+00:00'),
				'getShareTime'             => new DateTime('2030-01-02T03:04:05+01:00'),
			]
		);
		$emptyPw = $this->share('s2', 'bob', $node11, ['getPassword' => '']);
		$broken  = $this->share('s3', 'alice', null);
		$managed = $this->share('s4', 'openregister', $node11);
		$this->manager->method('getSharesBy')->willReturnCallback(
			function (string $uid, int $type, Node $node) use ($full, $emptyPw, $broken, $managed): array {
				if ($type === IShare::TYPE_GROUP) {
					throw new RuntimeException('group backend down');
				}

				if ($uid === 'alice' && $node->getId() === 10) {
					return [$full, $broken];
				}

				if ($uid === 'alice' && $node->getId() === 11 && $type === IShare::TYPE_USER) {
					return [$emptyPw, $full];
				}

				if ($uid === 'openregister' && $type === IShare::TYPE_USER) {
					return [$managed, $emptyPw];
				}

				return [];
			}
		);

		$rows = $this->service('alice', [$node10, $node11])->getLinkedShares('uuid-1');
		$this->assertSame(
			[
				[
					'shareId'              => 's1',
					'shareType'            => IShare::TYPE_LINK,
					'shareWith'            => '',
					'shareWithDisplayName' => 'Public',
					'permissions'          => 17,
					'passwordProtected'    => true,
					'expiration'           => '2031-02-03T04:05:06+00:00',
					'createdAt'            => '2030-01-02T03:04:05+01:00',
					'fileId'               => 10,
					'fileName'             => 'f10.txt',
					'canRevoke'            => true,
				],
				[
					'shareId'              => 's4',
					'shareType'            => IShare::TYPE_USER,
					'shareWith'            => 'bob',
					'shareWithDisplayName' => 'bob',
					'permissions'          => 17,
					'passwordProtected'    => false,
					'expiration'           => null,
					'createdAt'            => null,
					'fileId'               => 11,
					'fileName'             => 'f11.txt',
					'canRevoke'            => true,
				],
				[
					'shareId'              => 's2',
					'shareType'            => IShare::TYPE_USER,
					'shareWith'            => 'bob',
					'shareWithDisplayName' => 'bob',
					'permissions'          => 17,
					'passwordProtected'    => false,
					'expiration'           => null,
					'createdAt'            => null,
					'fileId'               => 11,
					'fileName'             => 'f11.txt',
					'canRevoke'            => false,
				],
			],
			// Node 10 (alice; the account shares nothing there), then node 11
			// (alice, then the account); s1 again and s3 (node gone) are dropped.
			$rows
		);
	}//end testGetLinkedSharesRows()

	/**
	 * Creating: every refusal, in check order.
	 *
	 * @return void
	 */
	public function testCreateShareRefusals(): void {
		$this->manager->expects($this->never())->method('createShare');
		$nodes = [$this->node(10), $this->node(13)];
		$draft = (new ShareDraft(10, IShare::TYPE_USER))->withRecipient('bob', 1);

		$this->assertRefused(fn () => $this->service(null, $nodes)->createShare('uuid-1', $draft), 401, 'No user logged in');
		$this->assertRefused(fn () => $this->service('alice', null)->createShare('uuid-1', $draft), 404, 'File does not belong to this object');
		$this->assertRefused(fn () => $this->service('alice', $nodes)->createShare('uuid-1', new ShareDraft(99, IShare::TYPE_USER)), 404, 'File does not belong to this object');
		// A restricted file is not in the folder for the caller.
		$this->assertRefused(fn () => $this->service('alice', $nodes)->createShare('uuid-1', new ShareDraft(13, IShare::TYPE_LINK)), 404, 'File does not belong to this object');
		$this->assertRefused(fn () => $this->service('alice', $nodes)->createShare('uuid-1', new ShareDraft(10, 2)), 400, 'Unsupported share type');
		$this->assertRefused(fn () => $this->service('alice', $nodes)->createShare('uuid-1', (new ShareDraft(10, IShare::TYPE_EMAIL))->withRecipient('  ', 1)), 400, 'shareWith is required for this share type');
		$this->assertRefused(fn () => $this->service('alice', $nodes, true, false)->createShare('uuid-1', $draft), 403, 'You may not change this object, so you cannot share its files.');
		$this->assertRefused(fn () => $this->service('alice', $nodes, true, 'throw')->createShare('uuid-1', $draft), 403, 'You may not change this object, so you cannot share its files.');
		$this->assertRefused(fn () => $this->service('alice', $nodes, true, true, false)->createShare('uuid-1', $draft), 403, 'You may not change this object, so you cannot share its files.');
		$this->assertRefused(fn () => $this->service('alice', $nodes, false)->createShare('uuid-1', $draft), 503, 'The OpenRegister account is not available');
		$this->assertSame([], $this->calls);
	}//end testCreateShareRefusals()

	/**
	 * Creating: the built share, the audit entry, and a manager failure.
	 *
	 * @return void
	 */
	public function testCreateShareAllowed(): void {
		$node  = $this->node(10);
		$built = $this->createMock(IShare::class);
		foreach (['setNode', 'setShareType', 'setSharedBy', 'setPermissions', 'setSharedWith', 'setPassword', 'setExpirationDate'] as $setter) {
			$built->method($setter)->willReturnCallback(
				function ($value) use ($setter, $built) {
					$shown = $value;
					if ($value instanceof Node) {
						$shown = 'node'.$value->getId();
					} elseif ($value instanceof DateTime) {
						$shown = $value->format('Y-m-d');
					}

					$this->calls[] = $setter.':'.$shown;
					return $built;
				}
			);
		}

		$built->method('getId')->willReturn('new-1');
		$built->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$this->manager->method('newShare')->willReturn($built);
		$this->manager->method('createShare')->willReturnOnConsecutiveCalls($built, $this->throwException(new RuntimeException('Path is already shared')));

		$draft  = (new ShareDraft(10, IShare::TYPE_LINK))->withRecipient(' bob ', 3)->withProtection('pw', '2031-05-06');
		$result = $this->service('alice', [$node])->createShare('uuid-1', $draft);
		$this->assertSame($built, $result);
		$this->assertSame(
			['setNode:node10', 'setShareType:3', 'setSharedBy:openregister', 'setPermissions:3', 'setSharedWith:bob', 'setPassword:pw', 'setExpirationDate:2031-05-06', 'audit:grant:new-1:update'],
			$this->calls
		);

		$this->calls = [];
		$this->assertRefused(fn () => $this->service('alice', [$node])->createShare('uuid-1', new ShareDraft(10, IShare::TYPE_LINK)), 400, 'Path is already shared');
		$this->assertSame(['setNode:node10', 'setShareType:3', 'setSharedBy:openregister', 'setPermissions:0'], $this->calls);
		$this->assertSame(['ShareLinkService::createShare failed: Path is already shared'], $this->warnings);
	}//end testCreateShareAllowed()

	/**
	 * Revoking: every refusal.
	 *
	 * @return void
	 */
	public function testRevokeShareRefusals(): void {
		$this->manager->expects($this->never())->method('deleteShare');
		$inFolder  = $this->node(10);
		$elsewhere = $this->node(77);
		$shares    = [
			'others'          => $this->share('s-others', 'dave', $inFolder),
			'outside'         => $this->share('s-outside', 'alice', $elsewhere),
			'gone'            => $this->share('s-gone', 'alice', null),
			'managed'         => $this->share('s-managed', 'openregister', $inFolder),
		];
		$this->manager->method('getShareById')->willReturnCallback(
			function (string $id) use ($shares): IShare {
				if (isset($shares[$id]) === false) {
					throw new RuntimeException('not found');
				}

				return $shares[$id];
			}
		);
		$children = [$inFolder, $this->node(13)];

		$this->assertRefused(fn () => $this->service(null, $children)->revokeShare('uuid-1', 'others'), 401, 'No user logged in');
		$this->assertRefused(fn () => $this->service('alice', $children)->revokeShare('uuid-1', 'missing'), 404, 'Share not found');
		$this->assertRefused(fn () => $this->service('alice', null)->revokeShare('uuid-1', 'others'), 404, 'Share not found');
		$this->assertRefused(fn () => $this->service('alice', $children)->revokeShare('uuid-1', 'outside'), 404, 'Share not found');
		$this->assertRefused(fn () => $this->service('alice', $children)->revokeShare('uuid-1', 'gone'), 404, 'Share not found');
		$this->assertRefused(fn () => $this->service('alice', $children)->revokeShare('uuid-1', 'others'), 404, 'Share not found');
		$this->assertRefused(fn () => $this->service('alice', $children, true, false)->revokeShare('uuid-1', 'managed'), 404, 'Share not found');
		$this->assertRefused(fn () => $this->service('alice', $children, false, true)->revokeShare('uuid-1', 'managed'), 404, 'Share not found');
		$this->assertSame([], $this->calls);
	}//end testRevokeShareRefusals()

	/**
	 * Revoking: allowed for the sharer, the share owner and, with the update right, the app account's share.
	 *
	 * @return void
	 */
	public function testRevokeShareAllowed(): void {
		$inFolder = $this->node(10);
		$shares   = [
			'mine'    => $this->share('s-mine', 'alice', $inFolder),
			'owned'   => $this->share('s-owned', 'dave', $inFolder, ['getShareOwner' => 'alice']),
			'managed' => $this->share('s-managed', 'openregister', $inFolder),
			// A restricted node still counts as inside the folder.
			'hidden'  => $this->share('s-hidden', 'alice', $this->node(13)),
		];
		$this->manager->method('getShareById')->willReturnCallback(fn (string $id): IShare => $shares[$id]);
		$this->manager->method('deleteShare')->willReturnCallback(
			function (IShare $share): void {
				$this->calls[] = 'delete:'.$share->getId();
			}
		);

		$children = [$inFolder, $this->node(13)];
		$this->service('alice', $children, true, false)->revokeShare('uuid-1', 'mine');
		$this->service('alice', $children, false, false)->revokeShare('uuid-1', 'owned');
		$this->service('alice', $children, true, true)->revokeShare('uuid-1', 'managed');
		$this->service('alice', $children)->revokeShare('uuid-1', 'hidden');
		$this->assertSame(
			[
				'delete:s-mine',
				'audit:revoke:s-mine:update',
				'delete:s-owned',
				'audit:revoke:s-owned:update',
				'delete:s-managed',
				'audit:revoke:s-managed:update',
				'delete:s-hidden',
				'audit:revoke:s-hidden:update',
			],
			$this->calls
		);
	}//end testRevokeShareAllowed()
}//end class
