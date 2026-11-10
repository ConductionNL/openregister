<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A cascade creates a nested object only when the caller may create objects in its schema.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\CascadeTarget;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;

/**
 * The acting user is `mallory`, a clerk. Only editors may create notes. `alice` is an editor:
 * the group lookup answers `editors` for her alone, so a check made for anybody but the
 * signed-in caller would pass where it must refuse.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @covers \OCA\OpenRegister\Service\Object\CascadingHandler
 * @uses   \OCA\OpenRegister\Service\Object\CascadeTarget
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 */
class CascadeCreateRightsTest extends TestCase {

	private const CREATED = '33333333-3333-3333-3333-333333333333';

	/**
	 * The nested saves that reached the save handler, by data.
	 *
	 * @var array<int, array>
	 */
	private array $saves = [];

	/**
	 * The `note` schema: editors create, everyone signed in reads.
	 *
	 * @return Schema
	 */
	private static function note(): Schema {
		$note = new Schema();
		$note->setId(2);
		$note->setUuid('note-schema');
		$note->setSlug('note');
		$note->setTitle('Note');
		$note->setProperties(['text' => ['type' => 'string']]);
		$note->setAuthorization(['create' => ['editors'], 'read' => ['authenticated']]);

		return $note;
	}//end note()

	/**
	 * A real permission handler for signed-in `mallory`, whose groups answer per user.
	 *
	 * @param array<string, list<string>> $groups The groups of each user id.
	 *
	 * @return PermissionHandler
	 */
	private function permissions(array $groups = ['mallory' => ['clerks'], 'alice' => ['editors']]): PermissionHandler {
		$users = [];
		foreach (array_keys($groups) as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($uid);
			$users[$uid] = $user;
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($users['mallory']);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => $groups[$user->getUID()] ?? []
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not available'));

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$session,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$container
		);
	}//end permissions()

