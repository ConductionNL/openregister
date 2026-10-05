<?php

/**
 * OpenRegister - a record changes hands, and it is recorded and announced
 *
 * Six properties are pinned here, each one a way the capability can look present
 * and be absent:
 *
 *  - a takeover is refused when the rules refuse the edit, AND NOTHING IS WRITTEN;
 *  - a takeover writes the owner, one typed audit entry, and one notification;
 *  - the audit entry names both the previous and the new owner;
 *  - only an administrator may assign a record to somebody else;
 *  - a bulk reassignment writes one entry PER RECORD, and one failure does not
 *    lose the rest;
 *  - the change carries to a child that shared the owner, and not to one that
 *    did not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Checked, recorded, announced handovers.
 */
class ObjectOwnershipServiceTest extends TestCase {

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The users.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * The groups.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The rules.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler $permissions;

	/**
	 * The owner column.
	 *
	 * @var ObjectOwnerWriter&MockObject
	 */
	private ObjectOwnerWriter $ownerWriter;

	/**
	 * The authorization block.
	 *
	 * @var ObjectAuthorizationWriter&MockObject
	 */
	private ObjectAuthorizationWriter $authorizationWriter;

	/**
	 * The trail.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * The storage.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $magicMapper;

	/**
	 * The notifications.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager $notificationManager;

	/**
	 * The register resolver.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * The schema resolver.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * System under test.
	 *
	 * @var ObjectOwnershipService
	 */
	private ObjectOwnershipService $service;

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->ownerWriter = $this->createMock(ObjectOwnerWriter::class);
		$this->authorizationWriter = $this->createMock(ObjectAuthorizationWriter::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->userManager->method('userExists')->willReturn(true);

		$this->service = new ObjectOwnershipService(
			$this->userSession,
			$this->userManager,
			$this->groupManager,
			new ObjectScopeResolver(),
			$this->permissions,
			$this->ownerWriter,
			$this->authorizationWriter,
			$this->auditTrailMapper,
			$this->magicMapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->notificationManager,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Sign a caller in, with their groups.
	 *
	 * @param string|null $uid The caller, or null for anonymous.
	 * @param string[] $groups Their groups.
	 *
	 * @return void
	 */
	private function signIn(?string $uid, array $groups = []): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end signIn()

	/**
	 * One stored record.
	 *
	 * @param string $uuid Its uuid.
	 * @param string|null $owner Its owner.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(string $uuid, ?string $owner): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOwner($owner);
		$object->setRegister('1');
		$object->setSchema('1');
		$object->setName('Besluit over de Nieuwstraat');

		return $object;
	}//end record()

	/**
	 * Expect the notification manager to be usable.
	 *
	 * @return INotification&MockObject The notification the service will fill.
	 */
	private function expectNotification(): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);

		return $notification;
	}//end expectNotification()

	/**
	 * A colleague the rules admit takes the record over themselves.
	 *
	 * @return void
	 */
	public function testAColleagueTakesOverWithoutAnAdministrator(): void {
		$this->signIn('bob', ['redactie']);
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->ownerWriter
			->expects($this->once())
			->method('writeOwner')
			->with($this->anything(), $this->anything(), 'uuid-1', 'bob');

		$outcome = $this->service->claim(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice')
		);

		$this->assertTrue($outcome['changed']);
		$this->assertSame('alice', $outcome['previousOwner']);
		$this->assertSame('bob', $outcome['newOwner']);
	}//end testAColleagueTakesOverWithoutAnAdministrator()

	/**
	 * The audit entry is its own typed action and names both owners.
	 *
	 * The diff is built from the two states, so the entry carries the previous
	 * owner and the new one without a second log having to be invented.
	 *
	 * @return void
	 */
	public function testTheHandoverIsRecordedAsItsOwnActionNamingBothOwners(): void {
		$this->signIn('bob', ['redactie']);
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->expectNotification();

		$this->auditTrailMapper
			->expects($this->once())
			->method('createAuditTrail')
			->with(
				$this->callback(
					static function ($old) {
						return $old instanceof ObjectEntity && $old->getOwner() === 'alice';
					}
				),
				$this->callback(
					static function ($new) {
						return $new instanceof ObjectEntity && $new->getOwner() === 'bob';
					}
				),
				ObjectOwnershipService::AUDIT_ACTION
			)
			->willReturn($this->createMock(AuditTrail::class));

		$this->service->claim(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice')
		);
	}//end testTheHandoverIsRecordedAsItsOwnActionNamingBothOwners()

	/**
	 * The previous owner is told, and told who took it.
	 *
	 * GPP-Woo's own manual flags its silent handover as a problem. A record that
	 * leaves your hands without a word stops being anybody's responsibility.
	 *
	 * @return void
	 */
	public function testThePreviousOwnerIsTold(): void {
		$this->signIn('bob', ['redactie']);
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$notification = $this->expectNotification();
		$notification->expects($this->once())->method('setUser')->with('alice')->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with(
				ObjectOwnershipService::NOTIFICATION_SUBJECT,
				$this->callback(
					static function (array $parameters) {
						return $parameters['previousOwner'] === 'alice' && $parameters['newOwner'] === 'bob';
					}
				)
			)
			->willReturnSelf();

		$this->notificationManager->expects($this->once())->method('notify');

		$this->service->claim(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice')
		);
	}//end testThePreviousOwnerIsTold()

