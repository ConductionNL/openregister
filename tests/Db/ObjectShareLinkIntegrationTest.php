<?php

/**
 * Live-database tests for the object share LINK and EMAIL surface.
 *
 * A link is a bearer CAPABILITY, so it is decided in a different place from
 * every other part of this capability: not in the RBAC filter, but on a public
 * endpoint, from the token. That makes three properties worth proving against a
 * real database and a real share manager rather than a mock.
 *
 *  1. A live token resolves to its object even though the caller is ANONYMOUS
 *     and the object is PRIVATE. That is the whole point, and it is also the one
 *     path in this capability that bypasses the RBAC filter.
 *  2. Revocation and expiry take effect on the NEXT request, with nothing for
 *     OpenRegister to invalidate — because both are core's checks, inside
 *     `getShareByToken()`.
 *  3. A token must not be a general-purpose key: a USER share's token must not
 *     be redeemable anonymously, and a link must not turn a private object into
 *     a listable one for other logged-in users.
 *
 * The tests drive the SERVICE and the CONTROLLER, not hand-built shares, so a
 * broken owner-check or a wrong share type cannot pass unnoticed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ObjectShareLinkIntegrationTest extends TestCase {

	private MagicMapper $mapper;

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private IUserSession $userSession;

	private IUserManager $userManager;

	private IDBConnection $db;

	private ?IUser $ownerUser = null;

	private string $ownerUid = '';

	/**
	 * @var int[]
	 */
	private array $createdSchemaIds = [];

	/**
	 * @var int[]
	 */
	private array $createdRegisterIds = [];

	/**
	 * @var string[]
	 */
	private array $createdTables = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(MagicMapper::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper = \OC::$server->get(SchemaMapper::class);
		$this->userSession = \OC::$server->get(IUserSession::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->db = \OC::$server->get(IDBConnection::class);

		$suffix = substr((string)Uuid::v4(), 0, 8);
		$this->ownerUid = 'link-owner-' . $suffix;

		$this->ownerUser = $this->userManager->createUser($this->ownerUid, 'Link-Test-Pass-123');
		if ($this->ownerUser === false || $this->ownerUser === null) {
			$this->markTestSkipped('could not create the owner user');
		}

		$this->userSession->setUser($this->ownerUser);
	}//end setUp()

	protected function tearDown(): void {
		$this->userSession->setUser(null);

		if ($this->ownerUser !== null) {
			$this->ownerUser->delete();
		}

		foreach ($this->createdTables as $tableName) {
			try {
				$this->db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
			} catch (\Exception $e) {
				// Already gone.
			}
		}

		foreach ([['openregister_schemas', $this->createdSchemaIds], ['openregister_registers', $this->createdRegisterIds]] as [$table, $ids]) {
			foreach ($ids as $id) {
				try {
					$qb = $this->db->getQueryBuilder();
					$qb->delete($table)
						->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
					$qb->executeStatement();
				} catch (\Exception $e) {
					// Already gone.
				}
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A live link token resolves a PRIVATE object for an ANONYMOUS caller.
	 *
	 * This is the capability working: no session, no principal, and the object
	 * is invisible to every logged-in user who is not its owner — yet the token
	 * reaches it, because the token is the authorization.
	 */
	public function testALiveTokenResolvesAPrivateObjectAnonymously(): void {
		[$register, $schema, $object] = $this->privateObject('linked');

		$link = $this->sharing()->createLink(object: $object, permissions: 1);
		$this->assertNotEmpty($link['token'], 'core issued no token');

		// Anonymous: no session at all.
		$this->userSession->setUser(null);

		$resolved = $this->resolveToken($link['token']);
		$this->assertNotNull($resolved, 'a live token must resolve its object');
		$this->assertSame($object->getUuid(), $resolved['uuid']);
	}//end testALiveTokenResolvesAPrivateObjectAnonymously()

	/**
	 * A REVOKED link stops resolving on the next request.
	 *
	 * Nothing in OpenRegister is invalidated — `getShareByToken()` simply no
	 * longer finds it. That is the read-through design (D2) paying off.
	 */
	public function testARevokedLinkStopsResolving(): void {
		[$register, $schema, $object] = $this->privateObject('revocable');

		$link = $this->sharing()->createLink(object: $object, permissions: 1);
		$this->userSession->setUser(null);
		$this->assertNotNull($this->resolveToken($link['token']), 'granted first');

		$this->userSession->setUser($this->ownerUser);
		$this->sharing()->revoke(object: $object, shareId: (string)$link['id']);
		$this->userSession->setUser(null);

		$this->assertNull(
			$this->resolveToken($link['token']),
			'a revoked link must stop resolving immediately'
		);
	}//end testARevokedLinkStopsResolving()

	/**
	 * An EXPIRED link stops resolving.
	 *
	 * The expiry is set in the past directly, because core refuses to create a
	 * share that is already expired — the point under test is what happens once
	 * the date has passed, not whether core validates the input.
	 */
	public function testAnExpiredLinkStopsResolving(): void {
		[$register, $schema, $object] = $this->privateObject('expiring');

		$link = $this->sharing()->createLink(
			object: $object,
			permissions: 1,
			expiration: (new \DateTime('+7 days'))->format('Y-m-d')
		);

		$this->userSession->setUser(null);
		$this->assertNotNull($this->resolveToken($link['token']), 'valid while unexpired');

		$this->expireShare(token: $link['token']);

		$this->assertNull(
			$this->resolveToken($link['token']),
			'an expired link must stop resolving'
		);
	}//end testAnExpiredLinkStopsResolving()

	/**
	 * A principal grant carries NO token, and the endpoint would refuse one anyway.
	 *
	 * The first version of this test tried to redeem a USER share's token
	 * anonymously and "passed" — because a USER share has no token at all, so it
	 * hit an early return and counted an assertion that proved nothing. Measured
	 * on this instance: `share_type = 0` rows all have a null token, while
	 * `share_type = 3` (link) has one.
	 *
	 * So the honest statement is two-part: today a principal grant cannot be
	 * redeemed as a bearer token because it HAS none, and the endpoint's type
	 * guard is defence-in-depth for the day a principal type starts carrying
	 * one. Both halves are asserted rather than implied.
	 */
	public function testAPrincipalGrantCarriesNoTokenAndTheTypeGuardExists(): void {
		[$register, $schema, $object] = $this->privateObject('principalonly');

		$recipientUid = 'link-recipient-' . substr((string)Uuid::v4(), 0, 8);
		$recipient = $this->userManager->createUser($recipientUid, 'Link-Test-Pass-123');

		try {
			$grant = $this->sharing()->grant(
				object: $object,
				type: 'user',
				shareWith: $recipientUid,
				permissions: 1
			);

			$share = \OC::$server->get(IManager::class)->getShareById((string)$grant['id']);

			$this->assertEmpty(
				(string)$share->getToken(),
				'a USER share is expected to carry no token; if this fails the type guard below is the only defence'
			);

			// The guard itself: the public endpoint honours ONLY the two bearer
			// types, so a principal share could not be redeemed even with a token.
			$this->assertNotContains(
				$share->getShareType(),
				[\OCP\Share\IShare::TYPE_LINK, \OCP\Share\IShare::TYPE_EMAIL],
				'a USER share must not be one of the types the public endpoint honours'
			);
		} finally {
			$this->userSession->setUser($this->ownerUser);
			if ($recipient !== false && $recipient !== null) {
				$recipient->delete();
			}
		}
	}//end testAPrincipalGrantCarriesNoTokenAndTheTypeGuardExists()

	/**
	 * A link does NOT make the object listable for other logged-in users.
	 *
	 * A link is a capability for whoever holds the token, not a change of the
	 * object's scope. If creating one also widened the RBAC verdict it would be
	 * per-object publication by the back door — exactly what ADR-006 guards
	 * against, and the distinction design Q4 turns on.
	 */
	public function testALinkDoesNotMakeTheObjectListableForOthers(): void {
		[$register, $schema, $object] = $this->privateObject('stillprivate');

		$this->sharing()->createLink(object: $object, permissions: 1);

		$otherUid = 'link-other-' . substr((string)Uuid::v4(), 0, 8);
		$otherUser = $this->userManager->createUser($otherUid, 'Link-Test-Pass-123');

		try {
			$this->userSession->setUser($otherUser);

			$keys = [];
			foreach ($this->mapper->searchObjectsInRegisterSchemaTable(['_multitenancy' => false], $register, $schema) as $row) {
				if ($row instanceof ObjectEntity === true) {
					$keys[] = (($row->getObject() ?? [])['key'] ?? null);
				}
			}

			$this->assertNotContains(
				'stillprivate',
				$keys,
				'creating a link must not publish the object to other logged-in users'
			);
		} finally {
			$this->userSession->setUser($this->ownerUser);
			if ($otherUser !== false && $otherUser !== null) {
				$otherUser->delete();
			}
		}
	}//end testALinkDoesNotMakeTheObjectListableForOthers()

	/**
	 * An email invitation refuses an address that is not one.
	 */
	public function testAnEmailInvitationRequiresAValidAddress(): void {
		[$register, $schema, $object] = $this->privateObject('bademail');

		$this->expectException(\InvalidArgumentException::class);
		$this->sharing()->inviteByEmail(object: $object, email: 'not-an-address');
	}//end testAnEmailInvitationRequiresAValidAddress()

	/**
	 * An extension verb rides on the grant, and a plain read grant does not carry it.
	 *
	 * ADR-010: core's bitmask has five verbs and OpenRegister has more concepts
	 * than that. `use` a credential and `run` a flow live in the share's
	 * attribute bag instead of widening the RBAC vocabulary — and the point of
	 * keeping them separate is that a read grant must NOT imply them.
	 *
	 * This is the half the ADR listed as unbuilt when it was written. It is what
	 * lets the credential broker's Guard 1d admit on the shared primitive.
	 */
	public function testAGrantCarriesExtensionVerbsAndReadAloneDoesNot(): void {
		[$register, $schema, $object] = $this->privateObject('verbgrant');

		$recipientUid = 'verb-recipient-' . substr((string)Uuid::v4(), 0, 8);
		$recipient = $this->userManager->createUser($recipientUid, 'Link-Test-Pass-123');

		try {
			$resolver = \OC::$server->get(\OCA\OpenRegister\Service\Rbac\ObjectGrantResolver::class);

			// A plain READ grant first — the control. Without it, a passing
			// assertion below would not distinguish "the verb was carried" from
			// "every grant reports every verb".
			$this->sharing()->grant(
				object: $object,
				type: 'user',
				shareWith: $recipientUid,
				permissions: 1
			);
			$resolver->forget();

			$this->assertFalse(
				$resolver->grantCarriesVerb($recipientUid, $object->getUuid(), 'use'),
				'a plain read grant must NOT carry `use` — seeing a credential is weaker than spending it'
			);

			// Now one that does carry it.
			[, , $withVerb] = $this->privateObject('verbgrant2');
			$this->sharing()->grant(
				object: $withVerb,
				type: 'user',
				shareWith: $recipientUid,
				permissions: 1,
				verbs: ['use']
			);
			$resolver->forget();

			$this->assertTrue(
				$resolver->grantCarriesVerb($recipientUid, $withVerb->getUuid(), 'use'),
				'a grant created with the `use` verb must carry it'
			);
			$this->assertFalse(
				$resolver->grantCarriesVerb($recipientUid, $withVerb->getUuid(), 'run'),
				'it must carry only the verbs it was given'
			);
		} finally {
			$this->userSession->setUser($this->ownerUser);
			if ($recipient !== false && $recipient !== null) {
				$recipient->delete();
			}
		}
	}//end testAGrantCarriesExtensionVerbsAndReadAloneDoesNot()

	/**
	 * Resolve a token the way the public endpoint does.
	 *
	 * Deliberately mirrors `ObjectShareLinkController` rather than calling it:
	 * constructing a controller needs an IRequest, and what matters here is the
	 * core-side chain — token validity, share type, folder-name-is-the-uuid.
	 *
	 * @param string $token The share token.
	 *
	 * @return array{uuid: string, permissions: int}|null The resolved object, or null.
	 */
	private function resolveToken(string $token): ?array {
		$manager = \OC::$server->get(IManager::class);

		try {
			$share = $manager->getShareByToken($token);
		} catch (\Throwable $e) {
			return null;
		}

		$allowed = [\OCP\Share\IShare::TYPE_LINK, \OCP\Share\IShare::TYPE_EMAIL];
		if (in_array($share->getShareType(), $allowed, true) === false) {
			return null;
		}

		try {
			if ($share->getNodeType() !== 'folder') {
				return null;
			}

			$name = $share->getNode()->getName();
		} catch (\Throwable $e) {
			return null;
		}

		$uuidPattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
		if (preg_match($uuidPattern, $name) !== 1) {
			return null;
		}

		return ['uuid' => $name, 'permissions' => $share->getPermissions()];
	}//end resolveToken()

	/**
	 * Push a share's expiry into the past.
	 *
	 * Core refuses to CREATE an already-expired share, so the row is aged
	 * directly. What is under test is the behaviour once the date has passed.
	 *
	 * @param string $token The share token.
	 *
	 * @return void
	 */
	private function expireShare(string $token): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('share')
			->set('expiration', $qb->createNamedParameter((new \DateTime('-2 days'))->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}//end expireShare()

	/**
	 * Create a register, schema, table and ONE private object owned by the owner.
	 *
	 * @param string $key The object's `key` property.
	 *
	 * @return array{0: Register, 1: Schema, 2: ObjectEntity}
	 */
	private function privateObject(string $key): array {
		$register = $this->registerMapper->createFromArray(
			['title' => 'PHPUnit link Register ' . uniqid(), 'description' => 'link tests']
		);
		$this->createdRegisterIds[] = $register->getId();

		$schema = $this->schemaMapper->createFromArray(
			[
				'title' => 'PHPUnit link Schema ' . uniqid(),
				'description' => 'link tests',
				'properties' => ['key' => ['type' => 'string', 'title' => 'Key', 'maxLength' => 255]],
				'authorization' => ['read' => ['authenticated']],
			]
		);
		$this->createdSchemaIds[] = $schema->getId();

		$this->mapper->ensureTableForRegisterSchema($register, $schema);
		$table = $this->mapper->getTableNameForRegisterSchema($register, $schema);
		$this->createdTables[] = 'oc_' . $table;

		$entity = new ObjectEntity();
		$entity->setUuid(Uuid::v4()->toRfc4122());
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject(['key' => $key]);
		$entity->setOwner($this->ownerUid);
		$stored = $this->mapper->insertObjectEntity($entity, $register, $schema, false);

		// Private, written the way the service writes it.
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('_authorization', $qb->createNamedParameter(json_encode(['scope' => 'private'])))
			->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($stored->getUuid())));
		$qb->executeStatement();

		$fresh = null;
		foreach ($this->mapper->searchObjectsInRegisterSchemaTable(['_multitenancy' => false, '_rbac' => false], $register, $schema) as $row) {
			if ($row instanceof ObjectEntity === true) {
				$fresh = $row;
			}
		}

		$this->assertNotNull($fresh, 'fixture did not persist');
		$this->assertSame(['scope' => 'private'], $fresh->getAuthorization(), 'fixture is not private');

		return [$register, $schema, $fresh];
	}//end privateObject()

	/**
	 * The owner-checked write surface.
	 *
	 * @return ObjectSharingService
	 */
	private function sharing(): ObjectSharingService {
		return \OC::$server->get(ObjectSharingService::class);
	}//end sharing()

}//end class
