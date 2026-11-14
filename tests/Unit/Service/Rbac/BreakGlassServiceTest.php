<?php

/**
 * Taking break glass and holding it: the reason, the trail, the notice, the expiry (REQ-GSR-004).
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
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration;
use OCA\OpenRegister\Service\Rbac\BreakGlassService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The service over a fake chained trail, a fixed clock and a recording notifier.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\BreakGlassService
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassTake
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions
 */
class BreakGlassServiceTest extends TestCase {

	/**
	 * The clock fixture: 2026-03-02T09:00:00Z.
	 *
	 * @var integer
	 */
	private const NOW = 1772442000;

	/**
	 * One hour.
	 *
	 * @var integer
	 */
	private const HOUR = 3600;

	/**
	 * The fixed clock's current timestamp.
	 *
	 * @var integer
	 */
	private int $now = self::NOW;

	/**
	 * The trail rows written, as [action, context, actorId].
	 *
	 * @var array<int, array{0: string, 1: array, 2: string|null}>
	 */
	private array $written = [];

	/**
	 * The users notified.
	 *
	 * @var array<int, array{user: string, subject: string, parameters: array}>
	 */
	private array $notified = [];

	/**
	 * When set, reading the trail throws.
	 *
	 * @var boolean
	 */
	private bool $trailFails = false;

	/**
	 * The administered run limit.
	 *
	 * @var string
	 */
	private string $limit = '';