	/**
	 * A caller the rules refuse the edit cannot take the record, and NOTHING IS
	 * WRITTEN.
	 *
	 * The second half is the assertion that matters: a refusal that still wrote
	 * the owner would be a refusal in the response only.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotEditCannotTakeTheRecord(): void {
		$this->signIn('mallory', []);
		$this->permissions->method('hasPermission')->willReturn(false);

		$this->ownerWriter->expects($this->never())->method('writeOwner');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrail');
		$this->notificationManager->expects($this->never())->method('notify');

		$this->expectException(NotAuthorizedException::class);
		$this->service->claim(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice')
		);
	}//end testACallerWhoMayNotEditCannotTakeTheRecord()

	/**
	 * An anonymous caller has no owner to derive, so there is nothing to take.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotTakeTheRecord(): void {
		$this->signIn(null);

		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->service->claim(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice')
		);
	}//end testAnAnonymousCallerCannotTakeTheRecord()

	/**
	 * Giving a record away is an administrator's action.
	 *
	 * An ordinary caller may TAKE a record and may not GIVE one: parking a record
	 * on a colleague's name makes them answerable for something they never saw.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorMayAssignToSomebodyElse(): void {
		$this->signIn('bob', ['redactie']);

		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->service->assign(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			newOwner: 'carol'
		);
	}//end testOnlyAnAdministratorMayAssignToSomebodyElse()

	/**
	 * An administrator assigns a record to a named owner.
	 *
	 * @return void
	 */
	public function testAnAdministratorAssignsToANamedOwner(): void {
		$this->signIn('root', ['admin']);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->ownerWriter
			->expects($this->once())
			->method('writeOwner')
			->with($this->anything(), $this->anything(), 'uuid-1', 'carol');

		$outcome = $this->service->assign(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			newOwner: 'carol'
		);

		$this->assertSame('carol', $outcome['newOwner']);
	}//end testAnAdministratorAssignsToANamedOwner()

