<?php

/**
 * Golden test for ObjectGrantResolver's share-reading paths.
 *
 * Pins, on the code as it stood before its units were split, which object
 * UUIDs a caller is granted (and with which bitmask, verbs and inheritability)
 * for every branch of the share walk: anonymous callers, an unreachable share
 * manager, a share type that throws, file shares, non-UUID folders, overlapping
 * grants, paging and the paging bound, memoisation and the hierarchy seeds.
 * Allowed AND refused outcomes are both asserted.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCA\OpenRegister\Service\Rbac\GrantLookup;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCP\Constants;
use OCP\Files\Node;
use OCP\Share\IAttributes;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver
 */
final class ObjectGrantResolverGoldenTest extends TestCase {

	private const UUID_A = '11111111-1111-4111-8111-111111111111';

	private const UUID_B = '22222222-2222-4222-8222-222222222222';

	private const UUID_C = '33333333-3333-4333-8333-333333333333';

	/**
	 * Log lines recorded by the resolver, as "level message".
	 *
	 * @var string[]
	 */
	private array $logLines = [];

	/**
	 * Calls made to getSharedWith, as "type:offset".
	 *
	 * @var string[]
	 */
	private array $calls = [];

	/**
	 * Stored folder node id per object uuid; an absent uuid has no stored row.
	 *
	 * @var array<string, int>
	 */
	private array $folders = [
		self::UUID_A => 1001,
		self::UUID_B => 1002,
		self::UUID_C => 1003,
	];