	/**
	 * Reset the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = self::NOW;
		$this->written = [];
		$this->notified = [];
		$this->trailFails = false;
		$this->limit = '';
	}//end setUp()

	/**
	 * A service answering for one caller.
	 *
	 * @param string|null $userId The caller, null for anonymous.
	 * @param string[]    $groups The caller's groups.
	 *
	 * @return BreakGlassService The service.
	 */
	private function serviceFor(?string $userId, array $groups = []): BreakGlassService {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($userId === null ? null : $this->user(uid: $userId));

		$member = $this->user(uid: 'beheerder');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member, $this->user(uid: 'teamlead')]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'beheer' ? $group : null));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->limit);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new BreakGlassService(
			trail: $this->trail(),
			userSession: $session,
			groupManager: $groupManager,
			notifications: $this->notifier(),
			appConfig: $appConfig,
			clock: $clock,
			logger: new NullLogger()
		);
	}//end serviceFor()

	/**
	 * A user with a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A chained trail that keeps what is written and answers it back, newest first.
	 *
	 * @return AuditTrailMapper The trail.
	 */
	private function trail(): AuditTrailMapper {
		$trail = $this->createMock(AuditTrailMapper::class);
		$trail->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null): AuditTrail {
				$this->written[] = [$action, $context, $actorId];
				return new AuditTrail();
			}
		);
		$trail->method('findObjectActions')->willReturnCallback(
			function (string $objectUuid, ActionLookupOptions $options): array {
				if ($this->trailFails === true) {
					throw new RuntimeException('trail unavailable');
				}

				$rows = [];
				foreach (array_reverse($this->written) as [$action, $context, $actorId]) {
					if (in_array($action, $options->actions, true) === true) {
						$rows[] = self::row(action: $action, context: $context, actor: ($actorId ?? (string)($context['grantee'] ?? '')));
					}
				}

				return $rows;
			}
		);

		return $trail;
	}//end trail()

	/**
	 * One trail row.
	 *
	 * @param string $action  The action.
	 * @param array  $context The context.
	 * @param string $actor   The actor.
	 *
	 * @return AuditTrail The row.
	 */
	private static function row(string $action, array $context, string $actor): AuditTrail {
		$row = new AuditTrail();
		$row->setAction($action);
		$row->setChanged($context);
		$row->setUser($actor);

		return $row;
	}//end row()

	/**
	 * A notification manager that records every notice it is handed.
	 *
	 * @return INotificationManager The manager.
	 */
	private function notifier(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state = new \ArrayObject();
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
					$notification->method($setter)->willReturnSelf();
				}

				$notification->method('setUser')->willReturnCallback(
					static function (string $user) use ($state, $notification): INotification {
						$state['user'] = $user;
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					static function (string $subject, array $parameters = []) use ($state, $notification): INotification {
						$state['subject'] = $subject;
						$state['parameters'] = $parameters;
						return $notification;
					}
				);
				$notification->method('getUser')->willReturnCallback(static fn (): string => (string)$state['user']);
				$notification->method('getSubject')->willReturnCallback(static fn (): string => (string)$state['subject']);
				$notification->method('getSubjectParameters')->willReturnCallback(static fn (): array => (array)$state['parameters']);

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->notified[] = [
					'user' => $notification->getUser(),
					'subject' => $notification->getSubject(),
					'parameters' => $notification->getSubjectParameters(),
				];
			}
		);

		return $manager;
	}//end notifier()

	/**
	 * A schema declaring read for four hours, telling `teamlead` and the `beheer` group.
	 *
	 * @param array<string, mixed> $overrides Keys replacing the declaration's.
	 *
	 * @return Schema The schema.
	 */
	private static function declaring(array $overrides = []): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setConfiguration(
			[
				BreakGlassDeclaration::ANNOTATION => array_merge(
					['verbs' => ['read'], 'maxDuration' => 'PT4H', 'notify' => ['users' => ['teamlead'], 'groups' => ['beheer']]],
					$overrides
				),
			]
		);

		return $schema;
	}//end declaring()

	/**
	 * A record.
	 *
	 * @return ObjectEntity The record.
	 */
	private static function record(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('b1a55000-0000-4000-8000-000000000001');
		$object->setName('Aanvraag 12');

		return $object;
	}//end record()

	/**
	 * Somebody at the counter is helped, on the record, and the declared recipient is told at that moment.
	 *
	 * @return void
	 */
	public function testTakingItWritesTheReasonAndTellsTheRecipients(): void {
		$grant = $this->serviceFor(userId: 'ana')->take(object: self::record(), schema: self::declaring(), reason: '  burger aan de balie ');

		$this->assertSame('ana', $grant['grantee']);
		$this->assertSame(['read'], $grant['verbs']);
		$this->assertSame('burger aan de balie', $grant['reason']);
		$this->assertSame('2026-03-02T09:00:00+00:00', $grant['takenAt']);
		$this->assertSame('2026-03-02T13:00:00+00:00', $grant['until']);

		$this->assertCount(1, $this->written);
		$this->assertSame(BreakGlassService::TAKEN_ACTION, $this->written[0][0]);
		$this->assertSame($grant, $this->written[0][1]);

		$this->assertSame(['teamlead', 'beheerder'], array_column($this->notified, 'user'));
		$this->assertSame(BreakGlassService::NOTIFICATION_SUBJECT, $this->notified[0]['subject']);
		$this->assertSame('burger aan de balie', $this->notified[0]['parameters']['reason']);
		$this->assertSame('ana', $this->notified[0]['parameters']['grantee']);
		$this->assertSame('Aanvraag 12', $this->notified[0]['parameters']['objectTitle']);
	}//end testTakingItWritesTheReasonAndTellsTheRecipients()

	/**
	 * A schema that does not declare it refuses, naming the schema, and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUndeclaringSchemaRefusesNamingTheSchema(): void {
		$schema = new Schema();
		$schema->setSlug('dossier');

		try {
			$this->serviceFor(userId: 'ana')->take(object: self::record(), schema: $schema, reason: 'balie');
			$this->fail('expected a refusal');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('Schema "dossier" does not offer emergency access', $e->getMessage());
		}

		$this->assertSame([], $this->written);
		$this->assertSame([], $this->notified);
	}//end testAnUndeclaringSchemaRefusesNamingTheSchema()

	/**
	 * No reason, no grant.
	 *
	 * @return void
	 */
	public function testAReasonIsRequired(): void {
		foreach (['', '   ', null, ['balie']] as $reason) {
			try {
				$this->serviceFor(userId: 'ana')->take(object: self::record(), schema: self::declaring(), reason: $reason);
				$this->fail('expected a refusal for ' . var_export($reason, true));
			} catch (InvalidArgumentException $e) {
				$this->assertSame('A reason is required to take emergency access', $e->getMessage());
			}
		}

		$this->assertSame([], $this->written);
	}//end testAReasonIsRequired()

	/**
	 * An anonymous caller, and a caller outside the taker groups, may not take it.
	 *
	 * @return void
	 */
	public function testOnlyASignedInCallerInTheTakerGroupsMayTakeIt(): void {
		try {
			$this->serviceFor(userId: null)->take(object: self::record(), schema: self::declaring(), reason: 'balie');
			$this->fail('expected a refusal');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('Emergency access is taken by a signed-in user', $e->getMessage());
		}

		$narrow = self::declaring(overrides: ['groups' => ['balie']]);
		try {
			$this->serviceFor(userId: 'ana', groups: ['medewerkers'])->take(object: self::record(), schema: $narrow, reason: 'balie');
			$this->fail('expected a refusal');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('You may not take emergency access on this schema', $e->getMessage());
		}

		$this->assertSame([], $this->written);

		$grant = $this->serviceFor(userId: 'ana', groups: ['balie'])->take(object: self::record(), schema: $narrow, reason: 'balie');
		$this->assertSame('ana', $grant['grantee']);
	}//end testOnlyASignedInCallerInTheTakerGroupsMayTakeIt()

	/**
	 * The grant carries the declared verbs only, and ends by itself four hours later.
	 *
	 * @return void
	 */
	public function testTheGrantHoldsForTheDeclaredVerbsAndPeriodOnly(): void {
		$service = $this->serviceFor(userId: 'ana');
		$this->assertFalse($service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read'));

		$service->take(object: self::record(), schema: self::declaring(), reason: 'balie');

		$this->assertTrue($service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read'));
		$this->assertFalse($service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'update'));
		$this->assertFalse($service->grants(userId: 'bob', object: self::record(), schema: self::declaring(), verb: 'read'));

		$this->now = self::NOW + 4 * self::HOUR;
		$this->assertFalse($service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read'));
	}//end testTheGrantHoldsForTheDeclaredVerbsAndPeriodOnly()

	/**
	 * Every read made under the grant is audited as made under it, once per take per verb.
	 *
	 * @return void
	 */
	public function testAUseIsAuditedAsMadeUnderTheGrant(): void {
		$service = $this->serviceFor(userId: 'ana');
		$service->take(object: self::record(), schema: self::declaring(), reason: 'balie');

		$service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read');
		$service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read');

		$uses = array_values(array_filter($this->written, static fn (array $row): bool => $row[0] === BreakGlassService::USED_ACTION));
		$this->assertCount(1, $uses);
		$this->assertSame('ana', $uses[0][2]);
		$this->assertSame('read', $uses[0][1]['verb']);
		$this->assertSame('2026-03-02T09:00:00+00:00', $uses[0][1]['takenAt']);
		$this->assertSame('2026-03-02T13:00:00+00:00', $uses[0][1]['until']);
	}//end testAUseIsAuditedAsMadeUnderTheGrant()

	/**
	 * Narrowing the declaration takes effect on a take still running.
	 *
	 * @return void
	 */
	public function testTheDeclarationAsItStandsNowBoundsARunningTake(): void {
		$service = $this->serviceFor(userId: 'ana');
		$service->take(object: self::record(), schema: self::declaring(), reason: 'balie');

		$this->assertFalse($service->grants(userId: 'ana', object: self::record(), schema: new Schema(), verb: 'read'));
		$this->assertFalse($service->grants(userId: 'ana', object: self::record(), schema: self::declaring(overrides: ['verbs' => ['update']]), verb: 'read'));
	}//end testTheDeclarationAsItStandsNowBoundsARunningTake()

	/**
	 * Taking it again cannot extend it without an administered limit; with one it extends to the limit.
	 *
	 * @return void
	 */
	public function testTakingItAgainIsBoundedByTheAdministeredLimit(): void {
		$service = $this->serviceFor(userId: 'ana');
		$service->take(object: self::record(), schema: self::declaring(), reason: 'balie');

		$this->now = self::NOW + 3 * self::HOUR;
		try {
			$service->take(object: self::record(), schema: self::declaring(), reason: 'nog steeds');
			$this->fail('expected a refusal');
		} catch (NotAuthorizedException $e) {
			$this->assertStringContainsString('administered limit', $e->getMessage());
		}

		$this->limit = 'PT6H';
		$second = $service->take(object: self::record(), schema: self::declaring(), reason: 'nog steeds');
		$this->assertSame('2026-03-02T15:00:00+00:00', $second['until']);
		$this->assertSame('2026-03-02T09:00:00+00:00', $second['chainStart']);
	}//end testTakingItAgainIsBoundedByTheAdministeredLimit()

	/**
	 * A row naming another grantee than its actor grants that grantee nothing.
	 *
	 * @return void
	 */
	public function testARowWhoseActorIsNotItsGranteeGrantsNothing(): void {
		$this->written[] = [
			BreakGlassService::TAKEN_ACTION,
			['grantee' => 'ana', 'verbs' => ['read'], 'takenAt' => '2026-03-02T08:00:00+00:00', 'until' => '2026-03-02T12:00:00+00:00'],
			'mallory',
		];

		$this->assertSame([], $this->serviceFor(userId: 'ana')->takesOn(objectUuid: 'b1a55000-0000-4000-8000-000000000001'));
		$this->assertFalse($this->serviceFor(userId: 'ana')->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read'));
	}//end testARowWhoseActorIsNotItsGranteeGrantsNothing()

	/**
	 * A trail that cannot be read holds no grant.
	 *
	 * @return void
	 */
	public function testAnUnreadableTrailGrantsNothing(): void {
		$service = $this->serviceFor(userId: 'ana');
		$service->take(object: self::record(), schema: self::declaring(), reason: 'balie');

		$this->trailFails = true;
		$this->assertFalse($service->grants(userId: 'ana', object: self::record(), schema: self::declaring(), verb: 'read'));
		$this->assertFalse($service->grants(userId: null, object: self::record(), schema: self::declaring(), verb: 'read'));
	}//end testAnUnreadableTrailGrantsNothing()
}//end class
