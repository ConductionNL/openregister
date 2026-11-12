<?php

/**
 * Characterisation tests for the last two steps every resolved authorization
 * block goes through in PermissionHandler: the department matrix compile and
 * the token-grant narrowing.
 *
 * Pins, through the private steps on the code as it was before they were
 * split: a block without a matrix is returned as is; a matrix compiles the
 * caller's own values from their groups (the user source read only when it
 * is a map); a declared person source is read too, and any failure compiles
 * nothing, keeps the block and is logged. Without a narrower or without a
 * grant in force the block is returned as is; with one, the narrower gets the
 * grant and the schema / register slugs (no register slug when the schema
 * belongs to no register or its register cannot be read), and its answer is
 * the block.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantUse;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden tests for compileDepartmentMatrix() and narrowByToken().
 */
class PermissionHandlerBlockStepsGoldenTest extends TestCase {

	/**
	 * Error messages logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A handler for caller `alice` in these groups.
	 *
	 * @param array<int, string>      $groups    Alice's groups.
	 * @param ContainerInterface|null $container The container.
	 * @param TokenGrantSource|null   $source    The token grant source.
	 * @param TokenGrantNarrower|null $narrower  The token grant narrower.
	 *
	 * @return PermissionHandler
	 */
	private function handler(array $groups, ?ContainerInterface $container = null, ?TokenGrantSource $source = null, ?TokenGrantNarrower $narrower = null): PermissionHandler {
		$this->errors = [];
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $users,
			groupManager: $groupManager,
			schemaMapper: $this->createMock(SchemaMapperInterface::class),
			objectEntityMapper: $this->createMock(MagicMapperInterface::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			container: ($container ?? $this->createMock(ContainerInterface::class)),
			tokenGrantSource: $source,
			tokenGrantNarrower: $narrower
		);
	}//end handler()

	/**
	 * Call a private step.
	 *
	 * @param PermissionHandler $handler The handler.
	 * @param string            $step    The method.
	 * @param array|null        $block   The block.
	 * @param Schema            $schema  The schema.
	 *
	 * @return array|null
	 */
	private function step(PermissionHandler $handler, string $step, ?array $block, Schema $schema): ?array {
		return (new ReflectionMethod(PermissionHandler::class, $step))->invoke($handler, $block, $schema);
	}//end step()

	/**
	 * A schema with a department field.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('zaak');
		$schema->setProperties(['department' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * A block with a department matrix whose user source is this.
	 *
	 * @param mixed $userSource The user source.
	 *
	 * @return array<string, mixed>
	 */
	private function matrixBlock(mixed $userSource): array {
		return [
			'read' => ['admin'],
			DepartmentMatrixCompiler::KEY => [
				'field' => 'department',
				'userSource' => $userSource,
				'rows' => [['value' => '$self', 'group' => 'handlers', 'actions' => ['read', 'update']]],
			],
		];
	}//end matrixBlock()

	/**
	 * The block without its matrix declaration: the rules the matrix compiled in.
	 *
	 * @param array|null $block The compiled block.
	 *
	 * @return array|null
	 */
	private function rulesOf(?array $block): ?array {
		unset($block[DepartmentMatrixCompiler::KEY]);

		return $block;
	}//end rulesOf()

	/**
	 * A block without a matrix is returned as is.
	 *
	 * @return void
	 */
	public function testNoMatrixKeepsTheBlock(): void {
		$handler = $this->handler(['dept:VTH']);

		$this->assertNull($this->step($handler, 'compileDepartmentMatrix', null, $this->schema()));
		$this->assertSame(['read' => ['admin']], $this->step($handler, 'compileDepartmentMatrix', ['read' => ['admin']], $this->schema()));
		$this->assertSame(['matrix' => 'nope'], $this->step($handler, 'compileDepartmentMatrix', ['matrix' => 'nope'], $this->schema()));
	}//end testNoMatrixKeepsTheBlock()

