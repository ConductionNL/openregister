<?php

/**
 * Golden test for ObjectOwnershipService's bulk, owning-group, notice and cascade branches.
 *
 * Pins, on the code as it stood before its units were split, the exact outcome
 * of reassign() for every per-record branch, the refusals in front of it,
 * the owning-group write (set, clear with null, clear with '', unknown group,
 * stranger) with its audit row, the previous-owner notice payload and the
 * branches that skip it, and the cascade's early exit. Allowed AND refused
 * outcomes are both asserted.
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

use OCA\OpenRegister\Service\Rbac\OwnershipReassignment;
use OCA\OpenRegister\Service\Rbac\OwnedRecord;
use InvalidArgumentException;
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
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\AuthorizationAssignment;
use OCA\OpenRegister\Service\Rbac\ObjectLookup;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\OwnershipLog;
use OCA\OpenRegister\Service\Rbac\OwnershipSources;
use OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\OwnershipGuards;
use OCA\OpenRegister\Service\Rbac\OwnershipWriters;
use OCA\OpenRegister\Service\Rbac\PrincipalDirectory;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectOwnershipService
 */
final class ObjectOwnershipServiceGoldenTest extends TestCase {

	private IUserSession $userSession;

	private IUserManager $userManager;

	private IGroupManager $groupManager;

	private ObjectAuthorizationWriter $authorizationWriter;

	private AuditTrailMapper $auditTrailMapper;

	private MagicMapper $magicMapper;

	private INotificationManager $notificationManager;

	private GrantChangeAuditor $grantAuditor;

	/**
	 * Notification subjects sent, as [user, subject, params].
	 *
	 * @var array<int, array>
	 */
	private array $notices = [];

