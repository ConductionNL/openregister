<?php

/**
 * Live-database tests for who may share an object, and in whose name.
 *
 * An object's folder lives in the openregister account's home. A grant or a
 * link is still created by the user who asks for it, so the share shows in
 * their name and they can manage it. These tests pin both halves against a
 * real share manager: the outcomes that must be refused, and the ones that
 * must work, with the refused ones first so a fix cannot buy the allowed
 * outcomes by widening access.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\SharerAccessSweepJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\ShareGrantAttributes;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ObjectSharerAccessIntegrationTest extends TestCase {

	private const PASSWORD = 'Sharer-Test-Pass-123';

	private MagicMapper $mapper;

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private IUserSession $userSession;

	private IUserManager $userManager;

	private IGroupManager $groupManager;

	private IDBConnection $db;

	/**
	 * @var array<string, IUser> Users made for this test, by role.
	 */
	private array $users = [];

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
		$this->groupManager = \OC::$server->get(IGroupManager::class);
		$this->db = \OC::$server->get(IDBConnection::class);

		$suffix = substr((string)Uuid::v4(), 0, 8);
		foreach (['owner', 'recipient', 'stranger', 'successor', 'admin'] as $role) {
			$user = $this->userManager->createUser('sharer-' . $role . '-' . $suffix, self::PASSWORD);
			if ($user === false || $user === null) {
				$this->markTestSkipped('could not create the ' . $role . ' user');
			}

			$this->users[$role] = $user;
		}

		$this->groupManager->get('admin')?->addUser($this->users['admin']);

		$this->as('owner');
	}//end setUp()

	protected function tearDown(): void {
		$this->userSession->setUser(null);

		foreach ($this->users as $user) {
			$user->delete();
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
	 * Someone who cannot reach the object can neither grant it nor link it.
	 *
	 * @return void
	 */
	public function testAUserWithoutObjectAccessCannotGrantOrLink(): void {
		[, , $object] = $this->object(key: 'stranger', private: true);

		$this->as('stranger');
		$this->assertRefused(fn () => $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('recipient')));
		$this->assertRefused(fn () => $this->sharing()->createLink(object: $object));

		$this->assertSame([], $this->sharesTo(role: 'recipient', object: $object), 'a refused grant must leave no share behind');
		$this->assertSame([], $this->sharesTo(role: 'stranger', object: $object), 'a refused caller must get no view of the folder');
	}//end testAUserWithoutObjectAccessCannotGrantOrLink()

	/**
	 * A reader who is not the owner may read the object and still not share it, at any level.
	 *
	 * @return void
	 */
	public function testAReaderWhoMayNotShareCannotGrantMoreOrLink(): void {
		[$register, $schema, $object] = $this->object(key: 'readable', private: false);

		$this->as('recipient');
		$this->assertNotNull(
			$this->findAsCaller(register: $register, schema: $schema, object: $object),
			'control: the reader must really read the object, or the refusals below prove nothing'
		);

		$this->assertRefused(fn () => $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('stranger'), permissions: Constants::PERMISSION_READ));
		$this->assertRefused(fn () => $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('stranger'), permissions: 15));
		$this->assertRefused(fn () => $this->sharing()->createLink(object: $object));

		$this->assertSame([], $this->sharesTo(role: 'stranger', object: $object));
		$this->assertSame([], $this->sharesTo(role: 'recipient', object: $object), 'a refused reader must get no view of the folder');
	}//end testAReaderWhoMayNotShareCannotGrantMoreOrLink()

	/**
	 * A former owner may no longer share the object.
	 *
	 * @return void
	 */
	public function testAFormerOwnerCannotShare(): void {
		[$register, $schema, $object] = $this->object(key: 'handed-over', private: true);
		$object = $this->setOwnerDirectly(register: $register, schema: $schema, object: $object, role: 'successor');

		$this->as('owner');
		$this->assertRefused(fn () => $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('recipient')));
		$this->assertRefused(fn () => $this->sharing()->createLink(object: $object));
		$this->assertSame([], $this->sharesTo(role: 'owner', object: $object));
	}//end testAFormerOwnerCannotShare()

	/**
	 * The owner grants in their own name, the recipient is admitted, and the owner can revoke.
	 *
	 * @return void
	 */
	public function testTheOwnerGrantsInTheirOwnNameAndCanRevoke(): void {
		[$register, $schema, $object] = $this->object(key: 'granted', private: true);

		$grant = $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('recipient'), permissions: Constants::PERMISSION_READ);

		$this->assertSame($this->uid('owner'), $grant['sharedBy'], 'the share must be in the name of the user who asked');
		$stored = $this->shareManager()->getShareById((string)$grant['id']);
		$this->assertSame($this->uid('owner'), $stored->getSharedBy(), 'the persisted share must name the asking user as sharer');
		$this->assertContains((string)$grant['id'], array_column($this->sharing()->listGrants(object: $object), 'id'));

		$this->as('recipient');
		$this->assertNotNull($this->findAsCaller(register: $register, schema: $schema, object: $object), 'the grant must admit the recipient');

		$this->as('owner');
		$this->sharing()->revoke(object: $object, shareId: (string)$grant['id']);

		$this->as('recipient');
		$this->assertNull($this->findAsCaller(register: $register, schema: $schema, object: $object), 'a revoked grant must deny');
	}//end testTheOwnerGrantsInTheirOwnNameAndCanRevoke()

	/**
	 * The owner creates a link in their own name and can revoke it.
	 *
	 * @return void
	 */
	public function testTheOwnerLinksInTheirOwnNameAndCanRevoke(): void {
		[, , $object] = $this->object(key: 'linked', private: true);

		$link = $this->sharing()->createLink(object: $object);
		$stored = $this->shareManager()->getShareByToken((string)$link['token']);
		$this->assertSame($this->uid('owner'), $stored->getSharedBy(), 'the link must be in the name of the user who asked');

		$this->sharing()->revoke(object: $object, shareId: (string)$link['id']);

		$this->expectException(\OCP\Share\Exceptions\ShareNotFound::class);
		$this->shareManager()->getShareByToken((string)$link['token']);
	}//end testTheOwnerLinksInTheirOwnNameAndCanRevoke()

	/**
	 * The sharer's own view of the folder carries their file rights and nothing more, and is no grant.
	 *
	 * @return void
	 */
	public function testTheSharersViewIsNoGrantAndCarriesNoMoreThanTheirRights(): void {
		[, , $object] = $this->object(key: 'viewed', private: true);

		$this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('recipient'), permissions: Constants::PERMISSION_READ);

		$views = $this->sharesTo(role: 'owner', object: $object);
		$this->assertCount(1, $views, 'the sharer holds exactly one view of the folder');
		$view = $views[0];
		$this->assertTrue((new ShareGrantAttributes())->isSharerAccess(share: $view), 'the view is marked as a sharer view');
		$this->assertSame(
			(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE),
			$view->getPermissions(),
			'the owner may read and change the files, and may re-share only through OpenRegister'
		);

		$this->assertNotContains(
			$view->getFullId(),
			array_column($this->sharing()->listGrants(object: $object), 'id'),
			'the sharer view must not be listed as a grant'
		);

		$resolver = \OC::$server->get(ObjectGrantResolver::class);
		$resolver->forget();
		$this->assertNotContains(
			$object->getUuid(),
			array_keys($resolver->grantedObjectUuids(userId: $this->uid('owner'))),
			'the sharer view must not admit anybody as an object grant'
		);
	}//end testTheSharersViewIsNoGrantAndCarriesNoMoreThanTheirRights()

	/**
	 * Handing the object over ends the former owner's view, and the grants they made end with it.
	 *
	 * @return void
	 */
	public function testHandingTheObjectOverEndsTheFormerOwnersView(): void {
		[$register, $schema, $object] = $this->object(key: 'transferred', private: true);

		$grant = $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('recipient'), permissions: Constants::PERMISSION_READ);
		$this->assertCount(1, $this->sharesTo(role: 'owner', object: $object), 'control: the owner held a view');

		$this->as('admin');
		\OC::$server->get(ObjectOwnershipService::class)->assign(
			register: $register,
			schema: $schema,
			object: $object,
			newOwner: $this->uid('successor')
		);

		$this->assertSame([], $this->sharesTo(role: 'owner', object: $object), 'the former owner must lose their view at once');

		$this->as('owner');
		$this->assertRefused(fn () => $this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('stranger')));

		$this->as('successor');
		$moved = $this->freshObject(register: $register, schema: $schema, object: $object);
		$this->assertNotContains(
			(string)$grant['id'],
			array_column($this->sharing()->listGrants(object: $moved), 'id'),
			'a grant is made in its sharer\'s name and must end with their right to share'
		);
	}//end testHandingTheObjectOverEndsTheFormerOwnersView()

	/**
	 * The sweep ends a view whose holder lost the object by a path that did not end it at once.
	 *
	 * @return void
	 */
	public function testTheSweepEndsAViewWhoseHolderMayNoLongerShare(): void {
		[$register, $schema, $object] = $this->object(key: 'swept', private: true);

		$this->sharing()->grant(object: $object, type: 'user', shareWith: $this->uid('recipient'), permissions: Constants::PERMISSION_READ);
		$this->assertCount(1, $this->sharesTo(role: 'owner', object: $object), 'control: the owner held a view');

		$this->setOwnerDirectly(register: $register, schema: $schema, object: $object, role: 'successor');
		$this->assertCount(1, $this->sharesTo(role: 'owner', object: $object), 'control: a direct write does not end the view by itself');

		$this->userSession->setUser(null);
		\OC::$server->get(SharerAccessSweepJob::class)->sweep();

		$this->assertSame([], $this->sharesTo(role: 'owner', object: $object), 'the sweep must end the view');
	}//end testTheSweepEndsAViewWhoseHolderMayNoLongerShare()

	/**
	 * Assert that the work is refused as not authorized.
	 *
	 * @param callable $work The sharing call.
	 *
	 * @return void
	 */
	private function assertRefused(callable $work): void {
		try {
			$work();
		} catch (NotAuthorizedException $e) {
			$this->addToAssertionCount(1);
			return;
		}

		$this->fail('the call was allowed and must have been refused');
	}//end assertRefused()

	/**
	 * The user shares on the object's folder addressed to one test user.
	 *
	 * @param string       $role   The test user's role.
	 * @param ObjectEntity $object The object.
	 *
	 * @return IShare[]
	 */
	private function sharesTo(string $role, ObjectEntity $object): array {
		$found = [];
		foreach ($this->shareManager()->getSharedWith($this->uid($role), IShare::TYPE_USER, null, -1) as $share) {
			try {
				if ($share->getNode()->getName() === $object->getUuid()) {
					$found[] = $share;
				}
			} catch (\Throwable $e) {
				// A share whose node is gone names no object.
			}
		}

		return $found;
	}//end sharesTo()

	/**
	 * Switch the session to one test user.
	 *
	 * @param string $role The role.
	 *
	 * @return void
	 */
	private function as(string $role): void {
		$this->userSession->setUser($this->users[$role]);
	}//end as()

	/**
	 * The uid of one test user.
	 *
	 * @param string $role The role.
	 *
	 * @return string
	 */
	private function uid(string $role): string {
		return $this->users[$role]->getUID();
	}//end uid()

	/**
	 * The object as the session caller may read it, or null.
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 *
	 * @return ObjectEntity|null
	 */
	private function findAsCaller(Register $register, Schema $schema, ObjectEntity $object): ?ObjectEntity {
		\OC::$server->get(ObjectGrantResolver::class)->forget();
		try {
			return $this->mapper->find((string)$object->getUuid(), $register, $schema, false, true, false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end findAsCaller()

	/**
	 * The object as stored, read without RBAC.
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 *
	 * @return ObjectEntity
	 */
	private function freshObject(Register $register, Schema $schema, ObjectEntity $object): ObjectEntity {
		return $this->mapper->find((string)$object->getUuid(), $register, $schema, false, false, false);
	}//end freshObject()

	/**
	 * Write a new owner straight to the row, as a path outside the ownership service would.
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 * @param string       $role     The new owner's role.
	 *
	 * @return ObjectEntity The object as stored afterwards.
	 */
	private function setOwnerDirectly(Register $register, Schema $schema, ObjectEntity $object, string $role): ObjectEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->mapper->getTableNameForRegisterSchema($register, $schema))
			->set('_owner', $qb->createNamedParameter($this->uid($role)))
			->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($object->getUuid())));
		$qb->executeStatement();

		$fresh = $this->freshObject(register: $register, schema: $schema, object: $object);
		$this->assertSame($this->uid($role), $fresh->getOwner(), 'fixture: the owner did not change');

		return $fresh;
	}//end setOwnerDirectly()

	/**
	 * A register, schema and table with one object owned by the owner.
	 *
	 * @param string $key     The object's `key` property.
	 * @param bool   $private Whether the object is private to its owner.
	 *
	 * @return array{0: Register, 1: Schema, 2: ObjectEntity}
	 */
	private function object(string $key, bool $private): array {
		$register = $this->registerMapper->createFromArray(
			['title' => 'PHPUnit sharer Register ' . uniqid(), 'description' => 'sharer tests']
		);
		$this->createdRegisterIds[] = $register->getId();

		$schema = $this->schemaMapper->createFromArray(
			[
				'title' => 'PHPUnit sharer Schema ' . uniqid(),
				'description' => 'sharer tests',
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
		$entity->setOwner($this->uid('owner'));
		$stored = $this->mapper->insertObjectEntity($entity, $register, $schema, false);

		if ($private === true) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set('_authorization', $qb->createNamedParameter(json_encode(['scope' => 'private'])))
				->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($stored->getUuid())));
			$qb->executeStatement();
		}

		return [$register, $schema, $this->freshObject(register: $register, schema: $schema, object: $stored)];
	}//end object()

	/**
	 * The sharing service.
	 *
	 * @return ObjectSharingService
	 */
	private function sharing(): ObjectSharingService {
		return \OC::$server->get(ObjectSharingService::class);
	}//end sharing()

	/**
	 * Core's share manager.
	 *
	 * @return IManager
	 */
	private function shareManager(): IManager {
		return \OC::$server->get(IManager::class);
	}//end shareManager()
}//end class