	/**
	 * A matrix compiles from the caller's groups; an unreadable user source compiles from none.
	 *
	 * @return void
	 */
	public function testAMatrixCompilesFromTheCallersGroups(): void {
		$withGroups = $this->rulesOf($this->step($this->handler(['dept:VTH', 'dept:BOR', 'other']), 'compileDepartmentMatrix', $this->matrixBlock(['groupPrefix' => 'dept:']), $this->schema()));
		$noValues   = $this->rulesOf($this->step($this->handler(['other']), 'compileDepartmentMatrix', $this->matrixBlock(['groupPrefix' => 'dept:']), $this->schema()));
		$unreadable = $this->rulesOf($this->step($this->handler(['dept:VTH', 'dept:BOR', 'other']), 'compileDepartmentMatrix', $this->matrixBlock('dept:'), $this->schema()));

		$this->assertSame('{"read":["admin",{"group":"handlers","match":{"department":{"$in":["BOR","VTH"]}}}],"update":[{"group":"handlers","match":{"department":{"$in":["BOR","VTH"]}}}]}', json_encode($withGroups), 'compiled from the caller\'s own values');
		$this->assertSame('{"read":["admin"]}', json_encode($noValues), 'no own values');
		$this->assertSame($noValues, $unreadable, 'a user source that is not a map yields no own values');
		$this->assertSame([], $this->errors);
	}//end testAMatrixCompilesFromTheCallersGroups()

	/**
	 * A declared person source is read; a failure keeps the block and is logged.
	 *
	 * @return void
	 */
	public function testAPersonSourceIsReadAndAFailureKeepsTheBlock(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no registers'));
		$block = $this->matrixBlock(['groupPrefix' => 'dept:', 'schema' => 'person', 'field' => 'department']);

		$this->assertSame($block, $this->step($this->handler(['dept:VTH'], $container), 'compileDepartmentMatrix', $block, $this->schema()));
		$this->assertSame(['[PermissionHandler] Could not compile a department matrix; the schema keeps its own rules'], $this->errors);

		$blank = $this->matrixBlock(['groupPrefix' => 'dept:', 'schema' => '  ']);
		$this->assertNotSame($blank, $this->step($this->handler(['dept:VTH'], $container), 'compileDepartmentMatrix', $blank, $this->schema()), 'a blank person schema is not read');
		$this->assertSame([], $this->errors);
	}//end testAPersonSourceIsReadAndAFailureKeepsTheBlock()

	/**
	 * Without a narrower, or without a grant in force, the block is kept.
	 *
	 * @return void
	 */
	public function testNoNarrowerOrNoGrantKeepsTheBlock(): void {
		$block = ['read' => ['admin']];
		$this->assertSame($block, $this->step($this->handler([]), 'narrowByToken', $block, $this->schema()));

		$narrower = $this->createMock(TokenGrantNarrower::class);
		$narrower->expects($this->never())->method('narrow');
		$this->assertSame($block, $this->step($this->handler([], null, null, $narrower), 'narrowByToken', $block, $this->schema()), 'no source');

		$source = $this->createMock(TokenGrantSource::class);
		$source->method('current')->willReturn(null);
		$this->assertSame($block, $this->step($this->handler([], null, $source, $narrower), 'narrowByToken', $block, $this->schema()), 'no grant');
	}//end testNoNarrowerOrNoGrantKeepsTheBlock()

	/**
	 * A grant in force narrows the block against the schema and its register.
	 *
	 * @return void
	 */
	public function testAGrantNarrowsAgainstSchemaAndRegister(): void {
		$grant  = new TokenGrant(verbs: ['read']);
		$source = $this->createMock(TokenGrantSource::class);
		$source->method('current')->willReturn($grant);

		$cases = [
			'register found'      => ['found', 'zaken'],
			'no register'         => ['none', null],
			'register unreadable' => ['throws', null],
		];
		foreach ($cases as $label => [$mode, $expectedSlug]) {
			$register = new Register();
			$register->setSlug('zaken');
			$registers = $this->createMock(RegisterMapperInterface::class);
			$registers->method('getFirstRegisterWithSchema')->willReturnCallback(
				static fn (): ?int => ($mode === 'none') ? null : 3
			);
			$registers->method('find')->willReturnCallback(
				static fn (): Register => ($mode === 'throws') ? throw new RuntimeException('down') : $register
			);
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($registers);

			$used     = [];
			$narrower = $this->createMock(TokenGrantNarrower::class);
			$narrower->expects($this->once())->method('narrow')->willReturnCallback(
				static function (?array $authorization, TokenGrantUse $use) use (&$used): array {
					$used = [$authorization, $use->grant, $use->target->schemaSlug, $use->target->registerSlug];
					return ['narrowed' => true];
				}
			);

			$result = $this->step($this->handler([], $container, $source, $narrower), 'narrowByToken', ['read' => ['admin']], $this->schema());
			$this->assertSame(['narrowed' => true], $result, $label);
			$this->assertSame([['read' => ['admin']], $grant, 'zaak', $expectedSlug], $used, $label);
		}
	}//end testAGrantNarrowsAgainstSchemaAndRegister()
}//end class
