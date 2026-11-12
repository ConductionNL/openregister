<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\Federation\ShareTargets;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Share creation through the real service, grantor, targets, resolver and manage gate.
 *
 * Two registers each carry a schema with the slug `zaak`; only members of `managers-a`
 * manage register `a`'s. The doubles answer only for the acting user's own groups.
 *
 * @covers \OCA\OpenRegister\Service\FederationShareService
 * @covers \OCA\OpenRegister\Service\Federation\ShareGrantor
 * @covers \OCA\OpenRegister\Service\Federation\ShareTargets
 * @covers \OCA\OpenRegister\Service\Rbac\SchemaManageGate
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 */
class ShareCreationRightsChainTest extends TestCase {

	/** @var array<string, mixed>|null */
	private ?array $stored = null;

	private function schema(int $id, ?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setAuthorization($authorization);
		return $schema;
	}

	private function register(int $id, string $slug, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setSchemas($schemaIds);
		return $register;
	}

	private function service(string $uid, array $groups, bool $admin = false, ?ObjectService $objects = null): FederationShareService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $admin && $id === $uid);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $asked): array => ($asked->getUID() === $uid ? $groups : [])
		);

		$schemaA = $this->schema(id: 1, authorization: ['read' => ['staff'], 'manage' => ['managers-a']]);
		$schemaB = $this->schema(id: 2, authorization: ['read' => ['staff']]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findInIds')->willReturnCallback(
			static function (int|string $id, array $schemaIds) use ($schemaA, $schemaB): ?Schema {
				if ($id !== 'zaak') {
					return null;
				}

				return (in_array(1, $schemaIds, true) === true ? $schemaA : (in_array(2, $schemaIds, true) === true ? $schemaB : null));
			}
		);
		$schemas->method('find')->willReturn($schemaA);

		$registerA = $this->register(id: 10, slug: 'a', schemaIds: [1]);
		$registerB = $this->register(id: 20, slug: 'b', schemaIds: [2]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static fn (string|int $id): Register => ($id === 'a' ? $registerA : $registerB)
		);

		$org = new Organisation();
		$org->setUuid('org-a');
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturn($org);

		$grantor = new ShareGrantor(
			$session,
			$organisations,
			new SchemaManageGate($session, $groupManager, $schemas),
			new ShareTargets(
				new RegisterScopedSchemaResolver($registers, $schemas),
				($objects ?? $this->createMock(ObjectService::class))
			)
		);

		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('createFromArray')->willReturnCallback(function (array $data): FederatedShare {
			$this->stored = $data;
			return new FederatedShare();
		});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('t', 48));

		return new FederationShareService($mapper, $grantor, $random, $this->createMock(LoggerInterface::class));
	}

	private function assertRefused(FederationShareService $service, array $params): void {
		try {
			$service->createOutgoingShare(params: $params);
			$this->fail('the share was not refused');
		} catch (NotAuthorizedException $e) {
			$this->assertNull($this->stored, 'a refused share was stored');
		}
	}

	public function testAMemberWhoOnlyReadsCannotMintASchemaShare(): void {
		$service = $this->service(uid: 'alice', groups: ['staff']);

		$this->assertRefused($service, ['scope' => 'schema', 'register' => 'a', 'schema' => 'zaak', 'permissions' => 'read']);
		$this->assertRefused($service, ['scope' => 'schema', 'register' => 'a', 'schema' => 'zaak', 'permissions' => 'read-write']);
		$this->assertRefused($service, ['scope' => 'query', 'register' => 'a', 'schema' => 'zaak', 'queryFilter' => ['status' => 'open'], 'permissions' => 'read']);
	}

	public function testAMemberCannotMintAnObjectShareOfARecordTheyCannotRead(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new NotAuthorizedException(message: 'no read'));
		$service = $this->service(uid: 'alice', groups: ['staff'], objects: $objects);

		$this->assertRefused($service, ['scope' => 'object', 'register' => 'a', 'schema' => 'zaak', 'objectUri' => 'secret-1', 'permissions' => 'read']);
	}

	public function testReadingARecordDoesNotAllowAWriteShareOfIt(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(new \OCA\OpenRegister\Db\ObjectEntity());
		$service = $this->service(uid: 'alice', groups: ['staff'], objects: $objects);

		$this->assertRefused($service, ['scope' => 'object', 'register' => 'a', 'schema' => 'zaak', 'objectUri' => 'zaak-1', 'permissions' => 'read-write']);

		$service->createOutgoingShare(params: ['scope' => 'object', 'register' => 'a', 'schema' => 'zaak', 'objectUri' => 'zaak-1', 'permissions' => 'read']);
		$this->assertSame('alice', $this->stored['owner']);
	}

	public function testAManagerMintsTheShareAndIsRecorded(): void {
		$service = $this->service(uid: 'mira', groups: ['managers-a']);

		$service->createOutgoingShare(params: ['scope' => 'schema', 'register' => 'a', 'schema' => 'zaak', 'permissions' => 'read-write', 'owner' => 'mallory']);

		$this->assertSame('mira', $this->stored['owner']);
		$this->assertSame('org-a', $this->stored['organisation']);
	}

	public function testAManagerOfTheSameSlugInAnotherRegisterIsRefused(): void {
		$service = $this->service(uid: 'mira', groups: ['managers-a']);

		$this->assertRefused($service, ['scope' => 'schema', 'register' => 'b', 'schema' => 'zaak', 'permissions' => 'read']);
	}

	public function testAShareNamingNoRegisterIsRefusedEvenForAManager(): void {
		$service = $this->service(uid: 'mira', groups: ['managers-a']);

		$this->assertRefused($service, ['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read']);
	}

	public function testARegisterShareNeedsTheRegisterManageRight(): void {
		$this->assertRefused(
			$this->service(uid: 'mira', groups: ['managers-a']),
			['scope' => 'register', 'register' => 'a', 'permissions' => 'read']
		);

		$this->service(uid: 'root', groups: [], admin: true)
			->createOutgoingShare(params: ['scope' => 'register', 'register' => 'a', 'permissions' => 'read']);
		$this->assertSame('root', $this->stored['owner']);
	}
}
