<?php

/**
 * PermissionHandler::expandRoles() expands every recorded block as recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pins the expanded block, the warnings and the errors of every role grammar case against a recording.
 *
 * The recording was made on the code before expandRoles() was split.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\AuthorizationUnresolvableException
 * @uses   \OCA\OpenRegister\Service\Rbac\RoleBlockExpander
 */
class PermissionHandlerExpandRolesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/permission-handler/expand-roles-golden.json';

	/**
	 * What was logged during the current case.
	 *
	 * @var array<int, array>
	 */
	private array $logged = [];

	/**
	 * The register definitions the cases run against.
	 *
	 * @return array<string, array>
	 */
	private static function definitions(): array {
		$viewer = ['name' => 'viewer', 'actions' => ['read', 'list']];
		$editor = ['name' => 'editor', 'extends' => 'viewer', 'actions' => ['update', 'read']];

		return [
			'none'             => [],
			'flat'             => [$viewer, ['name' => 'writer', 'actions' => ['create', 'update']]],
			'extends string'   => [$viewer, $editor],
			'extends list'     => [$viewer, ['name' => 'writer', 'actions' => ['create']], ['name' => 'lead', 'extends' => ['writer', 'viewer'], 'actions' => ['delete']]],
			'extends chain'    => [$viewer, $editor, ['name' => 'lead', 'extends' => 'editor', 'actions' => ['delete']]],
			'extends unknown'  => [['name' => 'viewer', 'extends' => 'ghost', 'actions' => ['read']]],
			'extends invalid'  => [['name' => 'viewer', 'extends' => ['', 7, null, 'writer'], 'actions' => ['read']], ['name' => 'writer', 'actions' => ['create']]],
			'extends scalar'   => [['name' => 'viewer', 'extends' => 7, 'actions' => ['read']]],
			'extends false'    => [['name' => 'viewer', 'extends' => false, 'actions' => ['read']]],
			'cycle'            => [['name' => 'viewer', 'extends' => 'editor', 'actions' => ['read']], ['name' => 'editor', 'extends' => 'viewer', 'actions' => ['update']]],
			'self'             => [['name' => 'viewer', 'extends' => 'viewer', 'actions' => ['read']]],
			'diamond'          => [['name' => 'base', 'actions' => ['read']], ['name' => 'left', 'extends' => 'base', 'actions' => ['create']], ['name' => 'right', 'extends' => 'base', 'actions' => ['update']], ['name' => 'viewer', 'extends' => ['left', 'right'], 'actions' => ['delete']]],
			'no actions'       => [['name' => 'viewer']],
			'actions scalar'   => [['name' => 'viewer', 'actions' => 'read']],
			'actions mixed'    => [['name' => 'viewer', 'actions' => ['read', 7, null, 'read', 'list']]],
			'empty actions'    => [['name' => 'viewer', 'actions' => []]],
			'nameless'         => [['actions' => ['read']], 'viewer', $viewer],
			'numeric name'     => [['name' => '7', 'actions' => ['read']]],
			'duplicate name'   => [$viewer, ['name' => 'viewer', 'actions' => ['delete']]],
		];
	}//end definitions()

	/**
	 * The blocks the cases expand.
	 *
	 * @return array<string, array>
	 */
	private static function blocks(): array {
		$matched = ['group' => 'editors', 'match' => ['a' => 1]];

		return [
			'no roles'         => ['read' => ['public']],
			'empty'            => [],
			'roles not a list' => ['roles' => 'viewer', 'read' => ['public']],
			'roles empty'      => ['roles' => []],
			'one group'        => ['roles' => ['viewer' => 'editors']],
			'group list'       => ['roles' => ['viewer' => ['editors', 'editors', 'staff']]],
			'no groups'        => ['roles' => ['viewer' => []]],
			'complex group'    => ['roles' => ['viewer' => [$matched, $matched]]],
			'merge existing'   => ['read' => ['staff', 'editors'], 'roles' => ['viewer' => ['editors', 'other']]],
			'unknown role'     => ['roles' => ['ghost' => ['editors']]],
			'unknown + known'  => ['read' => ['x'], 'roles' => ['ghost' => ['a'], 'viewer' => ['b']]],
			'two roles'        => ['roles' => ['viewer' => ['a'], 'editor' => ['b'], 'lead' => ['c'], 'writer' => ['d']]],
			'blank role key'   => ['roles' => ['' => ['editors']]],
			'int role key'     => ['roles' => [7 => ['editors']]],
			'deny'             => ['deny' => ['roles' => ['viewer' => ['x']]], 'roles' => ['viewer' => ['y']]],
			'deny of deny'     => ['deny' => ['deny' => ['read' => ['z']], 'roles' => ['viewer' => ['x']]]],
			'deny only'        => ['deny' => ['read' => ['x']]],
			'deny empty'       => ['deny' => []],
			'deny scalar'      => ['deny' => 'x', 'read' => ['y']],
			'deny unknown'     => ['deny' => ['roles' => ['ghost' => ['x']]]],
		];
	}//end blocks()

	/**
	 * A handler whose schema sits in a register resolved by $resolve.
	 *
	 * @param callable $resolve Answers the register lookup.
	 *
	 * @return PermissionHandler
	 */
	private function newHandler(callable $resolve): PermissionHandler {
		$registers = $this->createStub(RegisterMapper::class);
		$registers->method('getFirstRegisterWithSchema')->willReturnCallback($resolve);
		$registers->method('find')->willReturnCallback(fn (): Register => $this->register);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?RegisterMapper => ($id === RegisterMapperInterface::class ? $registers : null)
		);

		$logger = $this->createStub(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['error']);
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			userSession: $this->createStub(IUserSession::class),
			userManager: $this->createStub(IUserManager::class),
			groupManager: $this->createStub(IGroupManager::class),
			schemaMapper: $this->createStub(SchemaMapper::class),
			objectEntityMapper: $this->createStub(MagicMapper::class),
			conditionMatcher: $this->createStub(ConditionMatcher::class),
			appConfig: $this->createStub(IAppConfig::class),
			logger: $logger,
			container: $container
		);
	}//end newHandler()

	private Register $register;

	/**
	 * One expansion and what it logged.
	 *
	 * @param PermissionHandler $handler The handler.
	 * @param array             $block   The block.
	 *
	 * @return array{result: mixed, logged: array}
	 */
	private function expand(PermissionHandler $handler, array $block): array {
		$this->logged = [];
		$schema       = new Schema();
		$schema->setId(5);

		try {
			$result = $handler->expandRoles(authorization: $block, schema: $schema);
		} catch (Throwable $e) {
			$result = get_class($e);
		}

		return ['result' => $result, 'logged' => $this->logged];
	}//end expand()

	/**
	 * Every case, keyed by its definitions and block.
	 *
	 * @return array<string, array>
	 */
	private function record(): array {
		$recording = [];
		foreach (self::definitions() as $definitionsName => $definitions) {
			foreach (self::blocks() as $blockName => $block) {
				$this->register = new Register();
				$this->register->setId(99);
				$this->register->setConfiguration(['roles' => $definitions]);
				$handler = $this->newHandler(static fn (): int => 99);
				$recording["$definitionsName|$blockName"] = $this->expand($handler, $block);
			}
		}

		$this->register = new Register();
		$this->register->setId(99);
		$this->register->setConfiguration([]);
		$outcomes = [
			'no roles key' => static fn (): int => 99,
			'no register'  => static fn (): ?int => null,
			'lookup fails' => static function (): int {
				throw new RuntimeException('down');
			},
		];
		foreach ($outcomes as $name => $resolve) {
			foreach (self::blocks() as $blockName => $block) {
				$recording["$name|$blockName"] = $this->expand($this->newHandler($resolve), $block);
			}
		}

		return $recording;
	}//end record()

	public function testEveryBlockExpandsAsRecorded(): void {
		$recording = $this->record();
		$golden    = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);

		$this->assertNotSame([], $golden);
		$this->assertSame($golden, json_decode(json_encode($recording, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
	}//end testEveryBlockExpandsAsRecorded()
}//end class