	/**
	 * Build the service over fresh mocks.
	 *
	 * @param string[] $knownUsers Users that exist.
	 *
	 * @return ObjectOwnershipService The service.
	 */
	private function service(array $knownUsers = ['alice', 'carol']): ObjectOwnershipService {
		$this->userSession         = $this->createMock(IUserSession::class);
		$this->userManager         = $this->createMock(IUserManager::class);
		$this->groupManager        = $this->createMock(IGroupManager::class);
		$this->authorizationWriter = $this->createMock(ObjectAuthorizationWriter::class);
		$this->auditTrailMapper    = $this->createMock(AuditTrailMapper::class);
		$this->magicMapper         = $this->createMock(MagicMapper::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->grantAuditor        = $this->createMock(GrantChangeAuditor::class);

		$this->userManager->method('userExists')->willReturnCallback(
			static fn (string $uid) => in_array($uid, $knownUsers, true)
		);
		$this->auditTrailMapper->method('recordChange')->willReturn($this->createMock(AuditTrail::class));

		$notices      = &$this->notices;
		$this->notificationManager->method('createNotification')->willReturnCallback(
			function () use (&$notices) {
				$state        = ['user' => null];
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use (&$state, $notification) {
						$state['user'] = $user;
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $params) use (&$state, &$notices, $notification) {
						$notices[] = [$state['user'], $subject, $params];
						return $notification;
					}
				);
				return $notification;
			}
		);

		return new ObjectOwnershipService(
			guards: new OwnershipGuards(
				userSession: $this->userSession,
				grantManage: new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class)),
				permissions: $this->createMock(PermissionHandler::class)
			),
			sources: new OwnershipSources(directory: new PrincipalDirectory(userManager: $this->userManager, groupManager: $this->groupManager), lookup: new ObjectLookup(
				magicMapper: $this->magicMapper,
				registerMapper: $this->createMock(RegisterMapper::class),
				schemaMapper: $this->createMock(SchemaMapper::class)
			)),
			writers: new OwnershipWriters(
				ownerWriter: $this->createMock(ObjectOwnerWriter::class),
				authorizationWriter: $this->authorizationWriter,
				auditTrailMapper: $this->auditTrailMapper,
				notificationManager: $this->notificationManager
			),
			log: new OwnershipLog(logger: new NullLogger(), grantAuditor: $this->grantAuditor)
		);
	}//end service()

	/**
	 * Sign a caller in.
	 *
	 * @param string   $uid    The caller.
	 * @param string[] $groups The caller's groups.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end signIn()

	/**
	 * A record.
	 *
	 * @param string      $uuid  Its uuid.
	 * @param string|null $owner Its owner.
	 * @param string|null $name  Its name.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(string $uuid, ?string $owner, ?string $name = 'Besluit'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOwner($owner);
		$object->setRegister('1');
		$object->setSchema('1');
		$object->setName($name);
		return $object;
	}//end record()

	/**
	 * Run a call, returning the exception's class and message or null.
	 *
	 * @param callable $call The call.
	 *
	 * @return array|null [class, message] or null when it did not throw.
	 */
	private function refusal(callable $call): ?array {
		try {
			$call();
		} catch (Throwable $e) {
			return [get_class($e), $e->getMessage()];
		}

		return null;
	}//end refusal()

	/**
	 * The refusals in front of a bulk reassignment.
	 *
	 * @return void
	 */
	public function testBulkReassignmentRefusals(): void {
		$service = $this->service();
		$this->signIn('bob', ['staff']);
		$this->assertSame(
			[NotAuthorizedException::class, 'Only an administrator may assign a record to somebody else'],
			$this->refusal(fn () => $service->reassign(['u1'], new OwnershipReassignment('carol', false)))
		);

		$service = $this->service();
		$this->signIn('root', ['admin']);
		$this->assertSame(
			[InvalidArgumentException::class, 'No such user: nobody'],
			$this->refusal(fn () => $service->reassign(['u1'], new OwnershipReassignment('nobody', false)))
		);
		$this->assertSame(
			[InvalidArgumentException::class, 'No such user: '],
			$this->refusal(fn () => $service->reassign(['u1'], new OwnershipReassignment('', false)))
		);
		$this->assertSame(
			[InvalidArgumentException::class, 'Name at least one record to reassign'],
			$this->refusal(fn () => $service->reassign([], new OwnershipReassignment('carol', false)))
		);
	}//end testBulkReassignmentRefusals()

	/**
	 * Every per-record branch of a bulk reassignment, in order.
	 *
	 * @return void
	 */
	public function testBulkReassignmentOutcomeGolden(): void {
		$service = $this->service();
		$this->signIn('root', ['admin']);

		$this->magicMapper->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier) {
				return match ($identifier) {
					'gone' => throw new RuntimeException('Object not found'),
					'no-register' => ['object' => $this->record('no-register', 'alice'), 'register' => null, 'schema' => new Schema()],
					'no-schema' => ['object' => $this->record('no-schema', 'alice'), 'register' => new Register(), 'schema' => 'x'],
					'mine' => ['object' => $this->record('mine', 'carol'), 'register' => new Register(), 'schema' => new Schema()],
					'no-uuid' => ['object' => $this->record('', 'alice'), 'register' => new Register(), 'schema' => new Schema()],
					default => ['object' => $this->record((string)$identifier, 'alice'), 'register' => new Register(), 'schema' => new Schema()],
				};
			}
		);
		$this->magicMapper->expects($this->never())->method('findByRelationUsingRelationsColumn');

		$outcome = $service->reassign(
			[42, '', 'no-register', 'mine', 'gone', 'ok-1', 'no-schema', 'no-uuid', 'ok-2'],
			new OwnershipReassignment('carol', false)
		);

		$this->assertSame(
			[
				'newOwner' => 'carol',
				'transferred' => [
					['uuid' => 'ok-1', 'previousOwner' => 'alice', 'newOwner' => 'carol', 'changed' => true, 'children' => []],
					['uuid' => 'ok-2', 'previousOwner' => 'alice', 'newOwner' => 'carol', 'changed' => true, 'children' => []],
				],
				'unchanged' => ['mine'],
				'failed' => [
					['identifier' => '', 'reason' => 'An empty identifier cannot be resolved'],
					['identifier' => '', 'reason' => 'An empty identifier cannot be resolved'],
					['identifier' => 'no-register', 'reason' => 'The record has no register or schema'],
					['identifier' => 'gone', 'reason' => 'Object not found'],
					['identifier' => 'no-schema', 'reason' => 'The record has no register or schema'],
					['identifier' => 'no-uuid', 'reason' => 'A record with no uuid cannot change hands'],
				],
			],
			$outcome
		);

		// Each changed record told its previous owner, actor the administrator.
		$this->assertSame(
			[
				['alice', 'object_ownership_changed', ['objectTitle' => 'Besluit', 'objectUuid' => 'ok-1', 'previousOwner' => 'alice', 'newOwner' => 'carol', 'actor' => 'root']],
				['alice', 'object_ownership_changed', ['objectTitle' => 'Besluit', 'objectUuid' => 'ok-2', 'previousOwner' => 'alice', 'newOwner' => 'carol', 'actor' => 'root']],
			],
			$this->notices
		);
	}//end testBulkReassignmentOutcomeGolden()

	/**
	 * The notice's branches: no previous owner, departed owner, untitled record, system actor.
	 *
	 * @return void
	 */
	public function testPreviousOwnerNoticeGolden(): void {
		$service = $this->service(['alice', 'carol']);
		// The cascade looks for children only under a record that had an owner.
		$lookedUp = [];
		$this->magicMapper->expects($this->exactly(2))->method('findByRelationUsingRelationsColumn')->willReturnCallback(
			function (string $uuid) use (&$lookedUp) {
				$lookedUp[] = $uuid;
				return [];
			}
		);

		$this->magicMapper->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier) {
				return match ($identifier) {
					'unowned' => ['object' => $this->record('unowned', null), 'register' => new Register(), 'schema' => new Schema()],
					'empty-owner' => ['object' => $this->record('empty-owner', ''), 'register' => new Register(), 'schema' => new Schema()],
					'departed' => ['object' => $this->record('departed', 'dave'), 'register' => new Register(), 'schema' => new Schema()],
					'untitled' => ['object' => $this->record('untitled', 'alice', null), 'register' => new Register(), 'schema' => new Schema()],
				};
			}
		);

		// No session: a system operation, the actor falls back to the new owner.
		$this->userSession->method('getUser')->willReturn(null);
		$outcome = SystemOperationContext::run(
			fn () => $service->reassign(['unowned', 'empty-owner', 'departed', 'untitled'], new OwnershipReassignment('carol', true))
		);

		$this->assertSame(
			['unowned', 'empty-owner', 'departed', 'untitled'],
			array_column($outcome['transferred'], 'uuid')
		);
		$this->assertSame(
			[null, '', 'dave', 'alice'],
			array_column($outcome['transferred'], 'previousOwner')
		);
		$this->assertSame([], $outcome['failed']);
		$this->assertSame(
			[
				['alice', 'object_ownership_changed', ['objectTitle' => 'untitled', 'objectUuid' => 'untitled', 'previousOwner' => 'alice', 'newOwner' => 'carol', 'actor' => 'carol']],
			],
			$this->notices
		);
		$this->assertSame(['departed', 'untitled'], $lookedUp);
	}//end testPreviousOwnerNoticeGolden()

	/**
	 * A cascade whose child read fails, and whose children are carried or skipped.
	 *
	 * @return void
	 */
	public function testCascadeChildReadFailureLeavesNoChildren(): void {
		$service = $this->service();
		$this->signIn('root', ['admin']);
		$this->magicMapper->method('findAcrossAllSources')->willReturn(
			['object' => $this->record('parent', 'alice'), 'register' => new Register(), 'schema' => new Schema()]
		);
		$this->magicMapper->expects($this->once())->method('findByRelationUsingRelationsColumn')
			->with('parent')->willThrowException(new RuntimeException('db down'));

		$outcome = $service->reassign(['parent'], new OwnershipReassignment('carol', true));
		$this->assertSame(
			[['uuid' => 'parent', 'previousOwner' => 'alice', 'newOwner' => 'carol', 'changed' => true, 'children' => []]],
			$outcome['transferred']
		);
	}//end testCascadeChildReadFailureLeavesNoChildren()

	/**
	 * Setting and clearing the owning group, with the audit row; refusals.
	 *
	 * @return void
	 */
	public function testOwnerGroupGolden(): void {
		$cases = [
			'set' => [['scope' => 'private'], 'team', ['scope' => 'private', 'ownerGroup' => 'team'], [null, 'team']],
			'replace' => [['ownerGroup' => ' old '], 'team', ['ownerGroup' => 'team'], ['old', 'team']],
			'clear-null' => [['scope' => 'private', 'ownerGroup' => 'old'], null, ['scope' => 'private'], ['old', null]],
			'clear-empty' => [['ownerGroup' => 'old'], '', [], ['old', null]],
			'clear-absent' => [null, null, [], [null, null]],
			'non-string-before' => [['ownerGroup' => ['x']], 'team', ['ownerGroup' => 'team'], [null, 'team']],
		];

		foreach ($cases as $label => [$block, $group, $expected, $audit]) {
			$service = $this->service();
			$this->signIn('alice');
			$this->groupManager->method('groupExists')->willReturnCallback(static fn (string $g) => $g === 'team');
			$object = $this->record('rec', 'alice');
			$object->setAuthorization($block);

			$written = null;
			$this->authorizationWriter->expects($this->once())->method('writeAuthorizationBlock')->willReturnCallback(
				function ($table, AuthorizationAssignment $assignment) use (&$written) {
					$written = [$assignment->objectUuid, $assignment->block];
				}
			);
			$this->grantAuditor->expects($this->once())->method('recordOwnerGroup')->with($object, $audit[0], $audit[1]);

			$result = $service->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $object), group: $group);
			$this->assertSame($expected, $result, $label);
			$this->assertSame(['rec', $expected], $written, $label);
			$this->assertSame($expected, $object->getAuthorization(), $label);
		}
	}//end testOwnerGroupGolden()

	/**
	 * Refused owning-group writes write nothing.
	 *
	 * @return void
	 */
	public function testOwnerGroupRefusals(): void {
		// Unknown group.
		$service = $this->service();
		$this->signIn('alice');
		$this->groupManager->method('groupExists')->willReturn(false);
		$this->authorizationWriter->expects($this->never())->method('writeAuthorizationBlock');
		$this->grantAuditor->expects($this->never())->method('recordOwnerGroup');
		$this->assertSame(
			[InvalidArgumentException::class, 'No such group: ghosts'],
			$this->refusal(fn () => $service->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record('rec', 'alice')), group: 'ghosts'))
		);

		// A stranger, even for a clear.
		$service = $this->service();
		$this->signIn('mallory');
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->authorizationWriter->expects($this->never())->method('writeAuthorizationBlock');
		foreach (['team', null, ''] as $group) {
			$this->assertSame(
				[NotAuthorizedException::class, 'Only the owner or an administrator may change who owns this record'],
				$this->refusal(fn () => $service->setOwnerGroupFor(record: new OwnedRecord(register: new Register(), schema: new Schema(), object: $this->record('rec', 'alice')), group: $group))
			);
		}
	}//end testOwnerGroupRefusals()
}//end class
