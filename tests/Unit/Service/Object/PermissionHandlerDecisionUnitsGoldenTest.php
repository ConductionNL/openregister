<?php

declare(strict_types=1);

/**
 * PermissionHandler decision-unit golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * allowed AND refused outcomes of the decision units that refactor touches:
 * transition authorisation, the inheritFromPublic cascade, the authorization
 * resolution (schema / register / object override / deny union), the
 * match-rule probe, the read-group extraction and the object cache scope.
 * Unexpected (non-array, non-string, non-bool) values are part of every table:
 * where they were refused they must stay refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\TransitionGate;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden outcomes of the PermissionHandler units refactored for complexity.
 */
class PermissionHandlerDecisionUnitsGoldenTest extends TestCase {
	/** @var array<string, list<string>> Group memberships per known user. */
	private array $groupsByUser = [];

	/** @var list<int>|\Throwable Register ids holding the schema, or the failure listing them. */
	private array|\Throwable $registerIds = [];

	/** @var array<int, mixed> Register authorization per register id; a Throwable fails the find. */
	private array $registerAuth = [];

	/** @var int|null|\Throwable First register of a schema. */
	private int|null|\Throwable $firstRegister = null;

	/** @var bool|\Throwable The tenant-wide inheritFromPublic default. */
	private bool|\Throwable $tenantDefault = true;

	/**
	 * Build a handler over the current test state.
	 *
	 * @return PermissionHandler
	 */
	private function handler(): PermissionHandler {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (array_key_exists($uid, $this->groupsByUser) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			fn (IUser $user): array => $this->groupsByUser[$user->getUID()]
		);

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			function (int $schemaId): array {
				if ($this->registerIds instanceof \Throwable) {
					throw $this->registerIds;
				}

				return $this->registerIds;
			}
		);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturnCallback(
			function (int $schemaId): ?int {
				if ($this->firstRegister instanceof \Throwable) {
					throw $this->firstRegister;
				}

				return $this->firstRegister;
			}
		);
		$registerMapper->method('find')->willReturnCallback(
			function (string|int $id): Register {
				$auth = ($this->registerAuth[(int)$id] ?? null);
				if ($auth instanceof \Throwable) {
					throw $auth;
				}

				$register = new Register();
				$register->setId((int)$id);
				$register->setAuthorization($auth);
				return $register;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				RegisterMapperInterface::class => $registerMapper,
				default => throw new \RuntimeException('not wired: ' . $id),
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			function (): bool {
				if ($this->tenantDefault instanceof \Throwable) {
					throw $this->tenantDefault;
				}

				return $this->tenantDefault;
			}
		);

		return PermissionHandlerFactory::create(
			$this->createMock(IUserSession::class),
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$container,
			null
		);
	}//end handler()

	/**
	 * Call a private method of a handler.
	 *
	 * @param PermissionHandler $handler The handler
	 * @param string            $method  The method
	 * @param array             $args    Positional arguments
	 *
	 * @return mixed
	 */
	private function call(PermissionHandler $handler, string $method, array $args): mixed {
		return (new ReflectionClass($handler))->getMethod($method)->invokeArgs($handler, $args);
	}//end call()

