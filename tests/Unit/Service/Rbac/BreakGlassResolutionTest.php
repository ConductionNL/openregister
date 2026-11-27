<?php

/**
 * Break glass resolved through the one evaluator (REQ-GSR-004, design D-6).
 *
 * Every case asks `PermissionHandler::hasPermission()` with a real
 * `BreakGlassService` over a fake chained trail, so "they may read that record"
 * and "the write is refused" are measured where the verdict is taken.
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration;
use OCA\OpenRegister\Service\Rbac\BreakGlassService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The break-glass grant end to end through the permission handler.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @covers \OCA\OpenRegister\Service\Rbac\BreakGlassService
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassTake
 */
class BreakGlassResolutionTest extends TestCase {

	/**
	 * The clock fixture: 2026-03-02T09:00:00Z.
	 *
	 * @var integer
	 */
	private const NOW = 1772442000;

	/**
	 * The fixed clock.
	 *
	 * @var integer
	 */
	private int $now = self::NOW;

	/**
	 * The trail rows, as [action, context, actor].
	 *
	 * @var array<int, array{0: string, 1: array, 2: string}>
	 */
	private array $rows = [];

	/**
	 * Hands out a distinct schema id per schema, so the verdict memo never answers for another case.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 900;

	/**
	 * Reset the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = self::NOW;
		$this->rows = [];
	}//end setUp()

	/**
	 * A handler answering for one caller, with the service over the fake trail.
	 *
	 * @param string $userId The caller.
	 *
	 * @return PermissionHandler The handler, deny enforcing.
	 */
	private function handlerFor(string $userId): PermissionHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$logger = new NullLogger();

		return PermissionHandlerFactory::create(
			userSession: $userSession,
			userManager: $userManager,
			groupManager: $groupManager,
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $appConfig,
			logger: $logger,
			container: $this->container(),
			objectScopeResolver: new ObjectScopeResolver(),
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode($appConfig, $logger),
			breakGlass: $this->service(userSession: $userSession, groupManager: $groupManager)
		);
	}//end handlerFor()

	/**
	 * The service under test over the fake trail and the fixed clock.
	 *
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager The groups.
	 *
	 * @return BreakGlassService The service.
	 */
	private function service(IUserSession $userSession, IGroupManager $groupManager): BreakGlassService {
		$trail = $this->createMock(AuditTrailMapper::class);
		$trail->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null): AuditTrail {
				$this->rows[] = [$action, $context, (string)($actorId ?? $context['grantee'] ?? '')];
				return new AuditTrail();
			}
		);
		$trail->method('findObjectActions')->willReturnCallback(
			function (string $objectUuid, ActionLookupOptions $options): array {
				$found = [];
				foreach (array_reverse($this->rows) as [$action, $context, $actor]) {
					if (in_array($action, $options->actions, true) === true) {
						$row = new AuditTrail();
						$row->setAction($action);
						$row->setChanged($context);
						$row->setUser($actor);
						$found[] = $row;
					}
				}

				return $found;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new BreakGlassService(
			trail: $trail,
			userSession: $userSession,
			groupManager: $groupManager,
			notifications: $this->createMock(INotificationManager::class),
			appConfig: $this->createMock(IAppConfig::class),
			clock: $clock,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A container that knows a register and nothing else.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setTitle('Zaken');
		$register->setSlug('zaken');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end container()

	/**
	 * A schema letting `medewerkers` read and update, declaring read by break glass for four hours.
	 *
	 * @param bool $declares Whether the schema declares break glass.
	 *
	 * @return Schema The schema.
	 */
	private function schema(bool $declares = true): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setSlug('zaak');
		$schema->setTitle('Zaak');
		$schema->setAuthorization(['read' => ['medewerkers'], 'update' => ['medewerkers']]);
		if ($declares === true) {
			$schema->setConfiguration(
				[BreakGlassDeclaration::ANNOTATION => ['verbs' => ['read'], 'maxDuration' => 'PT4H', 'notify' => ['users' => ['teamlead']]]]
			);
		}

		return $schema;
	}//end schema()

	/**
	 * A record owned by somebody who is never the caller.
	 *
	 * @param array|null $authorization The record's own block.
	 *
	 * @return ObjectEntity The record.
	 */
	private static function record(?array $authorization = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('b1a55000-0000-4000-8000-000000000002');
		$object->setObject(['title' => 'een aanvraag']);
		$object->setOwner('eigenaar');
		$object->setAuthorization($authorization);

		return $object;
	}//end record()

	/**
	 * Whether the caller may do the action, asked fresh.
	 *
	 * @param string       $action The action.
	 * @param ObjectEntity $object The record.
	 * @param bool         $declares Whether the schema declares break glass.
	 *
	 * @return bool The verdict.
	 */
	private function may(string $action, ObjectEntity $object, bool $declares = true): bool {
		return $this->handlerFor(userId: 'ana')->hasPermission(
			ask: (new PermissionAsk(schema: $this->schema(declares: $declares), action: $action))
				->askedBy(userId: 'ana', objectOwner: $object->getOwner())
				->about(object: $object, register: null)
		);
	}//end may()

	/**
	 * Take the grant as `ana`.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return void
	 */
	private function take(ObjectEntity $object): void {
		$this->handlerFor(userId: 'ana');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ana');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service(userSession: $session, groupManager: $this->createMock(IGroupManager::class))
			->take(object: $object, schema: $this->schema(), reason: 'burger aan de balie');
	}//end take()

	/**
	 * Without a take the handler refuses; after it, the record may be read.
	 *
	 * @return void
	 */
	public function testATakenGrantLetsTheHandlerRead(): void {
		$record = self::record();
		$this->assertFalse($this->may(action: 'read', object: $record));

		$this->take(object: $record);

		$this->assertTrue($this->may(action: 'read', object: $record));
		$this->assertContains(BreakGlassService::USED_ACTION, array_column($this->rows, 0));
	}//end testATakenGrantLetsTheHandlerRead()

	/**
	 * It is not a route to administration: a write under the grant is refused.
	 *
	 * @return void
	 */
	public function testAWriteUnderTheGrantIsRefused(): void {
		$record = self::record();
		$this->take(object: $record);

		$this->assertFalse($this->may(action: 'update', object: $record));
		$this->assertFalse($this->may(action: 'delete', object: $record));
	}//end testAWriteUnderTheGrantIsRefused()

	/**
	 * Four hours later the read is refused again.
	 *
	 * @return void
	 */
	public function testItEndsByItself(): void {
		$record = self::record();
		$this->take(object: $record);

		$this->now = self::NOW + 4 * 3600;
		$this->assertFalse($this->may(action: 'read', object: $record));
	}//end testItEndsByItself()

	/**
	 * A schema that no longer declares it no longer honours a take.
	 *
	 * @return void
	 */
	public function testAnUndeclaringSchemaHonoursNoTake(): void {
		$record = self::record();
		$this->take(object: $record);

		$this->assertFalse($this->may(action: 'read', object: $record, declares: false));
	}//end testAnUndeclaringSchemaHonoursNoTake()

	/**
	 * A deny beats the emergency grant, as it beats every grant.
	 *
	 * @return void
	 */
	public function testADenyBeatsTheGrant(): void {
		$record = self::record(authorization: ['deny' => ['read' => ['user:ana']]]);
		$this->take(object: $record);

		$this->assertFalse($this->may(action: 'read', object: $record));
	}//end testADenyBeatsTheGrant()
}//end class
