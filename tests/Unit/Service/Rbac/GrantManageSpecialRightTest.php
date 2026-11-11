<?php

/**
 * What the special right `grant_manage` opens, and what it leaves closed.
 *
 * The sharing service, the ownership service, the scope resolver and the reader
 * of the special right are the real classes. Only what sits under them (the
 * session, the mappers, core's share manager, the folder) is a double. The
 * refusals are also read through the real sharing controller, because the
 * status a caller sees is the controller's to give.
 *
 * NOT COVERED HERE: the read side. `PermissionHandler` and `MagicRbacHandler`
 * decide who reads and changes a record, and neither knows the special right;
 * {@see testTheReadSideDoesNotKnowTheSpecialRight()} pins that as a fact about
 * the source, which is weaker than a read through a live instance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ScopeChange;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Rbac\OwnedRecord;
use OCA\OpenRegister\Service\Rbac\LinkProtection;
use OCA\OpenRegister\Service\Rbac\LinkInvitation;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\ObjectLookup;
use OCA\OpenRegister\Service\Rbac\OwnershipGuards;
use OCA\OpenRegister\Service\Rbac\OwnershipWriters;
use OCA\OpenRegister\Service\Rbac\PrincipalDirectory;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectSharingControllerFactory;
use OCA\OpenRegister\Controller\ObjectSharingController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\OwnershipLog;
use OCA\OpenRegister\Service\Rbac\OwnershipSources;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
class GrantManageSpecialRightTest extends TestCase {

	private const SERVICE_GROUP = 'records-service';

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private IManager&MockObject $shareManager;

	private ObjectAuthorizationWriter&MockObject $authorizationWriter;

	private IRequest&MockObject $request;

	private AuditTrailMapper&MockObject $trail;

	private ObjectSharingService $sharing;

	private ObjectOwnershipService $ownership;

	private ObjectSharingController $controller;

	private ObjectEntity $record;

	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->shareManager = $this->createMock(IManager::class);
		$this->authorizationWriter = $this->createMock(ObjectAuthorizationWriter::class);
		$this->request = $this->createMock(IRequest::class);

		// The organisation gives the service group the right on ONE schema.
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setAuthorization(
			[
				OrganisationSpecialRights::GRANT_MANAGE => [
					['group' => self::SERVICE_GROUP, 'register' => 'records', 'schemas' => ['private-note']],
				],
			]
		);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findByUuid')->willReturn($organisation);

		$register = new Register();
		$register->setSlug('records');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug(['12' => 'private-note', '13' => 'case'][(string)$id]);
				return $schema;
			}
		);

		$specialRights = new OrganisationSpecialRights($organisations, $registers, $schemas, new NullLogger());

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(55);
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturn($folder);

		$this->trail = $this->createMock(AuditTrailMapper::class);
		$auditor = new GrantChangeAuditor(new \OCA\OpenRegister\Service\Object\AuditRecording($this->trail, new NullLogger()), $this->createMock(SettingsChangeAuditor::class));

		$this->sharing = new ObjectSharingService(
			shares: new \OCA\OpenRegister\Service\Rbac\ObjectShareSurface(folders: $folders, shareManager: $this->shareManager),
			records: new \OCA\OpenRegister\Service\Rbac\ObjectGrantRecords(authorizationWriter: $this->authorizationWriter, scopeResolver: new ObjectScopeResolver(), grantResolver: $this->createMock(ObjectGrantResolver::class), inheritedGrants: $this->createMock(InheritedGrantLister::class)),
			context: new \OCA\OpenRegister\Service\Rbac\ObjectSharingContext(userSession: $this->userSession, groupManager: $this->groupManager, logger: new NullLogger(), auditor: $auditor),
			grantManage: new GrantManageGuard(new ObjectScopeResolver(), $specialRights)
		);

		$this->ownership = new ObjectOwnershipService(
			guards: new OwnershipGuards(
				userSession: $this->userSession,
				grantManage: new GrantManageGuard(new ObjectScopeResolver(), $specialRights),
				permissions: $this->createMock(PermissionHandler::class)
			),
			sources: new OwnershipSources(directory: new PrincipalDirectory(userManager: $this->createMock(IUserManager::class), groupManager: $this->groupManager), lookup: new ObjectLookup(magicMapper: $this->createMock(MagicMapper::class), registerMapper: $registers, schemaMapper: $schemas)),
			writers: new OwnershipWriters(
				ownerWriter: $this->createMock(ObjectOwnerWriter::class),
				authorizationWriter: $this->authorizationWriter,
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				notificationManager: $this->createMock(INotificationManager::class)
			),
			log: new OwnershipLog(logger: new NullLogger(), grantAuditor: $auditor)
		);

		$this->record = $this->record(schema: '12');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('setObject')->willReturnSelf();
		$objectService->method('getObject')->willReturnCallback(fn () => $this->record);

		$this->controller = ObjectSharingControllerFactory::create(
			'openregister',
			$this->request,
			$objectService,
			$registers,
			$schemas,
			$this->sharing,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * A private record owned by a handler, with no owning group.
	 *
	 * @param string $schema The schema id: 12 is the schema the right names, 13 is not.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('record-1');
		$object->setOwner('handler');
		$object->setRegister('7');
		$object->setSchema($schema);
		$object->setOrganisation('org-1');
		$object->setAuthorization(['scope' => 'private']);

		return $object;
	}//end record()

	/**
	 * Sign a caller in.
	 *
	 * @param string $uid The caller.
	 * @param string[] $groups Their groups.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end signIn()

	/**
	 * Let core's share manager hand out and store a share, and return the one it was given.
	 *
	 * @return IShare&MockObject The share the service fills in.
	 */
	private function shareManagerStoresAShare(): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getFullId')->willReturn('ocinternal:9');
		$share->method('getSharedWith')->willReturn('reviewer');
		$share->method('getPermissions')->willReturn(1);
		$share->method('getNodeId')->willReturn(55);
		$this->shareManager->method('newShare')->willReturn($share);
		$this->shareManager->method('createShare')->willReturnArgument(0);
		$this->shareManager->method('getShareById')->willReturn($share);

		return $share;
	}//end shareManagerStoresAShare()

	public function testAHolderWritesListsAndRevokesAGrantOnARecordItDoesNotOwn(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$share = $this->shareManagerStoresAShare();
		$share->expects($this->once())->method('setSharedBy')->with('service-account');
		$this->shareManager->expects($this->once())->method('createShare');
		$this->shareManager->expects($this->once())->method('deleteShare')->with($share);
		$this->shareManager->method('getSharesBy')->willReturn([]);

		$grant = $this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'reviewer', terms: new GrantTerms(permissions: 1)));
		$this->assertSame('ocinternal:9', $grant['id']);
		$this->assertSame([], $this->sharing->listGrants($this->record));
		$this->sharing->revoke($this->record, 'ocinternal:9');
	}//end testAHolderWritesListsAndRevokesAGrantOnARecordItDoesNotOwn()

	public function testEveryGrantAndRevokeLeavesAnAuditRowNamingHowTheCallerWasAdmitted(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$share = $this->shareManagerStoresAShare();
		$share->method('getShareType')->willReturn(IShare::TYPE_USER);

		$rows = [];
		$this->trail->expects($this->exactly(2))->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context) use (&$rows) {
				$rows[] = [$object->getUuid(), $action, $context];

				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		));

		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'reviewer', terms: new GrantTerms(permissions: 1)));
		$this->sharing->revoke($this->record, 'ocinternal:9');

		$this->assertSame('record-1', $rows[0][0]);
		$this->assertSame(GrantChangeAuditor::ACTION_GRANTED, $rows[0][1]);
		$this->assertSame('reviewer', $rows[0][2]['sharedWith']);
		$this->assertSame(GrantManageGuard::AS_HOLDER, $rows[0][2]['admittedAs']);
		$this->assertSame(GrantChangeAuditor::ACTION_REVOKED, $rows[1][1]);
		$this->assertSame('user', $rows[1][2]['type']);
		$this->assertSame('reviewer', $rows[1][2]['sharedWith']);
	}//end testEveryGrantAndRevokeLeavesAnAuditRowNamingHowTheCallerWasAdmitted()

	public function testAPublicLinkAndAnEmailInvitationLeaveAnAuditRowWithoutTheToken(): void {
		$this->signIn('handler', ['staff']);
		$share = $this->shareManagerStoresAShare();
		$share->method('getToken')->willReturn('secret-token');

		$rows = [];
		$this->trail->expects($this->exactly(2))->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context) use (&$rows) {
				$rows[] = [$action, $context];

				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		));

		$this->sharing->createLink(object: $this->record, invitation: new LinkInvitation(recipient: null, permissions: 1, protection: new LinkProtection(password: null, expiration: null)));
		$this->sharing->inviteByEmail(object: $this->record, invitation: new LinkInvitation(recipient: 'guest@example.nl', permissions: 1, protection: new LinkProtection(password: null, expiration: null)));

		$this->assertSame(GrantChangeAuditor::ACTION_GRANTED, $rows[0][0]);
		$this->assertSame('link', $rows[0][1]['type']);
		$this->assertSame(GrantChangeAuditor::ACTION_GRANTED, $rows[1][0]);
		$this->assertSame('email', $rows[1][1]['type']);
		$this->assertStringNotContainsString('secret-token', json_encode($rows));
	}//end testAPublicLinkAndAnEmailInvitationLeaveAnAuditRowWithoutTheToken()

	public function testARefusedGrantLeavesNoAuditRow(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->trail->expects($this->never())->method('recordObjectEvent');

		$this->expectException(NotAuthorizedException::class);
		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'service-account', terms: new GrantTerms(permissions: 1)));
	}//end testARefusedGrantLeavesNoAuditRow()

	public function testANewOwningGroupLeavesAnAuditRowWithBothSides(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->trail->expects($this->once())->method('recordObjectEvent')->with(AuditTrailEvents::matching([
			$this->record,
			GrantChangeAuditor::ACTION_OWNER_GROUP,
			['ownerGroup' => ['old' => null, 'new' => 'supervisors']]
		]));

		$this->ownership->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record), group: 'supervisors');
	}//end testANewOwningGroupLeavesAnAuditRowWithBothSides()

	public function testAHolderNamesTheOwningGroup(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->authorizationWriter->expects($this->once())->method('writeAuthorizationBlock');

		$block = $this->ownership->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record), group: 'supervisors');

		$this->assertSame('supervisors', $block['ownerGroup']);
	}//end testAHolderNamesTheOwningGroup()

	public function testTheRightDoesNotReachARecordOfASchemaItDoesNotName(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->shareManager->expects($this->never())->method('createShare');
		$this->record = $this->record(schema: '13');

		$this->expectException(NotAuthorizedException::class);
		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'reviewer', terms: new GrantTerms(permissions: 1)));
	}//end testTheRightDoesNotReachARecordOfASchemaItDoesNotName()

	public function testANonHolderIsRefusedWithAForbiddenStatus(): void {
		$this->signIn('colleague', ['staff']);
		$this->shareManager->expects($this->never())->method('createShare');
		$this->shareManager->expects($this->never())->method('deleteShare');
		$this->request->method('getParam')->willReturnMap([['type', null, 'user'], ['shareWith', null, 'reviewer']]);

		$this->assertSame(403, $this->controller->createShare('records', 'private-note', 'record-1')->getStatus());
		$this->assertSame(403, $this->controller->shares('records', 'private-note', 'record-1')->getStatus());
		$this->assertSame(
			403,
			$this->controller->destroyShare('records', 'private-note', 'record-1', 'ocinternal:9')->getStatus()
		);
	}//end testANonHolderIsRefusedWithAForbiddenStatus()

	public function testAHolderIsAnsweredCreatedThroughTheController(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->shareManagerStoresAShare();
		$this->request->method('getParam')->willReturnMap([['type', null, 'user'], ['shareWith', null, 'reviewer']]);

		$this->assertSame(201, $this->controller->createShare('records', 'private-note', 'record-1')->getStatus());
	}//end testAHolderIsAnsweredCreatedThroughTheController()

	/**
	 * @dataProvider selfGrants
	 *
	 * @param string $type The principal type.
	 * @param string $grantee The principal.
	 */
	public function testAHolderMayNotGrantToItself(string $type, string $grantee): void {
		$this->signIn('service-account', [self::SERVICE_GROUP, 'jobs']);
		$this->shareManager->expects($this->never())->method('createShare');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('does not let its holder grant access to itself');
		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: $type, shareWith: $grantee, terms: new GrantTerms(permissions: 1)));
	}//end testAHolderMayNotGrantToItself()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function selfGrants(): array {
		return [
			'its own account' => ['user', 'service-account'],
			'its own account, padded' => ['user', ' service-account '],
			'the group that holds the right' => ['group', self::SERVICE_GROUP],
			'another group it is in' => ['group', 'jobs'],
			'a federated cloud id' => ['remote', 'service-account@other.example'],
			'a remote group' => ['remote_group', 'staff@other.example'],
		];
	}//end selfGrants()

	public function testAHolderMayNotNameItsOwnGroupAsTheOwner(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->authorizationWriter->expects($this->never())->method('writeAuthorizationBlock');

		$this->expectException(NotAuthorizedException::class);
		$this->ownership->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record), group: self::SERVICE_GROUP);
	}//end testAHolderMayNotNameItsOwnGroupAsTheOwner()

	public function testTheRightOpensNeitherTheScopeNorALinkNorAnInvitation(): void {
		$this->signIn('service-account', [self::SERVICE_GROUP]);
		$this->authorizationWriter->expects($this->never())->method('writeAuthorizationBlock');
		$this->shareManager->expects($this->never())->method('createShare');
		$refused = 0;

		foreach ([
			fn () => $this->sharing->setScope(table: new RegisterSchema(register: new Register(), schema: new Schema()), change: new ScopeChange(object: $this->record, scope: 'organisation')),
			fn () => $this->sharing->createLink(object: $this->record, invitation: new LinkInvitation(recipient: null, permissions: 1, protection: new LinkProtection(password: null, expiration: null))),
			fn () => $this->sharing->inviteByEmail(object: $this->record, invitation: new LinkInvitation(recipient: 'someone@example.org', permissions: 1, protection: new LinkProtection(password: null, expiration: null))),
		] as $attempt) {
			try {
				$attempt();
			} catch (NotAuthorizedException $e) {
				$refused++;
			}
		}

		$this->assertSame(3, $refused);
	}//end testTheRightOpensNeitherTheScopeNorALinkNorAnInvitation()

	public function testAHolderIsNotAdmittedToTheRecordItself(): void {
		$admitted = (new ObjectScopeResolver())->admitsUnconditionally(
			userId: 'service-account',
			userGroups: [self::SERVICE_GROUP],
			objectOwner: $this->record->getOwner(),
			authorization: $this->record->getAuthorization()
		);

		$this->assertFalse($admitted);
	}//end testAHolderIsNotAdmittedToTheRecordItself()

	public function testTheReadSideDoesNotKnowTheSpecialRight(): void {
		$root = dirname(__DIR__, 4) . '/lib/';
		foreach ([
			'Service/Object/PermissionHandler.php',
			'Db/MagicMapper/MagicRbacHandler.php',
			'Service/Rbac/ObjectScopeResolver.php',
			'Service/Rbac/ObjectGrantResolver.php',
			'Service/PropertyRbacHandler.php',
		] as $file) {
			$source = (string)file_get_contents($root . $file);
			$this->assertNotSame('', $source, $file);
			$this->assertStringNotContainsString('OrganisationSpecialRights', $source, $file);
			$this->assertStringNotContainsString('GrantManageGuard', $source, $file);
			$this->assertStringNotContainsString('grant_manage', $source, $file);
		}
	}//end testTheReadSideDoesNotKnowTheSpecialRight()

	public function testTheOwnerAndAnAdministratorManageGrantsAsBefore(): void {
		$this->shareManagerStoresAShare();
		$this->shareManager->expects($this->exactly(2))->method('createShare');
		// Neither is in the group that holds the right, and both may grant to themselves.
		$this->userSession->method('getUser')->willReturnCallback(
			function (): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('handler');
				return $user;
			}
		);
		$this->groupManager->method('getUserGroupIds')->willReturnOnConsecutiveCalls(['staff'], ['admin']);

		// The owner (uid `handler`, no special group).
		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'user', shareWith: 'handler', terms: new GrantTerms(permissions: 1)));

		// An administrator who is not the owner.
		$this->record->setOwner('somebody-else');
		$this->sharing->grantWithTerms(object: $this->record, request: new GrantRequest(type: 'group', shareWith: 'admin', terms: new GrantTerms(permissions: 1)));
	}//end testTheOwnerAndAnAdministratorManageGrantsAsBefore()

	public function testSomebodyWhoIsNeitherIsStillRefusedTheOwningGroup(): void {
		$this->signIn('colleague', ['staff']);

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Only the owner or an administrator may change who owns this record');
		$this->ownership->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record), group: 'supervisors');
	}//end testSomebodyWhoIsNeitherIsStillRefusedTheOwningGroup()
}//end class