	/**
	 * A schema with an id and an authorization block.
	 *
	 * @param mixed    $authorization The block
	 * @param int|null $id            The schema id
	 *
	 * @return Schema
	 */
	private function schema(mixed $authorization, ?int $id = 7): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	/**
	 * Transition authorisation: every allowed and refused arm.
	 *
	 * @return void
	 */
	public function testTransitionAuthorisationTable(): void {
		$this->groupsByUser = [
			'alice' => ['editors', 'staff'],
			'root'  => ['admin'],
			'nobody' => [],
		];
		$roles = ['roles' => ['reviewer' => ['staff', 5], 'ghost' => 'editors', 7 => ['editors'], 'none' => []]];
		$cases = [
			'empty list refuses'               => [[], 'alice', [], false],
			'anonymous null refuses'           => [['editors'], null, [], false],
			'anonymous empty refuses'          => [['editors'], '', [], false],
			'unknown user refuses'             => [['editors'], 'mallory', [], false],
			'admin bypass allows'              => [['nope'], 'root', [], true],
			'literal member allows'            => [['nope', 'staff'], 'alice', [], true],
			'literal non-member refuses'       => [['nope'], 'alice', [], false],
			'empty literal refuses'            => [[''], 'nobody', [], false],
			'int entry refuses'                => [[5], 'alice', [], false],
			'role to member group allows'      => [[['role' => 'reviewer']], 'alice', $roles, true],
			'scalar role groups cast allows'   => [[['role' => 'ghost']], 'alice', $roles, true],
			'unknown role refuses'             => [[['role' => 'other']], 'alice', $roles, false],
			'empty role refuses'               => [[['role' => 'none']], 'alice', $roles, false],
			'non-string role refuses'          => [[['role' => 7]], 'alice', $roles, false],
			'role without roles block refuses' => [[['role' => 'reviewer']], 'alice', ['read' => ['x']], false],
			'roles not array refuses'          => [[['role' => 'reviewer']], 'alice', ['roles' => 'x'], false],
			'group key is not a role refuses'  => [[['group' => 'staff']], 'alice', $roles, false],
			'non-member role then literal'     => [[['role' => 'other'], 'editors'], 'alice', $roles, true],
		];
		foreach ($cases as $label => [$list, $userId, $schemaAuth, $expected]) {
			$this->assertSame(
				$expected,
				$this->handler()->isTransitionAuthorized(gate: new TransitionGate(authorizationList: $list, schema: $this->schema($schemaAuth)), userId: $userId),
				$label
			);
		}
	}//end testTransitionAuthorisationTable()

	/**
	 * The inheritFromPublic cascade: schema, registers (most restrictive wins), tenant default.
	 *
	 * @return void
	 */
	public function testInheritFromPublicCascade(): void {
		$cases = [
			'schema true'                      => [['inheritFromPublic' => true], [1], [1 => ['inheritFromPublic' => false]], false, true],
			'schema false'                     => [['inheritFromPublic' => false], [], [], true, false],
			'schema non-bool falls to register' => [['inheritFromPublic' => 'yes'], [1], [1 => ['inheritFromPublic' => true]], false, true],
			'register true'                    => [[], [1, 2], [1 => ['inheritFromPublic' => true], 2 => ['read' => []]], false, true],
			'register false wins'              => [[], [1, 2], [1 => ['inheritFromPublic' => true], 2 => ['inheritFromPublic' => false]], true, false],
			'register non-bool is unset'       => [[], [1], [1 => ['inheritFromPublic' => 1]], false, false],
			'register null auth is unset'      => [[], [1], [], true, true],
			'register unresolvable refuses'    => [[], [1, 2], [1 => ['inheritFromPublic' => true], 2 => new \RuntimeException('down')], true, false],
			'listing failure uses default'     => [[], new \RuntimeException('down'), [], false, false],
			'no registers uses default'        => [[], [], [], true, true],
			'schema auth not array'            => ['x', [1], [1 => ['inheritFromPublic' => false]], true, false],
		];
		foreach ($cases as $label => [$schemaAuth, $registerIds, $registerAuth, $default, $expected]) {
			$this->registerIds = $registerIds;
			$this->registerAuth = $registerAuth;
			$this->tenantDefault = $default;
			$this->assertSame($expected, $this->handler()->resolveInheritFromPublic(schema: $this->schema($schemaAuth)), $label);
		}

		// A schema without an id skips the register cascade entirely.
		$this->registerIds = [1];
		$this->registerAuth = [1 => ['inheritFromPublic' => true]];
		$this->tenantDefault = false;
		$this->assertFalse($this->handler()->resolveInheritFromPublic(schema: $this->schema([], null)));

		// An unreachable tenant default assumes true.
		$this->registerIds = [];
		$this->tenantDefault = new \RuntimeException('config down');
		$this->assertTrue($this->handler()->resolveInheritFromPublic(schema: $this->schema([])));

		// The answer is cached per schema id for the handler's lifetime.
		$this->tenantDefault = true;
		$handler = $this->handler();
		$this->assertTrue($handler->resolveInheritFromPublic(schema: $this->schema([])));
		$this->tenantDefault = false;
		$this->assertTrue($handler->resolveInheritFromPublic(schema: $this->schema([])));
	}//end testInheritFromPublicCascade()