	/**
	 * A logger that records level and message.
	 *
	 * @return AbstractLogger The logger.
	 */
	private function logger(): AbstractLogger {
		$lines = &$this->logLines;
		return new class($lines) extends AbstractLogger {
			/**
			 * @var string[]
			 */
			private array $lines;

			/**
			 * @param string[] $lines Recorded lines, by reference.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			/**
			 * @param mixed             $level   Level.
			 * @param string|Stringable $message Message.
			 * @param array             $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level.' '.$message;
			}
		};
	}//end logger()

	/**
	 * A folder share on a node with the given name.
	 *
	 * @param string            $name        Node name.
	 * @param integer           $permissions Permission bitmask.
	 * @param mixed             $verbs       Raw verbs attribute.
	 * @param mixed             $inheritable Raw inheritable attribute.
	 * @param string            $nodeType    Node type.
	 * @param integer|null      $nodeId      Shared node id; null for the folder stored for an object of that name.
	 *
	 * @return IShare The share.
	 */
	private function share(
		string $name,
		int $permissions,
		mixed $verbs = null,
		mixed $inheritable = null,
		string $nodeType = 'folder',
		?int $nodeId = null
	): IShare {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($name);

		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturnCallback(
			static function (string $scope, string $key) use ($verbs, $inheritable) {
				if ($key === 'verbs') {
					return $verbs;
				}

				return $inheritable;
			}
		);

		$share = $this->createMock(IShare::class);
		$share->method('getNodeType')->willReturn($nodeType);
		$share->method('getNode')->willReturn($node);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getAttributes')->willReturn($attributes);
		$share->method('getNodeId')->willReturn($nodeId ?? ($this->folders[$name] ?? crc32($name)));
		return $share;
	}//end share()

	/**
	 * A resolver over a share manager answering from a per-type callback.
	 *
	 * @param callable                      $sharesFor Fn(int $type, int $offset): IShare[] (may throw).
	 * @param HierarchyGrantExpander|null   $hierarchy Optional expander.
	 *
	 * @return ObjectGrantResolver The resolver.
	 */
	private function resolver(callable $sharesFor, ?HierarchyGrantExpander $hierarchy = null): ObjectGrantResolver {
		$calls   = &$this->calls;
		$manager = $this->createMock(IManager::class);
		$manager->method('getSharedWith')->willReturnCallback(
			static function (string $userId, int $type, $node, int $limit, int $offset) use ($sharesFor, &$calls) {
				$calls[] = $type.':'.$offset.':'.$limit;
				return $sharesFor($type, $offset);
			}
		);

		$folders = $this->folders;
		$objects = $this->createMock(originalClassName: MagicCrossTableReaderInterface::class);
		$objects->method('findFolderIdsByUuids')->willReturnCallback(
			static fn (array $uuids): array => array_intersect_key($folders, array_flip($uuids))
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				IManager::class => $manager,
				default => $objects,
			}
		);

		return new ObjectGrantResolver($this->logger(), $container, $hierarchy);
	}//end resolver()

	/**
	 * Anonymous callers hold nothing and never reach the share manager.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAreRefused(): void {
		$resolver = $this->resolver(fn () => [$this->share(self::UUID_A, Constants::PERMISSION_ALL)]);

		$this->assertSame([], $resolver->grantedObjectUuids(null));
		$this->assertSame([], $resolver->grantedObjectUuids(''));
		$this->assertFalse($resolver->hasAnyGrant(null));
		$this->assertFalse($resolver->isGranted(new GrantLookup(null, self::UUID_A), 'read'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup(null, self::UUID_A), 'use'));
		$this->assertSame([], $this->calls);
	}//end testAnonymousCallersAreRefused()

	/**
	 * A missing object uuid is refused and never reaches the share manager,
	 * while the same caller is admitted on a uuid the grant names.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsRefusedWithoutReadingShares(): void {
		$resolver = $this->resolver(fn () => [$this->share(self::UUID_A, Constants::PERMISSION_ALL)]);

		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', null), 'read'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', ''), 'read'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', null), 'use'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', ''), 'use'));
		$this->assertSame([], $this->calls);

		$this->assertTrue($resolver->isGranted(new GrantLookup('alice', self::UUID_A), 'read'));
	}//end testAMissingObjectIsRefusedWithoutReadingShares()

	/**
	 * An unreachable share manager fails closed and logs an error, memoised.
	 *
	 * @return void
	 */
	public function testUnreachableManagerFailsClosed(): void {
		foreach (['throws', 'wrongType'] as $mode) {
			$this->logLines = [];
			$container      = $this->createMock(ContainerInterface::class);
			if ($mode === 'throws') {
				$container->expects($this->once())->method('get')->willThrowException(new RuntimeException('x'));
			} else {
				$container->expects($this->once())->method('get')->willReturn(new \stdClass());
			}

			$resolver = new ObjectGrantResolver($this->logger(), $container);
			$this->assertSame([], $resolver->grantedObjectUuids('alice'), $mode);
			$this->assertSame([], $resolver->grantedObjectUuids('alice'), $mode.' memoised');
			$this->assertFalse($resolver->isGranted(new GrantLookup('alice', self::UUID_A), 'read'), $mode);
			$this->assertSame(
				['error [ObjectGrantResolver] Share manager unreachable; treating the caller as holding no grants'],
				$this->logLines,
				$mode
			);
		}
	}//end testUnreachableManagerFailsClosed()

	/**
	 * The full walk over four share types: what is admitted and what is not.
	 *
	 * @return void
	 */
	public function testTheShareWalkGolden(): void {
		$read   = Constants::PERMISSION_READ;
		$update = Constants::PERMISSION_UPDATE;
		$byType = [
			IShare::TYPE_USER => [
				$this->share(self::UUID_A, $read, ['use', 'run']),
				// A file share inside an object folder grants nothing.
				$this->share(self::UUID_C, Constants::PERMISSION_ALL, null, null, 'file'),
				// A non-UUID folder (register/schema container) grants nothing.
				$this->share('my-register', Constants::PERMISSION_ALL),
			],
			// Group grant on the same object: bitmask OR'd, verbs unioned.
			IShare::TYPE_GROUP => [
				$this->share(self::UUID_A, $update, '["run","approve",""]', false),
			],
			IShare::TYPE_REMOTE => 'throw',
			IShare::TYPE_REMOTE_GROUP => [
				$this->share(self::UUID_B, $read, 'not-json', '0'),
			],
		];

		$resolver = $this->resolver(
			static function (int $type) use ($byType) {
				if ($byType[$type] === 'throw') {
					throw new RuntimeException('boom');
				}

				return $byType[$type];
			}
		);

		$this->assertSame(
			[self::UUID_A => ($read | $update), self::UUID_B => $read],
			$resolver->grantedObjectUuids('alice')
		);
		$this->assertSame(
			[
				IShare::TYPE_USER.':0:100',
				IShare::TYPE_GROUP.':0:100',
				IShare::TYPE_REMOTE.':0:100',
				IShare::TYPE_REMOTE_GROUP.':0:100',
			],
			$this->calls
		);
		$this->assertSame(
			['warning [ObjectGrantResolver] Could not read shares for a type; skipping it'],
			$this->logLines
		);

		// Allowed.
		$this->assertTrue($resolver->hasAnyGrant('alice'));
		$this->assertTrue($resolver->isGranted(new GrantLookup('alice', self::UUID_A), 'read'));
		$this->assertTrue($resolver->isGranted(new GrantLookup('alice', self::UUID_A), 'update'));
		$this->assertTrue($resolver->isGranted(new GrantLookup('alice', self::UUID_B)));
		$this->assertTrue($resolver->grantCarriesVerb(new GrantLookup('alice', self::UUID_A), 'use'));
		$this->assertTrue($resolver->grantCarriesVerb(new GrantLookup('alice', self::UUID_A), 'approve'));
		$this->assertSame([self::UUID_A => ($read | $update)], $resolver->grantedObjectUuidsFor('alice', 'update'));

		// Refused.
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', self::UUID_A), 'delete'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', self::UUID_B), 'update'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', self::UUID_C), 'read'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', null), 'read'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', ''), 'read'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('alice', self::UUID_A), 'besluit_nemen'));
		$this->assertSame([], $resolver->grantedObjectUuidsFor('alice', 'besluit_nemen'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', self::UUID_A), ''));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', self::UUID_A), 'delete'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', self::UUID_B), 'use'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', self::UUID_C), 'use'));
		$this->assertFalse($resolver->grantCarriesVerb(new GrantLookup('alice', null), 'use'));

		// Inheritability: one local grant holds the object back.
		$this->assertFalse($resolver->isInheritable(self::UUID_A));
		$this->assertFalse($resolver->isInheritable(self::UUID_B));
		$this->assertTrue($resolver->isInheritable(self::UUID_C));
		$this->assertNull($resolver->inheritedFrom(self::UUID_A));

		// Memoised: no further share-manager calls.
		$this->assertCount(4, $this->calls);

		// A per-user forget() drops the memo and the object maps.
		$resolver->forget('alice');
		$this->assertTrue($resolver->isInheritable(self::UUID_A));
		$resolver->grantedObjectUuids('alice');
		$this->assertCount(8, $this->calls);
		$resolver->forget();
		$this->assertTrue($resolver->isInheritable(self::UUID_A));
	}//end testTheShareWalkGolden()

	/**
	 * A full page reads the next; a short page stops; the bound is logged.
	 *
	 * @return void
	 */
	public function testPagingAndTheBound(): void {
		$full = [];
		for ($i = 0; $i < 100; $i++) {
			$uuid = sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i);
			$this->folders[$uuid] = (2000 + $i);
			$full[] = $this->share(name: $uuid, permissions: Constants::PERMISSION_READ);
		}

		// USER: one full page, then a short one with UUID_B. GROUP: always full -> bound.
		$resolver = $this->resolver(
			function (int $type, int $offset) use ($full) {
				if ($type === IShare::TYPE_USER && $offset === 0) {
					return $full;
				}

				if ($type === IShare::TYPE_USER) {
					return [$this->share(self::UUID_B, Constants::PERMISSION_READ)];
				}

				if ($type === IShare::TYPE_GROUP) {
					return $full;
				}

				return [];
			}
		);

		$granted = $resolver->grantedObjectUuids('bob');
		$this->assertCount(101, $granted);
		$this->assertArrayHasKey(self::UUID_B, $granted);

		$groupCalls = array_values(
			array_filter($this->calls, static fn (string $c) => str_starts_with($c, IShare::TYPE_GROUP.':'))
		);
		$this->assertCount(20, $groupCalls);
		$this->assertSame(IShare::TYPE_GROUP.':1900:100', $groupCalls[19]);
		$this->assertSame(
			[IShare::TYPE_USER.':0:100', IShare::TYPE_USER.':100:100'],
			array_values(array_filter($this->calls, static fn (string $c) => str_starts_with($c, IShare::TYPE_USER.':')))
		);
		$this->assertSame(
			['warning [ObjectGrantResolver] Hit the share paging bound; some grants were NOT read'],
			$this->logLines
		);
	}//end testPagingAndTheBound()

	/**
	 * The hierarchy expander is seeded with inheritable grants only.
	 *
	 * @return void
	 */
	public function testHierarchySeedsExcludeLocalGrants(): void {
		$hierarchy = $this->createMock(HierarchyGrantExpander::class);
		$hierarchy->expects($this->once())->method('expand')->with(
			[self::UUID_A => Constants::PERMISSION_READ, self::UUID_B => Constants::PERMISSION_READ],
			[self::UUID_A => Constants::PERMISSION_READ]
		)->willReturn(
			[
				'granted' => [
					self::UUID_A => Constants::PERMISSION_READ,
					self::UUID_B => Constants::PERMISSION_READ,
					self::UUID_C => Constants::PERMISSION_READ,
				],
				'sources' => [self::UUID_C => self::UUID_A],
			]
		);

		$resolver = $this->resolver(
			function (int $type) {
				if ($type !== IShare::TYPE_USER) {
					return [];
				}

				return [
					$this->share(self::UUID_A, Constants::PERMISSION_READ),
					$this->share(self::UUID_B, Constants::PERMISSION_READ, null, 'false'),
				];
			},
			$hierarchy
		);

		$this->assertSame(
			[
				self::UUID_A => Constants::PERMISSION_READ,
				self::UUID_B => Constants::PERMISSION_READ,
				self::UUID_C => Constants::PERMISSION_READ,
			],
			$resolver->grantedObjectUuids('carol')
		);
		$this->assertSame(self::UUID_A, $resolver->inheritedFrom(self::UUID_C));
		$this->assertNull($resolver->inheritedFrom(self::UUID_A));
		$this->assertTrue($resolver->isGranted(new GrantLookup('carol', self::UUID_C), 'read'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('carol', self::UUID_C), 'update'));
		// Memoised: expand() called once.
		$resolver->grantedObjectUuids('carol');
	}//end testHierarchySeedsExcludeLocalGrants()

	/**
	 * A folder that only carries an object's uuid as its name grants nothing:
	 * the share must be on the folder stored for that object.
	 *
	 * @return void
	 */
	public function testAShareOnAnotherFolderWithTheObjectsNameGrantsNothing(): void {
		$read   = Constants::PERMISSION_READ;
		$byType = [
			IShare::TYPE_USER => [
				$this->share(name: self::UUID_A, permissions: $read),
				$this->share(name: self::UUID_B, permissions: Constants::PERMISSION_ALL, verbs: ['use'], nodeId: 4242),
			],
			IShare::TYPE_GROUP => [
				$this->share(name: self::UUID_C, permissions: Constants::PERMISSION_ALL, nodeId: 4343),
			],
		];

		$resolver = $this->resolver(sharesFor: static fn (int $type): array => ($byType[$type] ?? []));

		$this->assertSame(expected: [self::UUID_A => $read], actual: $resolver->grantedObjectUuids(userId: 'mallory'));
		$this->assertFalse(condition: $resolver->isGranted(lookup: new GrantLookup(userId: 'mallory', objectUuid: self::UUID_B)));
		$this->assertFalse(condition: $resolver->isGranted(lookup: new GrantLookup(userId: 'mallory', objectUuid: self::UUID_C)));
		$this->assertFalse(
			condition: $resolver->grantCarriesVerb(lookup: new GrantLookup(userId: 'mallory', objectUuid: self::UUID_B), verb: 'use')
		);
		$this->assertFalse(
			condition: $resolver->isGranted(lookup: new GrantLookup(userId: 'mallory', objectUuid: self::UUID_A), action: 'update')
		);
	}//end testAShareOnAnotherFolderWithTheObjectsNameGrantsNothing()

	/**
	 * A share naming an object that has no stored row grants nothing.
	 *
	 * @return void
	 */
	public function testAShareNamingNoStoredObjectGrantsNothing(): void {
		unset($this->folders[self::UUID_B]);
		$byType = [
			IShare::TYPE_USER => [$this->share(name: self::UUID_B, permissions: Constants::PERMISSION_READ, nodeId: 1002)],
		];

		$resolver = $this->resolver(sharesFor: static fn (int $type): array => ($byType[$type] ?? []));

		$this->assertSame(expected: [], actual: $resolver->grantedObjectUuids(userId: 'alice'));
	}//end testAShareNamingNoStoredObjectGrantsNothing()

	/**
	 * When the stored folders cannot be read, no share grants anything.
	 *
	 * @return void
	 */
	public function testUnreadableStoredFoldersFailClosed(): void {
		$byType  = [IShare::TYPE_USER => [$this->share(name: self::UUID_A, permissions: Constants::PERMISSION_ALL)]];
		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('getSharedWith')->willReturnCallback(
			static fn (string $userId, int $type): array => ($byType[$type] ?? [])
		);

		$objects = $this->createMock(originalClassName: MagicCrossTableReaderInterface::class);
		$objects->method('findFolderIdsByUuids')->willThrowException(new RuntimeException(message: 'db down'));
		$readers = ['throws' => $objects, 'wrongType' => new \stdClass()];

		foreach ($readers as $mode => $reader) {
			$this->logLines = [];
			$container      = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static fn (string $id) => match ($id) {
					IManager::class => $manager,
					default => $reader,
				}
			);

			$resolver = new ObjectGrantResolver(logger: $this->logger(), container: $container);
			$this->assertSame(expected: [], actual: $resolver->grantedObjectUuids(userId: 'alice'), message: $mode);
			$this->assertFalse(
				condition: $resolver->isGranted(lookup: new GrantLookup(userId: 'alice', objectUuid: self::UUID_A)),
				message: $mode
			);
			$this->assertSame(
				expected: ['error [ObjectGrantResolver] Could not read the stored object folders; treating the caller as holding no grants'],
				actual: $this->logLines,
				message: $mode
			);
		}
	}//end testUnreadableStoredFoldersFailClosed()
}//end class
