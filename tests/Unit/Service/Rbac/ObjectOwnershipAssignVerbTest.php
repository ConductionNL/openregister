<?php

/**
 * OpenRegister - handing a record to somebody else is gated on `assign`
 *
 * Pins task 3.2 of grants-that-follow-a-slot-a-relation-or-a-reason: the
 * reassignment path is gated on the `assign` verb for the record, not on the
 * administrator check, and holding `update` does not imply it. A block that
 * does not declare `assign` leaves it to administrators, as before.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\OwnershipReassignment;
use OCA\OpenRegister\Service\Rbac\OwnershipHandover;
use OCA\OpenRegister\Service\Rbac\OwnedRecord;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\PartialObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\ObjectLookup;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\OwnershipLog;
use OCA\OpenRegister\Service\Rbac\OwnershipSources;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter;
use OCA\OpenRegister\Service\Rbac\OwnerAssignment;
use OCA\OpenRegister\Service\Rbac\AuthorizationAssignment;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\OwnershipGuards;
use OCA\OpenRegister\Service\Rbac\OwnershipWriters;
use OCA\OpenRegister\Service\Rbac\PrincipalDirectory;
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
class ObjectOwnershipAssignVerbTest extends TestCase {


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
			guards: new OwnershipGuards(
				userSession: $this->userSession,
				grantManage: new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class)),
				permissions: $this->permissions
			),
			sources: new OwnershipSources(directory: new PrincipalDirectory(userManager: $this->userManager, groupManager: $this->groupManager), lookup: new ObjectLookup(magicMapper: $this->magicMapper, registerMapper: $this->registerMapper, schemaMapper: $this->schemaMapper)),
			writers: new OwnershipWriters(
				ownerWriter: $this->ownerWriter,
				authorizationWriter: $this->authorizationWriter,
				auditTrailMapper: $this->auditTrailMapper,
				notificationManager: $this->notificationManager
			),
			log: new OwnershipLog(logger: $this->createMock(LoggerInterface::class))
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
	 * A parent with one child and one grandchild, all owned by alice.
	 *
	 * @return void
	 */
	private function relatedFamily(): void {
		$child = $this->record(uuid: 'uuid-child', owner: 'alice');
		$grandchild = $this->record(uuid: 'uuid-grandchild', owner: 'alice');
		$this->magicMapper->method('findByRelationUsingRelationsColumn')->willReturnCallback(
			static function (string $uuid) use ($child, $grandchild): array {
				return match ($uuid) {
					'uuid-1' => [$child],
					'uuid-child' => [$grandchild],
					default => [],
				};
			}
		);
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->schemaMapper->method('find')->willReturn(new Schema());
	}//end relatedFamily()

	/**
	 * Answer the permission checks: `assign` and `update` as given, read always.
	 *
	 * @param array<string, bool> $verbs        The verdict per verb.
	 * @param array<string, mixed>|null $authorization The cascaded authorization block.
	 *
	 * @return void
	 */
	private function rules(array $verbs, ?array $authorization): void {
		$this->permissions->method('resolveAuthorization')->willReturn($authorization);
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action): bool => ($verbs[$action] ?? false)
		);
	}//end rules()

	/**
	 * Assign uuid-1 (owned by alice) to carol.
	 *
	 * @param bool $cascade Whether the assignment carries to the children.
	 *
	 * @return array The outcome.
	 */
	private function assignToCarol(bool $cascade = false): array {
		return $this->service->assign(
			record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record('uuid-1', 'alice')),
			handover: new OwnershipHandover(newOwner: 'carol', cascade: $cascade)
		);
	}//end assignToCarol()

	/**
	 * A coordinator holding `assign` and not `update` hands the record over.
	 *
	 * @return void
	 */
	public function testACoordinatorWhoCannotEditCanStillHandWorkOver(): void {
		$this->signIn('cora', ['coordinators']);
		$this->rules(verbs: ['read' => true, 'assign' => true, 'update' => false], authorization: ['assign' => ['coordinators']]);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));
		$this->ownerWriter->expects($this->once())->method('writeOwner')->with($this->anything(), new OwnerAssignment('uuid-1', 'carol'));

		$outcome = $this->assignToCarol();

		$this->assertSame('carol', $outcome['newOwner']);
	}//end testACoordinatorWhoCannotEditCanStillHandWorkOver()

	/**
	 * The verb asked is `assign`, about the record and its owner.
	 *
	 * @return void
	 */
	public function testTheRuleAskedIsAssignOnTheRecord(): void {
		$this->signIn('cora', ['coordinators']);
		$this->permissions->method('resolveAuthorization')->willReturn(['assign' => ['coordinators']]);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with(
				$this->isInstanceOf(Schema::class),
				'assign',
				null,
				'alice',
				true,
				$this->callback(static fn (ObjectEntity $o): bool => $o->getUuid() === 'uuid-1')
			)
			->willReturn(true);
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->assertSame('alice', $this->assignToCarol()['previousOwner']);
	}//end testTheRuleAskedIsAssignOnTheRecord()

	/**
	 * An editor who does not hold `assign` cannot reassign by virtue of editing.
	 *
	 * @return void
	 */
	public function testAnEditorCannotReassignByVirtueOfEditing(): void {
		$this->signIn('eddie', ['redactie']);
		$this->rules(verbs: ['read' => true, 'update' => true, 'assign' => false], authorization: ['update' => ['redactie'], 'assign' => ['coordinators']]);
		$this->ownerWriter->expects($this->never())->method('writeOwner');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrail');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only an administrator, or somebody holding assign on this record, may assign it to somebody else');
		$this->assignToCarol();
	}//end testAnEditorCannotReassignByVirtueOfEditing()

	/**
	 * Where no block declares `assign`, it stays an administrator's right, even
	 * for a caller the rule chain admits to everything else.
	 *
	 * @return void
	 */
	public function testAnUndeclaredAssignStaysWithAdministrators(): void {
		$this->signIn('eddie', ['redactie']);
		$this->permissions->method('resolveAuthorization')->willReturn(['read' => ['redactie'], 'update' => ['redactie']]);
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only an administrator may assign a record to somebody else');
		$this->assignToCarol();
	}//end testAnUndeclaredAssignStaysWithAdministrators()

	/**
	 * A schema with no authorization block at all (open to the rule chain) does
	 * not hand everybody the right to give records away.
	 *
	 * @return void
	 */
	public function testAnOpenSchemaDoesNotOpenAssign(): void {
		$this->signIn('eddie', ['redactie']);
		$this->rules(verbs: ['assign' => true, 'update' => true], authorization: null);
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only an administrator may assign a record to somebody else');
		$this->assignToCarol();
	}//end testAnOpenSchemaDoesNotOpenAssign()

	/**
	 * An empty `assign` list declares nobody.
	 *
	 * @return void
	 */
	public function testAnEmptyAssignListDeclaresNobody(): void {
		$this->signIn('eddie', ['redactie']);
		$this->rules(verbs: ['assign' => true], authorization: ['assign' => []]);
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only an administrator may assign a record to somebody else');
		$this->assignToCarol();
	}//end testAnEmptyAssignListDeclaresNobody()

	/**
	 * An anonymous caller is refused even where `assign` is declared public.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotAssign(): void {
		$this->signIn(null);
		$this->rules(verbs: ['assign' => true], authorization: ['assign' => ['public']]);
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->assignToCarol();
	}//end testAnAnonymousCallerCannotAssign()

	/**
	 * A verdict that throws is a refusal.
	 *
	 * @return void
	 */
	public function testAnUnreachableVerdictRefusesTheAssignment(): void {
		$this->signIn('cora', ['coordinators']);
		$this->permissions->method('resolveAuthorization')->willReturn(['assign' => ['coordinators']]);
		$this->permissions->method('hasPermission')->willThrowException(new \RuntimeException('rule store down'));
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->assignToCarol();
	}//end testAnUnreachableVerdictRefusesTheAssignment()

	/**
	 * An unreadable authorization block leaves assign with administrators.
	 *
	 * @return void
	 */
	public function testAnUnreadableBlockRefusesTheAssignment(): void {
		$this->signIn('cora', ['coordinators']);
		$this->permissions->method('resolveAuthorization')->willThrowException(new \RuntimeException('schema gone'));
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only an administrator may assign a record to somebody else');
		$this->assignToCarol();
	}//end testAnUnreadableBlockRefusesTheAssignment()

	/**
	 * An administrator still assigns where nobody declared `assign`, without
	 * the rules being asked.
	 *
	 * @return void
	 */
	public function testAnAdministratorStillAssignsWithoutTheVerbDeclared(): void {
		$this->signIn('root', ['admin']);
		$this->permissions->expects($this->never())->method('resolveAuthorization');
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));

		$this->assertSame('carol', $this->assignToCarol()['newOwner']);
	}//end testAnAdministratorStillAssignsWithoutTheVerbDeclared()

	/**
	 * A cascade by a coordinator is refused whole when they may not assign a
	 * child, and nothing moves.
	 *
	 * @return void
	 */
	public function testACascadeIsRefusedWhenTheCallerMayNotAssignAChild(): void {
		$this->signIn('cora', ['coordinators']);
		$this->permissions->method('resolveAuthorization')->willReturn(['assign' => ['coordinators']]);
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (
				Schema $schema,
				string $action,
				?string $userId = null,
				?string $objectOwner = null,
				bool $rbac = true,
				?ObjectEntity $object = null
			): bool => $action === 'assign' && $object?->getUuid() !== 'uuid-grandchild'
		);
		$this->relatedFamily();
		$this->ownerWriter->expects($this->never())->method('writeOwner');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrail');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only somebody who may assign every related record can assign this record with its related records');
		$this->assignToCarol(cascade: true);
	}//end testACascadeIsRefusedWhenTheCallerMayNotAssignAChild()

	/**
	 * A cascade by a coordinator holding `assign` on the whole family carries,
	 * although they may edit none of it.
	 *
	 * @return void
	 */
	public function testACascadeCarriesWhenTheCallerMayAssignEveryChild(): void {
		$this->signIn('cora', ['coordinators']);
		$this->rules(verbs: ['assign' => true, 'update' => false], authorization: ['assign' => ['coordinators']]);
		$this->relatedFamily();
		$this->expectNotification();
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));
		$written = [];
		$this->ownerWriter->method('writeOwner')->willReturnCallback(
			static function ($table, OwnerAssignment $assignment) use (&$written): void {
				$written[$assignment->objectUuid] = $assignment->owner;
			}
		);

		$this->assignToCarol(cascade: true);

		$this->assertSame(['uuid-1' => 'carol', 'uuid-child' => 'carol', 'uuid-grandchild' => 'carol'], $written);
	}//end testACascadeCarriesWhenTheCallerMayAssignEveryChild()
}//end class
