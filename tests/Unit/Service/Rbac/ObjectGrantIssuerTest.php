<?php

/**
 * ObjectGrantResolver: the issuer of a grant holds the access they issued, until it is revoked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-the-issuer-of-a-grant-holds-the-access-they-issued-from
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCA\OpenRegister\Service\Rbac\GrantLookup;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCP\Constants;
use OCP\Files\Node;
use OCP\Share\IAttributes;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The issuer's access is read from the grant they issued and from nothing
 * else, through the same stored-folder check as a received grant.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver
 * @uses   \OCA\OpenRegister\Formats\UuidFormat
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionBit
 * @uses   \OCA\OpenRegister\Service\Rbac\ShareGrantAttributes
 * @uses   \OCA\OpenRegister\Service\Rbac\ShareGrantRun
 * @uses   \OCA\OpenRegister\Service\Rbac\StoredFolderShares
 */
class ObjectGrantIssuerTest extends TestCase {

	private const CASE_A = '0b8f6a0e-6f5e-4c3e-9a51-7a4f0b2c9d11';

	private const CASE_B = '6d1c2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';

	/**
	 * Folder id per stored object.
	 *
	 * @var array<string, int>
	 */
	private array $folders = [self::CASE_A => 101, self::CASE_B => 202];

	/**
	 * The calls to getSharesBy, as "userId:type:reshares".
	 *
	 * @var array<int, string>
	 */
	private array $issuedCalls = [];

	/**
	 * A grant on an object folder.
	 *
	 * @param string             $uuid        The object.
	 * @param int                $permissions The core permissions.
	 * @param array<int, string> $verbs       Extension verbs.
	 * @param int|null           $nodeId      The shared node, the stored folder when null.
	 *
	 * @return IShare
	 */
	private function grant(string $uuid, int $permissions, array $verbs=[], ?int $nodeId=null): IShare {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($uuid);
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturnCallback(
			static fn (string $scope, string $key): mixed => ($key === 'verbs' ? $verbs : null)
		);

		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_USER);
		$share->method('getNodeType')->willReturn('folder');
		$share->method('getNode')->willReturn($node);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getAttributes')->willReturn($attributes);
		$share->method('getNodeId')->willReturn($nodeId ?? $this->folders[$uuid]);

		return $share;
	}//end grant()

	/**
	 * A resolver whose share manager answers received and issued grants per user.
	 *
	 * @param array<string, array<int, IShare>> $received User shares received (user type only).
	 * @param array<string, array<int, IShare>> $issued   User shares issued (user type only).
	 *
	 * @return ObjectGrantResolver
	 */
	private function resolver(array &$received, array &$issued): ObjectGrantResolver {
		$calls = &$this->issuedCalls;
		$manager = $this->createMock(IManager::class);
		$manager->method('getSharedWith')->willReturnCallback(
			static function (string $userId, int $type, $node, int $limit, int $offset) use (&$received): array {
				return ($type === IShare::TYPE_USER && $offset === 0) ? ($received[$userId] ?? []) : [];
			}
		);
		$manager->method('getSharesBy')->willReturnCallback(
			static function (string $userId, int $type, $node, bool $reshares, int $limit, int $offset) use (&$issued, &$calls): array {
				$calls[] = $userId . ':' . $type . ':' . var_export($reshares, true);
				return ($type === IShare::TYPE_USER && $offset === 0) ? ($issued[$userId] ?? []) : [];
			}
		);

		$folders = $this->folders;
		$objects = $this->createMock(MagicCrossTableReaderInterface::class);
		$objects->method('findFolderIdsByUuids')->willReturnCallback(
			static fn (array $uuids): array => array_intersect_key($folders, array_flip($uuids))
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === IManager::class ? $manager : $objects)
		);

		return new ObjectGrantResolver(new NullLogger(), $container);
	}//end resolver()

	/**
	 * The issuer reads the object through the grant they issued, with its verbs, and nothing more.
	 *
	 * @return void
	 */
	public function testTheIssuerHoldsWhatTheGrantConveys(): void {
		$received = [];
		$issued = ['anouk' => [$this->grant(self::CASE_A, Constants::PERMISSION_READ, ['comment'])]];
		$resolver = $this->resolver($received, $issued);

		$this->assertSame([self::CASE_A => Constants::PERMISSION_READ], $resolver->grantedObjectUuids('anouk'));
		$this->assertTrue($resolver->isGranted(new GrantLookup('anouk', self::CASE_A), 'read'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('anouk', self::CASE_A), 'update'));
		$this->assertTrue($resolver->grantCarriesVerb(new GrantLookup('anouk', self::CASE_A), 'comment'));
		$this->assertFalse($resolver->isGranted(new GrantLookup('anouk', self::CASE_B), 'read'));
	}//end testTheIssuerHoldsWhatTheGrantConveys()

	/**
	 * Only shares the caller initiated count: reshares by others are asked out.
	 *
	 * @return void
	 */
	public function testOnlySharesTheCallerInitiatedAreRead(): void {
		$received = [];
		$issued = [];
		$this->resolver($received, $issued)->grantedObjectUuids('anouk');

		$this->assertSame(
			['anouk:' . IShare::TYPE_USER . ':false', 'anouk:' . IShare::TYPE_GROUP . ':false', 'anouk:' . IShare::TYPE_REMOTE . ':false', 'anouk:' . IShare::TYPE_REMOTE_GROUP . ':false'],
			$this->issuedCalls
		);
	}//end testOnlySharesTheCallerInitiatedAreRead()

	/**
	 * Revoking the grant revokes the issuer's derived access with it.
	 *
	 * @return void
	 */
	public function testRevokingTheGrantRevokesTheIssuersAccess(): void {
		$received = [];
		$issued = ['anouk' => [$this->grant(self::CASE_A, Constants::PERMISSION_READ)]];
		$resolver = $this->resolver($received, $issued);
		$this->assertTrue($resolver->isGranted(new GrantLookup('anouk', self::CASE_A), 'read'));

		$issued['anouk'] = [];
		$resolver->forget('anouk');

		$this->assertFalse($resolver->isGranted(new GrantLookup('anouk', self::CASE_A), 'read'));
		$this->assertSame([], $resolver->grantedObjectUuids('anouk'));
	}//end testRevokingTheGrantRevokesTheIssuersAccess()

	/**
	 * An issued share that is not on the object's own stored folder grants nothing.
	 *
	 * @return void
	 */
	public function testAnIssuedShareOffTheStoredFolderGrantsNothing(): void {
		$received = [];
		$issued = ['anouk' => [$this->grant(self::CASE_A, Constants::PERMISSION_ALL, [], 999)]];

		$this->assertSame([], $this->resolver($received, $issued)->grantedObjectUuids('anouk'));
	}//end testAnIssuedShareOffTheStoredFolderGrantsNothing()

	/**
	 * Received and issued grants on one object compose widest-wins.
	 *
	 * @return void
	 */
	public function testReceivedAndIssuedGrantsCompose(): void {
		$received = ['anouk' => [$this->grant(self::CASE_A, Constants::PERMISSION_UPDATE)]];
		$issued = ['anouk' => [$this->grant(self::CASE_A, Constants::PERMISSION_READ), $this->grant(self::CASE_B, Constants::PERMISSION_READ)]];

		$this->assertSame(
			[self::CASE_A => (Constants::PERMISSION_UPDATE | Constants::PERMISSION_READ), self::CASE_B => Constants::PERMISSION_READ],
			$this->resolver($received, $issued)->grantedObjectUuids('anouk')
		);
	}//end testReceivedAndIssuedGrantsCompose()
}//end class
