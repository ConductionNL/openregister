<?php

/**
 * Unit tests for the reach listing.
 *
 * Covers the spec delta's "uitdiensttreding is one act": a principal holding
 * grants from four sources has all four listed with where each comes from.
 * Also covers the property the revocation depends on, that a group-sourced
 * reach is listed as NOT revocable, so an administrator sees the half the act
 * will leave standing before they run it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\PrincipalReach;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class PrincipalReachServiceTest extends TestCase {
	private const PRINCIPAL = 'anja';

	/**
	 * A schema the cascade can be asked about.
	 *
	 * @param int    $id    The schema id.
	 * @param string $title The schema title.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, string $title): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle($title);

		return $schema;
	}//end schema()

	/**
	 * A live delegation held by the principal.
	 *
	 * @param string $uuid The grant uuid.
	 * @param bool   $live Whether it still answers.
	 *
	 * @return DelegationGrant The grant.
	 */
	private function delegation(string $uuid, bool $live = true): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setUuid($uuid);
		$grant->setActingAs('bram');
		$grant->setScope(['read']);
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setExpiresAt(new DateTime($live === true ? '+30 days' : '-1 day'));

		return $grant;
	}//end delegation()

	/**
	 * Assemble the service over staged authorities.
	 *
	 * @param array<int, Schema>        $schemas     The schemas.
	 * @param array<int, string>        $actions     Verbs the principal holds on each.
	 * @param array<int, string>        $groups      Group memberships.
	 * @param array<int, array>         $derived     Derived grants.
	 * @param array<int, DelegationGrant> $delegated The delegations held.
	 *
	 * @return PrincipalReachService The assembled service.
	 */
	private function service(
		array $schemas = [],
		array $actions = [],
		array $groups = [],
		array $derived = [],
		array $delegated = [],
	): PrincipalReachService {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('permittedActionsFor')->willReturn($actions);
		$permissions->method('provenanceFor')->willReturnCallback(
			static function (Schema $schema, array $verbs): array {
				$out = [];
				foreach ($verbs as $verb) {
					$out[$verb] = ['level' => 'schema', 'matched' => 'redacteuren'];
				}

				return $out;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn($schemas);

		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn($derived);

		$delegations = $this->createMock(DelegationGrantMapper::class);
		$delegations->method('findHeldBy')->willReturn($delegated);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::PRINCIPAL);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$userManager->method('userExists')->willReturn(true);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new PrincipalReachService(
			$permissions,
			$schemaMapper,
			$store,
			$delegations,
			$groupManager,
			$userManager,
			new NullLogger()
		);
	}//end service()

	public function testUitdiensttredingListsAllFourSourcesWithWhereEachComesFrom(): void {
		$service = $this->service(
			schemas: [$this->schema(1, 'Zaak')],
			actions: ['read'],
			groups: ['redacteuren'],
			derived: [['register' => 'zaken', 'action' => 'read']],
			delegated: [$this->delegation('deleg-1')]
		);

		$listing = $service->listFor(self::PRINCIPAL);

		$sources = array_column($listing['entries'], 'source');
		foreach (PrincipalReach::SOURCES as $expected) {
			self::assertContains($expected, $sources, 'source ' . $expected . ' is missing from the listing');
		}

		self::assertSame(4, $listing['total']);
		self::assertSame(1, $listing['bySource'][PrincipalReach::SOURCE_AUTHORIZATION]);
		self::assertSame(1, $listing['bySource'][PrincipalReach::SOURCE_GROUP]);
		self::assertSame(1, $listing['bySource'][PrincipalReach::SOURCE_DERIVED]);
		self::assertSame(1, $listing['bySource'][PrincipalReach::SOURCE_DELEGATION]);

		// WHERE IT COMES FROM, not just that it exists. An administrator told
		// they may act and not why cannot tell which rule to change.
		$authorization = array_values(
			array_filter(
				$listing['entries'],
				static fn (array $e): bool => $e['source'] === PrincipalReach::SOURCE_AUTHORIZATION
			)
		);
		self::assertSame('schema', $authorization[0]['grantedBy']['level']);
		self::assertSame('redacteuren', $authorization[0]['grantedBy']['matched']);
	}//end testUitdiensttredingListsAllFourSourcesWithWhereEachComesFrom()

	public function testAGroupSourcedReachIsListedButNotRevocable(): void {
		$service = $this->service(groups: ['redacteuren', 'behandelaren']);

		$listing = $service->listFor(self::PRINCIPAL);

		self::assertSame(2, $listing['total']);
		self::assertSame(0, $listing['revocable']);
		// RETAINED IS THE HALF THE ACT WILL NOT TAKE. Reporting it as revocable
		// would tell an administrator the access is gone when it is not.
		self::assertSame(2, $listing['retained']);
		foreach ($listing['entries'] as $entry) {
			self::assertFalse($entry['revocable']);
		}
	}//end testAGroupSourcedReachIsListedButNotRevocable()

	public function testALapsedDelegationIsNotReach(): void {
		$service = $this->service(delegated: [$this->delegation('gone', false)]);

		$listing = $service->listFor(self::PRINCIPAL);

		// Counting a delegation that already lapsed would inflate the number an
		// administrator acts on, and make the revocation report a removal that
		// removed nothing.
		self::assertSame(0, $listing['total']);
	}//end testALapsedDelegationIsNotReach()

	public function testASchemaTheCascadeCannotAnswerIsSkippedNotCountedAsReach(): void {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('permittedActionsFor')->willThrowException(new RuntimeException('cascade unreadable'));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$this->schema(1, 'Zaak')]);

		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn([]);

		$delegations = $this->createMock(DelegationGrantMapper::class);
		$delegations->method('findHeldBy')->willReturn([]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);
		$userManager->method('userExists')->willReturn(false);

		$service = new PrincipalReachService(
			$permissions,
			$schemaMapper,
			$store,
			$delegations,
			$this->createMock(IGroupManager::class),
			$userManager,
			new NullLogger()
		);

		$listing = $service->listFor(self::PRINCIPAL);

		self::assertSame(0, $listing['total']);
		self::assertFalse($listing['exists']);
	}//end testASchemaTheCascadeCannotAnswerIsSkippedNotCountedAsReach()

	public function testEverySourceIsAMemberOfTheVocabulary(): void {
		$service = $this->service(
			schemas: [$this->schema(1, 'Zaak')],
			actions: ['read', 'update'],
			groups: ['redacteuren'],
			derived: [['register' => 'zaken']],
			delegated: [$this->delegation('deleg-1')]
		);

		foreach ($service->listFor(self::PRINCIPAL)['entries'] as $entry) {
			self::assertTrue(
				PrincipalReach::isMember($entry['source']),
				'a source outside the vocabulary is a source nobody can revoke'
			);
		}
	}//end testEverySourceIsAMemberOfTheVocabulary()
}//end class