	/**
	 * A bulk reassignment writes one audit entry per record.
	 *
	 * One entry for the batch cannot be found from any of the records it moved,
	 * which is the shape that makes a bulk action unauditable.
	 *
	 * @return void
	 */
	public function testABulkReassignmentRecordsEveryRecordSeparately(): void {
		$this->signIn('root', ['admin']);
		$this->expectNotification();

		$this->magicMapper->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier) {
				return [
					'object' => $this->record((string)$identifier, 'alice'),
					'register' => new Register(),
					'schema' => new Schema(),
				];
			}
		);

		$this->auditTrailMapper
			->expects($this->exactly(3))
			->method('createAuditTrail')
			->willReturn($this->createMock(AuditTrail::class));

		$outcome = $this->service->reassignMany(
			identifiers: ['uuid-1', 'uuid-2', 'uuid-3'],
			newOwner: 'carol'
		);

		$this->assertCount(3, $outcome['transferred']);
		$this->assertSame([], $outcome['failed']);
	}//end testABulkReassignmentRecordsEveryRecordSeparately()

	/**
	 * One record that cannot be moved does not lose the rest.
	 *
	 * A bulk reassignment that stops halfway leaves an administrator with no way
	 * to know how far it got.
	 *
	 * @return void
	 */
	public function testOneFailureDoesNotLoseTheRestOfTheBatch(): void {
		$this->signIn('root', ['admin']);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->magicMapper->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier) {
				if ($identifier === 'gone') {
					throw new \RuntimeException('Object not found');
				}

				return [
					'object' => $this->record((string)$identifier, 'alice'),
					'register' => new Register(),
					'schema' => new Schema(),
				];
			}
		);

		$outcome = $this->service->reassignMany(
			identifiers: ['uuid-1', 'gone', 'uuid-2'],
			newOwner: 'carol'
		);

		$this->assertCount(2, $outcome['transferred']);
		$this->assertCount(1, $outcome['failed']);
		$this->assertSame('gone', $outcome['failed'][0]['identifier']);
	}//end testOneFailureDoesNotLoseTheRestOfTheBatch()

	/**
	 * The change carries to a child that shared the owner.
	 *
	 * @return void
	 */
	public function testTheChangeCarriesToAChildThatSharedTheOwner(): void {
		$this->signIn('root', ['admin']);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$child = $this->record('uuid-child', 'alice');
		$this->magicMapper->method('findByRelationUsingRelationsColumn')->willReturnCallback(
			static function (string $uuid) use ($child) {
				if ($uuid === 'uuid-1') {
					return [$child];
				}

				return [];
			}
		);
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$written = [];
		$this->ownerWriter->method('writeOwner')->willReturnCallback(
			static function ($register, $schema, string $objectUuid, string $owner) use (&$written): void {
				$written[$objectUuid] = $owner;
			}
		);

		$outcome = $this->service->assign(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			newOwner: 'carol',
			cascade: true
		);

		$this->assertCount(1, $outcome['children']);
		$this->assertSame(['uuid-1' => 'carol', 'uuid-child' => 'carol'], $written);
	}//end testTheChangeCarriesToAChildThatSharedTheOwner()

	/**
	 * A child somebody else owns is left alone.
	 *
	 * It is their record. A handover that took it would quietly move a record
	 * nobody asked about, which is the kind of blast radius that makes a cascade
	 * unusable.
	 *
	 * @return void
	 */
	public function testAChildOwnedBySomebodyElseIsLeftAlone(): void {
		$this->signIn('root', ['admin']);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->magicMapper->method('findByRelationUsingRelationsColumn')->willReturn(
			[$this->record('uuid-child', 'dave')]
		);
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->method('find')->willReturn(new Schema());

		$written = [];
		$this->ownerWriter->method('writeOwner')->willReturnCallback(
			static function ($register, $schema, string $objectUuid, string $owner) use (&$written): void {
				$written[$objectUuid] = $owner;
			}
		);

		$outcome = $this->service->assign(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			newOwner: 'carol',
			cascade: true
		);

		$this->assertSame([], $outcome['children']);
		$this->assertSame(['uuid-1' => 'carol'], $written);
	}//end testAChildOwnedBySomebodyElseIsLeftAlone()

	/**
	 * Children are left alone unless the cascade was asked for.
	 *
	 * @return void
	 */
	public function testTheCascadeIsOffUnlessAskedFor(): void {
		$this->signIn('root', ['admin']);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->magicMapper->expects($this->never())->method('findByRelationUsingRelationsColumn');

		$this->service->assign(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			newOwner: 'carol'
		);
	}//end testTheCascadeIsOffUnlessAskedFor()

	/**
	 * Taking a record you already own writes nothing.
	 *
	 * Not an error, and not a second audit entry or a second notification: a
	 * repeated call is reported as having changed nothing.
	 *
	 * @return void
	 */
	public function testTakingARecordYouAlreadyOwnWritesNothing(): void {
		$this->signIn('alice', ['redactie']);
		$this->permissions->method('hasPermission')->willReturn(true);

		$this->ownerWriter->expects($this->never())->method('writeOwner');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrail');

		$outcome = $this->service->claim(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice')
		);

		$this->assertFalse($outcome['changed']);
	}//end testTakingARecordYouAlreadyOwnWritesNothing()

	/**
	 * A record with no uuid cannot change hands.
	 *
	 * The write is keyed on the uuid. Cast instead of refused, a null uuid
	 * becomes the empty string, and the update then addresses whatever rows carry
	 * an empty uuid rather than failing.
	 *
	 * @return void
	 */
	public function testARecordWithNoUuidCannotChangeHands(): void {
		$this->signIn('root', ['admin']);

		$object = new ObjectEntity();
		$object->setOwner('alice');

		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->assign(
			register: new Register(),
			schema: new Schema(),
			object: $object,
			newOwner: 'carol'
		);
	}//end testARecordWithNoUuidCannotChangeHands()

	/**
	 * The owner names the group that owns the record with them.
	 *
	 * The scope in the same block survives it: the block is read, one key is
	 * changed, and the block is written back.
	 *
	 * @return void
	 */
	public function testTheOwnerNamesTheOwningGroupWithoutLosingTheScope(): void {
		$this->signIn('alice', []);
		$this->groupManager->method('groupExists')->willReturn(true);

		$object = $this->record('uuid-1', 'alice');
		$object->setAuthorization(['scope' => 'private']);

		$this->authorizationWriter
			->expects($this->once())
			->method('writeAuthorizationBlock')
			->with(
				$this->anything(),
				$this->anything(),
				'uuid-1',
				['scope' => 'private', 'ownerGroup' => 'redactie']
			);

		$block = $this->service->setOwnerGroup(
			register: new Register(),
			schema: new Schema(),
			object: $object,
			group: 'redactie'
		);

		$this->assertSame('redactie', $block['ownerGroup']);
	}//end testTheOwnerNamesTheOwningGroupWithoutLosingTheScope()

	/**
	 * Somebody who neither owns nor administers cannot name the owning group.
	 *
	 * @return void
	 */
	public function testAStrangerCannotNameTheOwningGroup(): void {
		$this->signIn('mallory', []);

		$this->authorizationWriter->expects($this->never())->method('writeAuthorizationBlock');

		$this->expectException(NotAuthorizedException::class);
		$this->service->setOwnerGroup(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			group: 'redactie'
		);
	}//end testAStrangerCannotNameTheOwningGroup()

	/**
	 * A group that does not exist is refused.
	 *
	 * A group id nobody answers to looks like shared ownership and reaches
	 * nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownOwningGroupIsRefused(): void {
		$this->signIn('alice', []);
		$this->groupManager->method('groupExists')->willReturn(false);

		$this->authorizationWriter->expects($this->never())->method('writeAuthorizationBlock');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setOwnerGroup(
			register: new Register(),
			schema: new Schema(),
			object: $this->record('uuid-1', 'alice'),
			group: 'nobody'
		);
	}//end testAnUnknownOwningGroupIsRefused()

}//end class
