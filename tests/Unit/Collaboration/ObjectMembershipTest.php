<?php

/**
 * ObjectMembership: adding an object to a collection and taking it out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Collaboration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Collaboration;

use OCA\OpenRegister\Collaboration\ObjectCollections;
use OCA\OpenRegister\Collaboration\ObjectMembership;
use OCA\OpenRegister\Collaboration\ObjectResourceProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectActivityAuditInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectUpdateRightsInterface;
use OCP\Collaboration\Resources\CollectionException;
use OCP\Collaboration\Resources\ICollection;
use OCP\Collaboration\Resources\IManager;
use OCP\Collaboration\Resources\IResource;
use OCP\Collaboration\Resources\ResourceException;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the update-guarded, audited collection membership.
 */
#[CoversClass(ObjectMembership::class)]
#[UsesClass(ObjectCollections::class)]
#[UsesClass(ObjectResourceProvider::class)]
#[UsesClass(ObjectEntity::class)]
class ObjectMembershipTest extends TestCase {

	private const UUID = '5f0c2a6e-1b2c-4d3e-8f9a-0b1c2d3e4f5a';

	private const RESOURCE_ID = '3/7/' . self::UUID;

	/**
	 * Nextcloud's collaboration manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager $manager;

	/**
	 * The schema opt-in.
	 *
	 * @var ObjectResourceProvider&MockObject
	 */
	private ObjectResourceProvider $provider;

	/**
	 * The update right.
	 *
	 * @var ObjectUpdateRightsInterface&MockObject
	 */
	private ObjectUpdateRightsInterface $rights;

	/**
	 * The audit trail.
	 *
	 * @var ObjectActivityAuditInterface&MockObject
	 */
	private ObjectActivityAuditInterface $audit;

