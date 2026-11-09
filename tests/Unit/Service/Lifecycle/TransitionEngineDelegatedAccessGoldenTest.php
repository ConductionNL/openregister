<?php

/**
 * Golden test pinning the access outcomes of the delegated transition modes.
 *
 * Written against TransitionEngine before the arguments of its graph- and
 * provider-mode helpers were grouped. It pins, for both modes, that a caller
 * without the `update` right is refused before anything is written or
 * executed, that an allowed caller writes (graph) or re-reads (provider) with
 * RBAC and the organisation filter ON, that the system path writes or
 * re-reads with both OFF and never asks the permission handler, and that the
 * transitioned event names the action, the from and to states and the caller.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

final class TransitionEngineDelegatedAccessGoldenTest extends TestCase {

	private const PARENT = '00000000-0000-0000-0000-000000000000';

	private const S1 = '00000000-0000-0000-0000-000000000001';

	private const S2 = '00000000-0000-0000-0000-000000000002';

	private const S3 = '00000000-0000-0000-0000-000000000003';

	private const CASE = '00000000-0000-0000-0000-0000000000aa';

	private const TAG = 'OCA\\Dossiq\\Lifecycle\\CaseActionProvider';

	/**
	 * Every saveObject() call, by parameter name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every find() call, by parameter name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * Every permission question asked.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Every transitioned event, as [action, from, to, userId].
	 *
	 * @var array<int, array<int, string|null>>
	 */
	private array $events = [];

	/**
	 * Every provider execute() call, as [userId, action, data].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $executed = [];

	public function testGraphAllowedWritesWithRbacAndTenancyOn(): void {
		$result = $this->graphEngine(allowed: true)->transition(self::CASE, 'move-to-' . self::S2);

		$this->assertSame(self::S2, $result->getObject()['status']);
		$this->assertSame(['update'], $this->asked);
		$this->assertCount(1, $this->saves);
		$this->assertTrue($this->saves[0]['_rbac']);
		$this->assertTrue($this->saves[0]['_multitenancy']);
		$written = array_intersect_key($this->saves[0]['object'], ['status' => 1, 'caseType' => 1]);
		$this->assertSame(['caseType' => self::PARENT, 'status' => self::S2], $written);
		$this->assertSame([['move-to-' . self::S2, self::S1, self::S2, 'alice']], $this->events);
	}

	public function testGraphRefusedCallerWritesNothing(): void {
		try {
			$this->graphEngine(allowed: false)->transition(self::CASE, 'move-to-' . self::S2);
			$this->fail('A caller without the update right was not refused.');
		} catch (NotAuthorizedException $e) {
			$this->assertStringContainsString('permission', $e->getMessage());
		}

		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->events);
	}

	public function testGraphSystemPathWritesWithRbacAndTenancyOffAndAsksNothing(): void {
		$this->graphEngine(allowed: false)->transitionAsSystem(self::CASE, 'move-to-' . self::S2, 'procest');

		$this->assertSame([], $this->asked);
		$this->assertCount(1, $this->saves);
		$this->assertFalse($this->saves[0]['_rbac']);
		$this->assertFalse($this->saves[0]['_multitenancy']);
		$this->assertSame([['move-to-' . self::S2, self::S1, self::S2, 'alice']], $this->events);
	}

	public function testGraphNonCandidateIsRefusedWithoutAWrite(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is not allowed from the current state');

		try {
			$this->graphEngine(allowed: true)->transition(self::CASE, 'move-to-' . self::S3);
		} finally {
			$this->assertSame([], $this->saves);
			$this->assertSame([], $this->events);
		}
	}

	public function testGraphPayloadIsRefusedWithoutAWrite(): void {
		try {
			$this->graphEngine(allowed: true)->transition(self::CASE, 'move-to-' . self::S2, ['note' => 'x']);
			$this->fail('A payload on a graph move was accepted.');
		} catch (\Throwable $e) {
			$this->assertNotInstanceOf(NotAuthorizedException::class, $e);
		}

		$this->assertSame([], $this->saves);
	}

	public function testProviderAllowedReReadsWithRbacAndTenancyOn(): void {
		$this->providerEngine(allowed: true)->transition(self::CASE, 'lc-start', ['resultTypeId' => 'rt-1']);

		$this->assertSame(['update'], $this->asked);
		$this->assertSame([['alice', 'lc-start', ['resultTypeId' => 'rt-1']]], $this->executed);
		$this->assertCount(2, $this->finds);
		$this->assertTrue($this->finds[1]['_rbac']);
		$this->assertTrue($this->finds[1]['_multitenancy']);
		$this->assertSame([], $this->saves);
		$this->assertSame([['lc-start', 'in-behandeling', 'afgehandeld', 'alice']], $this->events);
	}

	public function testProviderRefusedCallerIsNeverHandedToTheProvider(): void {
		try {
			$this->providerEngine(allowed: false)->transition(self::CASE, 'lc-start');
			$this->fail('A caller without the update right was not refused.');
		} catch (NotAuthorizedException $e) {
			$this->assertStringContainsString('permission', $e->getMessage());
		}

		$this->assertSame([], $this->executed);
		$this->assertSame([], $this->events);
	}

	public function testProviderSystemPathReReadsWithRbacAndTenancyOff(): void {
		$this->providerEngine(allowed: false)->transitionAsSystem(self::CASE, 'lc-start', 'procest');

		$this->assertSame([], $this->asked);
		$this->assertCount(1, $this->executed);
		$this->assertCount(2, $this->finds);
		$this->assertFalse($this->finds[1]['_rbac']);
		$this->assertFalse($this->finds[1]['_multitenancy']);
		$this->assertSame([['lc-start', 'in-behandeling', 'afgehandeld', 'alice']], $this->events);
	}

	private function graphEngine(bool $allowed): TransitionEngine {
		$case = $this->caseObject(['caseType' => self::PARENT, 'status' => self::S1]);
		$objects = $this->objectService(finds: [$case]);
		$objects->method('findAll')->willReturn(
			[
				$this->statusType(self::S3, 3, true),
				$this->statusType(self::S1, 1, false),
				$this->statusType(self::S2, 2, false),
			]
		);

		return $this->engine(
			objects: $objects,
			annotation: [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			allowed: $allowed,
			registry: $this->createMock(LifecycleActionProviderRegistry::class)
		);
	}

	private function providerEngine(bool $allowed): TransitionEngine {
		$before = $this->caseObject(['caseType' => 'ct-1', 'status' => 'in-behandeling']);
		$after = $this->caseObject(['caseType' => 'ct-1', 'status' => 'afgehandeld']);

		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$provider->method('execute')->willReturnCallback(
			function (array $object, string $userId, string $action, array $data): array {
				$this->executed[] = [$userId, $action, $data];

				return [];
			}
		);
		$registry = $this->createMock(LifecycleActionProviderRegistry::class);
		$registry->method('resolve')->with(self::TAG)->willReturn($provider);

		return $this->engine(
			objects: $this->objectService(finds: [$before, $after]),
			annotation: [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
				'provider' => self::TAG,
			],
			allowed: $allowed,
			registry: $registry
		);
	}

	/**
	 * An object service double recording find() and saveObject() by parameter name.
	 *
	 * @param array<int, ObjectEntity> $finds What successive find() calls answer.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $finds): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use (&$finds): ?ObjectEntity {
				$this->finds[] = $this->named('find', $args);

				return (array_shift($finds) ?? null);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$call = $this->named('saveObject', $args);
				$this->saves[] = $call;

				return $this->caseObject((array)$call['object']);
			}
		);

		return $objects;
	}

	/**
	 * Name the arguments of one ObjectService call.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments PHPUnit handed over.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod(ObjectService::class, $method))->getParameters() as $index => $param) {
			$out[$param->getName()] = ($args[$index] ?? null);
		}

		return $out;
	}

	private function engine(ObjectService $objects, array $annotation, bool $allowed, LifecycleActionProviderRegistry $registry): TransitionEngine {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => $annotation]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action) use ($allowed): bool {
				$this->asked[] = $action;

				return $allowed;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				if ($event instanceof ObjectTransitionedEvent) {
					$this->events[] = [$event->getAction(), $event->getFrom(), $event->getTo(), $event->getUserId()];
				}
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new TransitionEngine(
			new \OCA\OpenRegister\Service\Lifecycle\TransitionSubjects($objects, $schemas, $this->createMock(RegisterMapper::class)),
			new \OCA\OpenRegister\Service\Lifecycle\TransitionDispatch($dispatcher, new LifecycleWriteBoundary(new LifecycleActionContext()), $registry),
			new \OCA\OpenRegister\Service\Lifecycle\TransitionPlatform($session, $appConfig, new NullLogger()),
			$permissions
		);
	}

	private function caseObject(array $data): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid(self::CASE);
		$case->setSchema('case');
		$case->setRegister('1');
		unset($data['id']);
		$case->setObject($data);

		return $case;
	}

	private function statusType(string $uuid, int $order, bool $final): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['caseType' => self::PARENT, 'order' => $order, 'isFinal' => $final]);

		return $entity;
	}
}