	/**
	 * A save handler whose own nested save only records what it is asked to write.
	 *
	 * @param PermissionHandler $permissions The permission handler it asks.
	 *
	 * @return SaveObject
	 */
	private function saveHandler(PermissionHandler $permissions, ?LoggerInterface $logger = null): SaveObject {
		$handler = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$handler->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data): ObjectEntity {
				if (($data['text'] ?? null) === 'boom') {
					throw new Exception('storage');
				}

				$this->saves[] = $data;
				$created = new ObjectEntity();
				$created->setUuid(self::CREATED);
				return $created;
			}
		);

		$reflection = new ReflectionClass(SaveObject::class);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => self::note()]);
		$reflection->getProperty('urlGenerator')->setValue($handler, $this->createMock(IURLGenerator::class));
		$reflection->getProperty('permissionHandler')->setValue($handler, $permissions);
		$reflection->getProperty('logger')->setValue($handler, $logger ?? new NullLogger());

		return $handler;
	}//end saveHandler()

	/**
	 * Run one private SaveObject method on a parent `p-1` owned by `alice`.
	 *
	 * @param SaveObject $handler The save handler.
	 * @param string     $method  The method name.
	 * @param array      $args    The arguments after the parent.
	 *
	 * @return mixed What the method returns.
	 */
	private static function onParent(SaveObject $handler, string $method, array $args): mixed {
		$parent = new ObjectEntity();
		$parent->setUuid('p-1');
		$parent->setRegister('10');
		$parent->setOwner('alice');

		$run = new CascadeRun();
		$run->parent = $parent;
		$run->data = ($args[1] ?? []);
		$run->stored = ($args[2] ?? []);
		$call = match ($method) {
			'cascadeObjects' => [$run, $args[0]],
			default => [new NestedObjectTarget(parent: $parent, definition: $args[0]), $args[1]],
		};

		return (new ReflectionClass(SaveObject::class))->getMethod($method)->invoke($handler, ...$call);
	}//end onParent()

	/**
	 * Cascade one nested note through SaveObject::cascadeSingleObject.
	 *
	 * @param SaveObject $handler The save handler.
	 *
	 * @return string|null The created uuid.
	 */
	private static function cascadeNote(SaveObject $handler): ?string {
		return self::onParent(
			handler: $handler,
			method: 'cascadeSingleObject',
			args: [['$ref' => 'note', 'inversedBy' => 'incident'], ['text' => 'new', '@self' => ['owner' => 'alice']]]
		);
	}//end cascadeNote()

	public function testANestedObjectTheCallerMayNotCreateIsRefused(): void {
		$handler = $this->saveHandler(permissions: $this->permissions());

		try {
			self::cascadeNote(handler: $handler);
			$this->fail('A clerk created a note through a cascade.');
		} catch (NotAuthorizedException $e) {
			$this->assertStringContainsString(needle: "'mallory'", haystack: $e->getMessage());
			$this->assertStringContainsString(needle: "'create'", haystack: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $this->saves);
	}

	public function testANestedObjectTheCallerMayCreateIsCreated(): void {
		$handler = $this->saveHandler(permissions: $this->permissions(groups: ['mallory' => ['editors'], 'alice' => ['clerks']]));

		$this->assertSame(expected: self::CREATED, actual: self::cascadeNote(handler: $handler));
		$this->assertCount(expectedCount: 1, haystack: $this->saves);
	}

	public function testAnEnclosingSaveWithRbacOffSkipsTheCheck(): void {
		$handler = $this->saveHandler(permissions: $this->permissions());
		$handler->enterCascadeScope(_rbac: false);

		$this->assertSame(expected: self::CREATED, actual: self::cascadeNote(handler: $handler));
	}

	public function testANestedSaveWithRbacOnInsideASaveWithRbacOffSkipsTheCheck(): void {
		$handler = $this->saveHandler(permissions: $this->permissions());
		$handler->enterCascadeScope(_rbac: false);
		$handler->enterCascadeScope(_rbac: true);

		$this->assertSame(expected: self::CREATED, actual: self::cascadeNote(handler: $handler));
	}

	public function testAScopeWithRbacOnStillChecks(): void {
		$handler = $this->saveHandler(permissions: $this->permissions());
		$handler->enterCascadeScope(_rbac: true);

		$this->expectException(NotAuthorizedException::class);
		self::cascadeNote(handler: $handler);
	}

	public function testALeftScopeNoLongerSkipsTheCheck(): void {
		$handler = $this->saveHandler(permissions: $this->permissions());
		$handler->enterCascadeScope(_rbac: true);
		$handler->enterCascadeScope(_rbac: false);
		$handler->leaveCascadeScope();

		$this->expectException(NotAuthorizedException::class);
		self::cascadeNote(handler: $handler);
	}

	public function testTheRefusalOfAnInlineObjectPropertyRefusesTheParent(): void {
		$parentSchema = new Schema();
		$parentSchema->setId(1);
		$parentSchema->setProperties(['note' => ['type' => 'object', '$ref' => 'note', 'inversedBy' => 'incident']]);

		$this->expectException(NotAuthorizedException::class);
		self::onParent(
			handler: $this->saveHandler(permissions: $this->permissions()),
			method: 'cascadeObjects',
			args: [$parentSchema, ['note' => ['text' => 'new']], []]
		);
	}

	public function testTheRefusalOfAnInlineObjectInAListRefusesTheParent(): void {
		$parentSchema = new Schema();
		$parentSchema->setId(1);
		$parentSchema->setProperties(['notes' => ['type' => 'array', 'items' => ['$ref' => 'note', 'inversedBy' => 'incident']]]);

		$this->expectException(NotAuthorizedException::class);
		self::onParent(
			handler: $this->saveHandler(permissions: $this->permissions()),
			method: 'cascadeObjects',
			args: [$parentSchema, ['notes' => [['text' => 'new']]], []]
		);
	}

	public function testAnotherFailureOfAnInlineObjectStillLeavesTheParentAsSent(): void {
		$parentSchema = new Schema();
		$parentSchema->setId(1);
		$parentSchema->setProperties(['note' => ['type' => 'object', '$ref' => 'note', 'inversedBy' => 'incident']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Cascade of an object property failed'),
			$this->callback(static fn (array $context): bool => $context['property'] === 'note' && $context['exception'] === 'storage')
		);
		$handler = $this->saveHandler(permissions: $this->permissions(groups: ['mallory' => ['editors'], 'alice' => ['clerks']]), logger: $logger);

		$data = self::onParent(handler: $handler, method: 'cascadeObjects', args: [$parentSchema, ['note' => ['text' => 'boom']], []]);

		$this->assertSame(expected: ['note' => ['text' => 'boom']], actual: $data);
	}

	/**
	 * A cascading handler over `note`, whose save handler refuses or records.
	 *
	 * @param Exception|null $refusal What the create check throws, null to allow.
	 * @param Exception|null $failure What the save throws, null to succeed.
	 *
	 * @return CascadingHandler
	 */
	private function cascadingHandler(?Exception $refusal, ?Exception $failure = null): CascadingHandler {
		$note = self::note();
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$note]);

		$save = $this->createMock(SaveObject::class);
		$check = $save->expects($this->once())->method('assertMayCascadeCreate')->with($note);
		if ($refusal !== null) {
			$check->willThrowException($refusal);
		}

		$save->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data) use ($failure): ObjectEntity {
				if ($failure !== null) {
					throw $failure;
				}

				$this->saves[] = $data;
				$created = new ObjectEntity();
				$created->setUuid(self::CREATED);
				return $created;
			}
		);

		return new CascadingHandler(
			saveHandler: $save,
			schemaMapper: $schemas,
			utilityHandler: $this->createMock(UtilityHandler::class),
			logger: new NullLogger()
		);
	}//end cascadingHandler()

	/**
	 * Create one nested note through CascadingHandler::createRelatedObject.
	 *
	 * @param CascadingHandler $handler The cascading handler.
	 *
	 * @return string|null The created uuid.
	 */
	private static function relatedNote(CascadingHandler $handler): ?string {
		return $handler->createRelatedObject(objectData: ['text' => 'new'], target: (new CascadeTarget(parentUuid: 'p-1', register: 10))->with(definition: ['$ref' => '#/components/schemas/note', 'inversedBy' => 'incident']));
	}//end relatedNote()

	public function testThePreValidationCascadeRefusesWhatTheCallerMayNotCreate(): void {
		$handler = $this->cascadingHandler(refusal: new NotAuthorizedException(message: 'no'));

		try {
			self::relatedNote(handler: $handler);
			$this->fail('The refusal was swallowed.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame(expected: 'no', actual: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $this->saves);
	}

	public function testThePreValidationCascadeCreatesWhatTheCallerMayCreate(): void {
		$this->assertSame(expected: self::CREATED, actual: self::relatedNote(handler: $this->cascadingHandler(refusal: null)));
		$this->assertSame(expected: [['text' => 'new', 'incident' => 'p-1']], actual: $this->saves);
	}

	public function testThePreValidationCascadeFindsTheSchemaInAnyCase(): void {
		$created = $this->cascadingHandler(refusal: null)->createRelatedObject(objectData: ['text' => 'new'], target: (new CascadeTarget(parentUuid: 'p-1', register: 10))->with(definition: ['$ref' => '#/components/schemas/NOTE', 'inversedBy' => 'incident']));

		$this->assertSame(expected: self::CREATED, actual: $created);
	}

	public function testAnotherFailureInThePreValidationCascadeStillAnswersNull(): void {
		$handler = $this->cascadingHandler(refusal: null, failure: new Exception('storage'));

		$this->assertNull(self::relatedNote(handler: $handler));
	}
}//end class