	/**
	 * Authorization resolution: schema, register fallback, object override and deny union.
	 *
	 * @return void
	 */
	public function testResolveAuthorizationRaw(): void {
		$schemaAuth = ['read' => ['staff'], 'update' => ['editors'], 'deny' => ['update' => ['interns']]];

		$this->assertSame($schemaAuth, $this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema($schemaAuth), null]));

		// No schema block: the first register's block, or null.
		$this->firstRegister = 3;
		$this->registerAuth = [3 => ['read' => ['public']]];
		$this->assertSame(['read' => ['public']], $this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema([]), null]));
		$this->registerAuth = [3 => []];
		$this->assertNull($this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema([]), null]));
		$this->firstRegister = null;
		$this->assertNull($this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema([]), null]));

		// An object override replaces per action; deny unions.
		$object = new ObjectEntity();
		$object->setAuthorization(['update' => ['admin'], 'deny' => ['delete' => ['staff']]]);
		$merged = $this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema($schemaAuth), $object]);
		$this->assertSame(['staff'], $merged['read']);
		$this->assertSame(['admin'], $merged['update']);
		$this->assertSame(['update' => ['interns'], 'delete' => ['staff']], $merged['deny']);

		// An object override without a baseline is the override alone.
		$alone = $this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema([]), $object]);
		$this->assertSame(['update' => ['admin'], 'deny' => ['delete' => ['staff']]], $alone);

		// An object without its own block inherits the baseline.
		$plain = new ObjectEntity();
		$this->assertSame($schemaAuth, $this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema($schemaAuth), $plain]));

		// No deny anywhere: no deny key is added.
		$noDeny = new ObjectEntity();
		$noDeny->setAuthorization(['update' => ['admin']]);
		$this->assertSame(
			['read' => ['staff'], 'update' => ['admin']],
			$this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema(['read' => ['staff'], 'update' => ['x']]), $noDeny])
		);

		// A register lookup failure stays a refusal (exception), not an open block.
		$this->firstRegister = new \RuntimeException('down');
		$this->expectException(\OCA\OpenRegister\Exception\AuthorizationUnresolvableException::class);
		$this->call($this->handler(), 'resolveAuthorizationRaw', [$this->schema([]), null]);
	}//end testResolveAuthorizationRaw()

	/**
	 * The small probes: match rule, read groups, object cache scope.
	 *
	 * @return void
	 */
	public function testProbeTables(): void {
		$handler = $this->handler();
		$matchCases = [
			[null, false],
			[[], false],
			[['read' => 'x'], false],
			[['read' => ['staff', ['group' => 'g']]], false],
			[['read' => [['group' => 'g', 'match' => []]]], false],
			[['read' => [['group' => 'g', 'match' => ['a' => 1]]]], true],
			[['x' => 1, 'update' => [['match' => '0']]], false],
			[['update' => [['match' => 'yes']]], true],
		];
		foreach ($matchCases as $i => [$auth, $expected]) {
			$this->assertSame($expected, $this->call($handler, 'schemaHasMatchRule', [$this->schema($auth)]), "match case $i");
		}

		$readCases = [
			[null, null],
			[[], null],
			[['update' => ['x']], null],
			[['read' => 'staff'], null],
			[['read' => []], null],
			[['read' => ['staff', 'staff', ['group' => 'g'], ['group' => 5], 7, ['x' => 'y']]], ['staff', 2 => 'g']],
			[['read' => ['staff', 'public']], null],
			[['read' => [['group' => 'admin']]], null],
		];
		foreach ($readCases as $i => [$auth, $expected]) {
			$this->assertSame($expected, $this->call($handler, 'resolveReadGroupIds', [$auth]), "read case $i");
		}

		$this->assertSame('_', $this->call($handler, 'objectCacheScope', [null]));
		$object = new ObjectEntity();
		$this->assertNull($this->call($handler, 'objectCacheScope', [$object]));
		$object->setUuid('');
		$this->assertNull($this->call($handler, 'objectCacheScope', [$object]));
		$object->setUuid('u-1');
		$this->assertSame('u-1', $this->call($handler, 'objectCacheScope', [$object]));
		$object->setAuthorization(['read' => ['x']]);
		$this->assertNull($this->call($handler, 'objectCacheScope', [$object]));
	}//end testProbeTables()
}//end class