	/**
	 * The acting user, `bea`.
	 *
	 * @var IUser&MockObject
	 */
	private IUser $actor;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->manager  = $this->createMock(IManager::class);
		$this->provider = $this->createMock(ObjectResourceProvider::class);
		$this->rights   = $this->createMock(ObjectUpdateRightsInterface::class);
		$this->audit    = $this->createMock(ObjectActivityAuditInterface::class);
		$this->actor    = $this->createMock(IUser::class);
		$this->actor->method('getUID')->willReturn('bea');
	}//end setUp()

	/**
	 * The membership service over the mocks.
	 *
	 * @return ObjectMembership
	 */
	private function membership(): ObjectMembership {
		return new ObjectMembership(
			manager: $this->manager,
			provider: $this->provider,
			rights: $this->rights,
			audit: $this->audit,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end membership()

	/**
	 * The case object in register 3, schema 7.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setUuid(self::UUID);
		return $object;
	}//end object()

	/**
	 * A collection, holding the object's resource or not.
	 *
	 * @param bool $holdsObject Whether the object is already a member.
	 *
	 * @return ICollection&MockObject
	 */
	private function collection(bool $holdsObject): ICollection {
		$members = [];
		if ($holdsObject === true) {
			$own = $this->createMock(IResource::class);
			$own->method('getType')->willReturn(ObjectResourceProvider::TYPE);
			$own->method('getId')->willReturn(self::RESOURCE_ID);
			$members[] = $own;
		}

		$folder = $this->createMock(IResource::class);
		$folder->method('getType')->willReturn('file');
		$folder->method('getId')->willReturn('88');
		$members[] = $folder;

		$collection = $this->createMock(ICollection::class);
		$collection->method('getId')->willReturn(12);
		$collection->method('getName')->willReturn('Dossier Kerkstraat');
		$collection->method('getResources')->willReturn($members);
		return $collection;
	}//end collection()

	/**
	 * The schema opts in and the actor holds update on the object.
	 *
	 * @param bool $mayUpdate Whether the actor may update the object.
	 *
	 * @return void
	 */
	private function joinableAndRight(bool $mayUpdate): void {
		$this->provider->method('mayJoin')->with(7)->willReturn(true);
		$this->rights->method('mayUpdateObject')->with(self::UUID, 'bea')->willReturn($mayUpdate);
	}//end joinableAndRight()

	/**
	 * An updater adds the object: core adds the resource and one `collection.added` entry on the object names the collection.
	 *
	 * @return void
	 */
	public function testAnUpdaterAddsTheObjectAndTheAddIsAudited(): void {
		$this->joinableAndRight(mayUpdate: true);
		$collection = $this->collection(holdsObject: false);
		$this->manager->method('getCollectionForUser')->with(12, $this->actor)->willReturn($collection);
		$resource = $this->createMock(IResource::class);
		$this->manager->expects($this->once())->method('createResource')->with(ObjectResourceProvider::TYPE, self::RESOURCE_ID)->willReturn($resource);
		$collection->expects($this->once())->method('addResource')->with($resource);
		$collection->expects($this->never())->method('removeResource');

		$this->audit->expects($this->once())->method('record')->with(
			self::UUID,
			ObjectMembership::ACTION_ADDED,
			['collection' => ['id' => 12, 'name' => 'Dossier Kerkstraat']]
		);

		$this->assertSame(ObjectMembership::CHANGED, $this->membership()->add(object: $this->object(), collectionId: 12, actor: $this->actor));
	}

	/**
	 * Removing a member writes `collection.removed`.
	 *
	 * @return void
	 */
	public function testAnUpdaterRemovesTheObjectAndTheRemovalIsAudited(): void {
		$this->joinableAndRight(mayUpdate: true);
		$collection = $this->collection(holdsObject: true);
		$this->manager->method('getCollectionForUser')->willReturn($collection);
		$resource = $this->createMock(IResource::class);
		$this->manager->method('createResource')->willReturn($resource);
		$collection->expects($this->once())->method('removeResource')->with($resource);
		$collection->expects($this->never())->method('addResource');
		$this->audit->expects($this->once())->method('record')->with(
			self::UUID,
			ObjectMembership::ACTION_REMOVED,
			['collection' => ['id' => 12, 'name' => 'Dossier Kerkstraat']]
		);

		$this->assertSame(ObjectMembership::CHANGED, $this->membership()->remove(object: $this->object(), collectionId: 12, actor: $this->actor));
	}

	/**
	 * A reader without update rights is refused: no collection is opened, nothing changes, nothing is audited.
	 *
	 * @return void
	 */
	public function testAReaderWithoutUpdateRightsCannotChangeMembership(): void {
		$this->joinableAndRight(mayUpdate: false);
		$this->manager->expects($this->never())->method('getCollectionForUser');
		$this->manager->expects($this->never())->method('createResource');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(ObjectMembership::FORBIDDEN, $this->membership()->remove(object: $this->object(), collectionId: 12, actor: $this->actor));
		$this->assertSame(ObjectMembership::FORBIDDEN, $this->membership()->add(object: $this->object(), collectionId: 12, actor: $this->actor));
	}

	/**
	 * An update check that throws refuses; it never grants.
	 *
	 * @return void
	 */
	public function testAnUpdateCheckThatFailsRefuses(): void {
		$this->provider->method('mayJoin')->willReturn(true);
		$this->rights->method('mayUpdateObject')->willThrowException(new RuntimeException('schema gone'));
		$this->manager->expects($this->never())->method('getCollectionForUser');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(ObjectMembership::FORBIDDEN, $this->membership()->add(object: $this->object(), collectionId: 12, actor: $this->actor));
	}

	/**
	 * A schema that did not opt in is refused before the update right is asked.
	 *
	 * @return void
	 */
	public function testAnOptedOutSchemaIsNotJoinable(): void {
		$this->provider->method('mayJoin')->with(7)->willReturn(false);
		$this->rights->expects($this->never())->method('mayUpdateObject');
		$this->manager->expects($this->never())->method('getCollectionForUser');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(ObjectMembership::NOT_JOINABLE, $this->membership()->add(object: $this->object(), collectionId: 12, actor: $this->actor));
	}

	/**
	 * A collection the actor may not access, or that does not exist, is not found.
	 *
	 * @return void
	 */
	public function testACollectionTheActorMayNotAccessIsNotFound(): void {
		$this->joinableAndRight(mayUpdate: true);
		$this->manager->method('getCollectionForUser')->willThrowException(new CollectionException('Collection not found'));
		$this->manager->expects($this->never())->method('createResource');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(ObjectMembership::NO_COLLECTION, $this->membership()->add(object: $this->object(), collectionId: 99, actor: $this->actor));
	}

	/**
	 * Adding a member again, or removing a non-member, changes nothing and writes no entry.
	 *
	 * @return void
	 */
	public function testANoOpChangeIsNotAudited(): void {
		$this->joinableAndRight(mayUpdate: true);
		$member    = $this->collection(holdsObject: true);
		$nonMember = $this->collection(holdsObject: false);
		$this->manager->method('getCollectionForUser')->willReturnOnConsecutiveCalls($member, $nonMember);
		$this->manager->expects($this->never())->method('createResource');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(ObjectMembership::UNCHANGED, $this->membership()->add(object: $this->object(), collectionId: 12, actor: $this->actor));
		$this->assertSame(ObjectMembership::UNCHANGED, $this->membership()->remove(object: $this->object(), collectionId: 12, actor: $this->actor));
	}

	/**
	 * Core refusing the add (a race to a duplicate) is no change and no entry.
	 *
	 * @return void
	 */
	public function testACoreRefusalIsNotAudited(): void {
		$this->joinableAndRight(mayUpdate: true);
		$collection = $this->collection(holdsObject: false);
		$collection->method('addResource')->willThrowException(new ResourceException('Already part of the collection'));
		$this->manager->method('getCollectionForUser')->willReturn($collection);
		$this->manager->method('createResource')->willReturn($this->createMock(IResource::class));
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(ObjectMembership::UNCHANGED, $this->membership()->add(object: $this->object(), collectionId: 12, actor: $this->actor));
	}
}
