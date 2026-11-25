<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\AuthorizationRuleEvaluator;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An anonymous refusal on the object plane names its plane, schema, verb and cause, once per request.
 *
 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-a-refusal-and-a-grant-must-both-be-observable
 */
class PermissionHandlerAnonymousRefusalRecordTest extends TestCase {
	private PermissionHandler $handler;

	/** @var list<array<string, mixed>> The context of every anonymous-refusal line logged. */
	private array $recorded = [];

	protected function setUp(): void {
		(new AuthorizationRuleEvaluator())->forgetRecordedRefusals();
		$this->recorded = [];

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				if (str_contains((string) $message, 'Anonymous caller refused') === true) {
					$this->recorded[] = $context;
				}
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);

		$register = new Register();
		$register->setId(10);
		$register->setAuthorization(null);
		$register->setConfiguration([]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(10);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($registerMapper) {
				if ($class === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new \RuntimeException('Not available: ' . $class);
			}
		);

		$this->handler = PermissionHandlerFactory::create(
			$userSession,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$logger,
			$container
		);
	}

	protected function tearDown(): void {
		(new AuthorizationRuleEvaluator())->forgetRecordedRefusals();
	}

	private function schema(int $id, ?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setAuthorization($authorization);
		$schema->setTitle('Schema ' . $id);
		return $schema;
	}

	public function testARuleThatRefusesIsNamedAsSuchOnTheObjectPlane(): void {
		$allowed = $this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(1, ['read' => ['behandelaars']]), action: 'read'));

		$this->assertFalse($allowed);
		$this->assertCount(1, $this->recorded);
		$this->assertSame('object', $this->recorded[0]['plane']);
		$this->assertSame('1', $this->recorded[0]['schemaId']);
		$this->assertSame('read', $this->recorded[0]['action']);
		$this->assertSame('rule-refused', $this->recorded[0]['cause']);
		$this->assertTrue($this->recorded[0]['authorizationDeclared']);
		$this->assertFalse($this->recorded[0]['failClosedWrite']);
	}

	public function testAnAbsentBlockIsNamedAsNoRuleAndTheWriteDefaultIsNamed(): void {
		$allowed = $this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(2, null), action: 'create'));

		$this->assertFalse($allowed);
		$this->assertCount(1, $this->recorded);
		$this->assertSame('no-rule', $this->recorded[0]['cause']);
		$this->assertFalse($this->recorded[0]['authorizationDeclared']);
		$this->assertTrue($this->recorded[0]['failClosedWrite']);
	}

	public function testABlockWithoutTheVerbIsNoRuleThoughABlockExists(): void {
		$allowed = $this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(3, ['read' => ['public']]), action: 'update'));

		$this->assertFalse($allowed);
		$this->assertCount(1, $this->recorded);
		$this->assertSame('no-rule', $this->recorded[0]['cause']);
		$this->assertTrue($this->recorded[0]['authorizationDeclared']);
		$this->assertTrue($this->recorded[0]['failClosedWrite']);
	}

	public function testAGrantRecordsNoRefusal(): void {
		$allowed = $this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(4, ['read' => ['public']]), action: 'read'));

		$this->assertTrue($allowed);
		$this->assertSame([], $this->recorded);
	}

	public function testManyRefusalsOfOneSchemaAndVerbRecordOneLine(): void {
		$schema = $this->schema(5, ['read' => ['behandelaars']]);
		for ($row = 0; $row < 25; $row++) {
			$this->handler->clearPermissionCache();
			$this->assertFalse($this->handler->hasPermission(ask: new PermissionAsk(schema: $schema, action: 'read')));
		}

		$this->assertCount(1, $this->recorded);
	}

	public function testAnotherVerbOrSchemaRecordsItsOwnLine(): void {
		$this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(6, ['read' => ['behandelaars']]), action: 'read'));
		$this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(6, ['read' => ['behandelaars']]), action: 'delete'));
		$this->handler->hasPermission(ask: new PermissionAsk(schema: $this->schema(7, ['read' => ['behandelaars']]), action: 'read'));

		$this->assertCount(3, $this->recorded);
		$this->assertSame(['6:read', '6:delete', '7:read'], array_map(static fn (array $c): string => $c['schemaId'] . ':' . $c['action'], $this->recorded));
	}
}
