<?php

/**
 * PermissionHandler caches, reports, elevates and filters as recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ActionEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Walks hasPermission() repeats, the events it fires, the bulk filter and the reader list.
 *
 * The recording was made on the code before these units were split.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\ActionEvaluatedEvent
 * @uses   \OCA\OpenRegister\Event\CustomScopeEvaluatedEvent
 * @uses   \OCA\OpenRegister\Event\CustomScopeEvaluatingEvent
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class PermissionHandlerDecisionFlowGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/permission-handler/decision-flow-golden.json';

	private const GROUPS = ['alice' => ['editors'], 'root' => ['admin'], 'bob' => ['staff']];

	/**
	 * What the current case logged and dispatched.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	/**
	 * The blocks hasPermission() runs.
	 *
	 * @return array<string, array|null>
	 */
	private static function blocks(): array {
		return [
			'none'     => null,
			'grants'   => ['read' => ['editors'], 'update' => ['staff'], 'approve' => ['editors']],
			'match'    => ['read' => [['group' => 'editors', 'match' => ['pass' => true]]]],
			'deny'     => ['read' => ['editors'], 'deny' => ['read' => ['editors']]],
			'private'  => ['scope' => 'private', 'read' => ['editors']],
		];
	}//end blocks()

	private function users(): array {
		$users = [];
		foreach (array_keys(self::GROUPS) as $uid) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[$uid] = $user;
		}

		return $users;
	}//end users()

	private function dispatcher(array $world): IEventDispatcher {
		$dispatcher = $this->createStub(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($world): void {
				$this->trace[] = $this->described($event);
				$listener = ($world['listener'] ?? 'none');
				if ($event instanceof CustomScopeEvaluatingEvent === true) {
					match ($listener) {
						'allow' => $event->allow(),
						'deny'  => $event->deny(),
						'throw' => throw new RuntimeException('listener down'),
						default => null,
					};
				}

				if ($listener === 'telemetry down' && $event instanceof CustomScopeEvaluatingEvent === false) {
					throw new RuntimeException('telemetry down');
				}
			}
		);

		return $dispatcher;
	}//end dispatcher()

	private function described(Event $event): array {
		if ($event instanceof ActionEvaluatedEvent === true) {
			return ['action event', $event->getAction(), $event->isGranted(), $event->getActor(), $event->getObject()?->getUuid()];
		}

		if ($event instanceof CustomScopeEvaluatedEvent === true) {
			return ['scope event', $event->getAction(), $event->getUserId(), $event->getVerdict(), $event->isFromListener()];
		}

		if ($event instanceof CustomScopeEvaluatingEvent === true) {
			return ['scope ask', $event->getAction(), $event->getUserId(), $event->getUserGroups(), $event->getObject()?->getUuid()];
		}

		return [get_class($event)];
	}//end described()

	private function container(array $world): ContainerInterface {
		$registers = $this->createStub(RegisterMapper::class);
		if (($world['broken registers'] ?? false) === true) {
			$registers->method('getFirstRegisterWithSchema')->willReturn(7);
			$registers->method('find')->willThrowException(new RuntimeException('down'));
		}

		$organisations = $this->createStub(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn(($world['orgs'] ?? []));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === RegisterMapperInterface::class ? $registers : $organisations)
		);

		return $container;
	}//end container()

	private function schemas(array $world): SchemaMapper {
		$schemas = $this->createStub(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id) use ($world): Schema {
				if ((string) $id === '404') {
					throw new DoesNotExistException('gone');
				}

				if ((string) $id === '500') {
					throw new RuntimeException('down');
				}

				$schema = new Schema();
				$schema->setId((int) $id);
				$schema->setAuthorization(($world['block'] ?? null));

				return $schema;
			}
		);

		return $schemas;
	}//end schemas()

	private function newHandler(array $world): PermissionHandler {
		$users   = $this->users();
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn(($users[$world['session'] ?? ''] ?? null));
		$manager = $this->createStub(IUserManager::class);
		$manager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($users[$uid] ?? null));
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => self::GROUPS[$user->getUID()]);
		$editors = $this->createStub(IGroup::class);
		$editors->method('getUsers')->willReturn([$users['alice'], $users['bob']]);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ($gid === 'editors' ? $editors : null));

		$matcher = $this->createStub(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			static fn (array $object, array $match): bool => (($object['title'] ?? '') === 'x')
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (str_contains($key, 'deny') === true ? 'enforcing' : $default)
		);

		$mapper = $this->createStub(MagicMapper::class);
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn(($world['objects'] ?? []));

		$logger = $this->createStub(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->trace[] = [$level, $message];
				}
			);
		}

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $manager,
			groupManager: $groups,
			schemaMapper: $this->schemas($world),
			objectEntityMapper: $mapper,
			conditionMatcher: $matcher,
			appConfig: $appConfig,
			logger: $logger,
			container: $this->container($world),
			eventDispatcher: (isset($world['listener']) === true ? $this->dispatcher($world) : null)
		);
	}//end newHandler()

	private function objectOf(string $kind): ?ObjectEntity {
		if ($kind === 'none') {
			return null;
		}

		$object = new ObjectEntity();
		$object->setUuid(($kind === 'no uuid' ? null : 'u-1'));
		$object->setRegister('3');
		$object->setObject(['title' => 'x']);
		$object->setOwner('bob');
		if ($kind === 'own block') {
			$object->setAuthorization(['read' => ['staff']]);
		}

		if ($kind === 'draft') {
			$object->setStatus('draft');
		}

		return $object;
	}//end objectOf()

	/**
	 * The call's answer, or the class it threw, with what it logged and dispatched.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{answer: mixed, trace: array}
	 */
	private function answer(callable $call): array {
		$this->trace = [];
		try {
			$answer = $call();
		} catch (Throwable $e) {
			$answer = get_class($e);
		}

		return ['answer' => $answer, 'trace' => $this->trace];
	}//end answer()

	/**
	 * Three identical questions to one handler, a changed object between the second and third.
	 *
	 * @param array $world The handler's world.
	 * @param array $ask   The block, action, user, owner, object kind and register.
	 *
	 * @return array The answers.
	 */
	private function repeats(array $world, array $ask): array {
		$handler = $this->newHandler($world);
		$schema  = new Schema();
		$schema->setId(($ask['no schema id'] ?? false) === true ? null : 5);
		$schema->setAuthorization($ask['block']);
		$object = $this->objectOf($ask['object']);
		$call   = static fn (): bool => $handler->hasPermission(
			schema: $schema,
			action: $ask['action'],
			userId: $ask['user'],
			objectOwner: $ask['owner'],
			_rbac: $ask['rbac'],
			object: $object,
			register: $ask['register']
		);

		$answers = [$this->answer($call), $this->answer($call)];
		$object?->setObject(['title' => 'y']);
		$answers[] = $this->answer($call);

		return $answers;
	}//end repeats()

	/**
	 * Every hasPermission() case for one block.
	 *
	 * @param array|null $block The block.
	 *
	 * @return array<string, string>
	 */
	private function recordDecisions(?array $block): array {
		$cases = [];
		foreach (['read', 'update', 'approve'] as $action) {
			foreach (['none', 'plain', 'no uuid', 'own block', 'draft'] as $kind) {
				foreach (['none', 'allow', 'deny', 'throw', 'telemetry down', 'no dispatcher'] as $listener) {
					$answers = [];
					foreach ([[null, 'alice'], ['alice', 'alice'], ['bob', null], [null, null], ['root', null], [null, 'root'], ['ghost', 'alice']] as [$user, $session]) {
						foreach ([null, 'alice'] as $owner) {
							foreach ([null, '3'] as $register) {
								$world = ['session' => $session];
								if ($listener !== 'no dispatcher') {
									$world['listener'] = $listener;
								}

								$answers[] = $this->repeats(
									$world,
									['block' => $block, 'action' => $action, 'user' => $user, 'owner' => $owner, 'object' => $kind, 'register' => $register, 'rbac' => true]
								);
							}
						}
					}

					$cases["$action|$kind|$listener"] = sha1(json_encode($answers, JSON_THROW_ON_ERROR));
				}//end foreach
			}//end foreach
		}//end foreach

		return $cases;
	}//end recordDecisions()

	/**
	 * The frames around hasPermission(): no rbac, a system operation, an elevation, a schema without id.
	 *
	 * @return array<string, array>
	 */
	private function recordFrames(): array {
		$ask = ['block' => ['read' => ['staff']], 'action' => 'update', 'user' => 'alice', 'owner' => null, 'object' => 'plain', 'register' => null, 'rbac' => true];
		$world = ['session' => 'alice', 'listener' => 'none'];
		$entry = [['register' => '3', 'schema' => '5', 'verbs' => ['update']]];

		return [
			'no rbac'      => $this->repeats($world, array_merge($ask, ['rbac' => false])),
			'system'       => SystemOperationContext::run(fn (): array => $this->repeats($world, $ask)),
			'no schema id' => $this->repeats($world, array_merge($ask, ['no schema id' => true])),
			'elevated'     => ObjectActionElevationScope::run('a', 'alice', $entry, fn (): array => $this->repeats($world, $ask)),
			'elevated by register' => ObjectActionElevationScope::run(
				'a',
				'alice',
				$entry,
				fn (): array => $this->repeats($world, array_merge($ask, ['object' => 'none', 'register' => '3']))
			),
			'elevated deny' => ObjectActionElevationScope::run(
				'a',
				'alice',
				$entry,
				fn (): array => $this->repeats($world, array_merge($ask, ['block' => ['update' => ['editors'], 'deny' => ['update' => ['editors']]]]))
			),
			'elevated other register' => ObjectActionElevationScope::run(
				'a',
				'alice',
				$entry,
				fn (): array => $this->repeats($world, array_merge($ask, ['object' => 'none', 'register' => '4']))
			),
		];
	}//end recordFrames()

	private function bulkObject(string $uuid, array $fields): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['title' => 'x']);
		foreach ($fields as $name => $value) {
			$object->{'set'.ucfirst($name)}($value);
		}

		return $object;
	}//end bulkObject()

	/**
	 * What the bulk filter keeps.
	 *
	 * @return array<string, array>
	 */
	private function recordBulk(): array {
		$objects = [
			$this->bulkObject('editable', ['schema' => '5', 'owner' => 'bob']),
			$this->bulkObject('own', ['schema' => '5', 'owner' => 'alice']),
			$this->bulkObject('no schema', ['owner' => 'bob']),
			$this->bulkObject('schema gone', ['schema' => '404']),
			$this->bulkObject('org a', ['schema' => '5', 'owner' => 'alice', 'organisation' => 'org-a']),
			$this->bulkObject('org b', ['schema' => '5', 'owner' => 'alice', 'organisation' => 'org-b']),
			$this->bulkObject('draft', ['schema' => '5', 'owner' => 'bob', 'status' => 'draft']),
			$this->bulkObject('own draft', ['schema' => '5', 'owner' => 'alice', 'status' => 'draft']),
		];

		$cases = [];
		foreach ([null, 'alice', 'root', 'bob'] as $session) {
			foreach ([[], ['org-a']] as $orgs) {
				foreach (['none' => null, 'editors' => ['delete' => ['editors']]] as $blockName => $block) {
					foreach ([[true, true], [true, false], [false, true], [false, false]] as [$rbac, $tenancy]) {
						$world   = ['session' => $session, 'orgs' => $orgs, 'objects' => $objects, 'block' => $block];
						$handler = $this->newHandler($world);
						$name    = ($session ?? 'anon').'|'.($orgs[0] ?? 'no org')."|$blockName|".(int) $rbac.(int) $tenancy;
						$cases[$name] = $this->answer(
							static fn (): array => $handler->filterUuidsForPermissions(uuids: ['any'], _rbac: $rbac, _multitenancy: $tenancy)
						);
					}
				}
			}
		}

		$broken = $this->bulkObject('down', ['schema' => '500']);
		$handler = $this->newHandler(['session' => 'alice', 'objects' => [$broken]]);
		$cases['schema lookup down'] = $this->answer(
			static fn (): array => $handler->filterUuidsForPermissions(uuids: ['any'], _rbac: true, _multitenancy: false)
		);

		return $cases;
	}//end recordBulk()

	/**
	 * Who may read an object.
	 *
	 * @return array<string, array>
	 */
	private function recordReaders(): array {
		$cases = [];
		$blocks = [
			'none'   => null,
			'groups' => ['read' => ['editors']],
			'public' => ['read' => ['public']],
			'admin'  => ['read' => ['admin']],
			'empty'  => ['update' => ['editors']],
		];
		foreach ($blocks as $blockName => $block) {
			foreach (['bob', '', null] as $owner) {
				foreach (['5', '404', '500', null] as $schemaId) {
					$object = $this->bulkObject('r', ['owner' => $owner, 'schema' => $schemaId]);
					$handler = $this->newHandler(['block' => $block]);
					$cases["$blockName|".($owner ?? 'null').'|'.($schemaId ?? 'null')] = $this->answer(
						static fn (): array => $handler->getReadableByUsers($object)
					);
				}
			}
		}

		$object  = $this->bulkObject('r', ['owner' => 'bob', 'schema' => '5']);
		$handler = $this->newHandler(['broken registers' => true]);
		$cases['unresolvable'] = $this->answer(static fn (): array => $handler->getReadableByUsers($object));

		return $cases;
	}//end recordReaders()

	/**
	 * Every case.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		$recording = [];
		foreach (self::blocks() as $name => $block) {
			foreach ($this->recordDecisions($block) as $case => $hash) {
				$recording["decide|$name|$case"] = $hash;
			}
		}

		foreach (['frame' => $this->recordFrames(), 'bulk' => $this->recordBulk(), 'readers' => $this->recordReaders()] as $kind => $cases) {
			foreach ($cases as $case => $answer) {
				$recording["$kind|$case"] = $answer;
			}
		}

		return $recording;
	}//end record()

	public function testEveryFlowRunsAsRecorded(): void {
		$recording = $this->record();
		if (getenv('RECORD_GOLDEN') === 'decision-flow') {
			file_put_contents(self::GOLDEN, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame($golden, $recording);
	}//end testEveryFlowRunsAsRecorded()
}//end class
