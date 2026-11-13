<?php

/**
 * The find path takes the schema's declared posture when its authorization cannot be resolved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses \OCA\OpenRegister\Service\Rbac\UndecidablePosture
 * @uses \OCA\OpenRegister\Db\Register
* @uses \OCA\OpenRegister\Db\JsonArrayColumn
* @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
* @uses \OCA\OpenRegister\Db\ObjectEntity
* @uses \OCA\OpenRegister\Db\Schema
* @uses \OCA\OpenRegister\Event\CustomScopeEvaluatedEvent
* @uses \OCA\OpenRegister\Event\CustomScopeEvaluatingEvent
* @uses \OCA\OpenRegister\Event\EvaluatedSchemaActionAccessors
* @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
* @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
* @uses \OCA\OpenRegister\Service\ConditionUserResolvers
* @uses \OCA\OpenRegister\Service\Object\DenialQuestion
* @uses \OCA\OpenRegister\Service\Object\GroupRuleEntries
* @uses \OCA\OpenRegister\Service\Object\GroupRuleRequest
* @uses \OCA\OpenRegister\Service\Object\InheritFromPublicSetting
* @uses \OCA\OpenRegister\Service\Object\PermissionAsk
* @uses \OCA\OpenRegister\Service\Object\PermissionCheck
* @uses \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
* @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
* @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
* @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
* @uses \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
* @uses \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
* @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
* @uses \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
* @uses \OCA\OpenRegister\Service\Rbac\DenyQuestion
* @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
* @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
* @uses \OCA\OpenRegister\Service\Rbac\McpScope
* @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
* @uses \OCA\OpenRegister\Service\Rbac\StagedDenial
* @uses \OCA\OpenRegister\Service\Rbac\UndeclaredPropertyConditions
* @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
final class PermissionHandlerUndecidablePostureTest extends TestCase {

	/**
	 * The logged lines, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * The context of each warning.
	 *
	 * @var array<int, array>
	 */
	private array $warnings = [];

	/**
	 * A handler for "ana" in group behandelaars whose register lookup fails, or finds a register with the given rules.
	 *
	 * @param array|null $registerRules The register's rules; null makes the lookup fail.
	 *
	 * @return PermissionHandler
	 */
	private function handler(?array $registerRules = null): PermissionHandler {
		$test   = $this;
		$logger = new class($test) extends AbstractLogger {
			public function __construct(private readonly PermissionHandlerUndecidablePostureTest $test) {
			}

			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->test->record((string)$level, (string)$message, $context);
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ana');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['behandelaars']);
		$groups->method('isAdmin')->willReturn(false);

		$registerMapper = $this->createMock(RegisterMapper::class);
		if ($registerRules === null) {
			$registerMapper->method('getFirstRegisterWithSchema')->willThrowException(new RuntimeException('database gone'));
		} else {
			$register = new Register();
			$register->setId(3);
			$register->setAuthorization($registerRules);
			$registerMapper->method('getFirstRegisterWithSchema')->willReturn(3);
			$registerMapper->method('find')->willReturn($register);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new RuntimeException('nothing registered for '.$id);
			}
		);

		$catalogue = $this->createMock(PermissionCatalogue::class);
		$catalogue->method('declarationFor')->willReturn(null);
		$catalogue->method('isGrantable')->willReturn(false);
		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn([]);
		$derived = $this->createMock(DerivedGrantResolver::class);
		$derived->method('groupsFor')->willReturn([]);

		return PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $users,
			groupManager: $groups,
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $appConfig,
			logger: $logger,
			container: $container,
			eventDispatcher: null,
			objectScopeResolver: new ObjectScopeResolver(),
			denyResolver: new DenyResolver(matcher: new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode(appConfig: $appConfig, logger: $logger),
			permissionCatalogue: $catalogue,
			derivedGrantStore: $store,
			derivedGrantResolver: $derived
		);
	}//end handler()

	/**
	 * Record a log line; called by the logger double.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 * @param array  $context The context.
	 *
	 * @return void
	 */
	public function record(string $level, string $message, array $context): void {
		$this->lines[] = $level.': '.$message;
		if ($level === 'warning') {
			$this->warnings[] = $context;
		}
	}//end record()

	private static function schema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(41);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	private function asks(PermissionHandler $handler, Schema $schema, string $action): bool {
		return $handler->hasPermission(schema: $schema, action: $action, userId: 'ana', objectOwner: 'bea', object: self::object());
	}//end asks()

	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-2222-3333-4444-555555555555');
		$object->setOwner('bea');
		$object->setObject(['title' => 'een zaak']);
		return $object;
	}//end object()

	/**
	 * Schema blocks that say nothing, or say closed, about an undecidable question.
	 *
	 * @return array<string, array{0: array|null}>
	 */
	public static function closedBlocks(): array {
		return [
			'no block'                  => [null],
			'closed'                    => [['onUndecidable' => 'closed']],
			'a value the save refuses'  => [['onUndecidable' => 'Open']],
		];
	}//end closedBlocks()

	/**
	 * A schema that does not say open refuses.
	 *
	 * @param array|null $authorization The schema's block.
	 *
	 * @return void
	 *
	 * @dataProvider closedBlocks
	 */
	public function testANonOpenSchemaRefusesWhenTheCascadeFails(?array $authorization): void {
		$verdict = $this->asks($this->handler(), self::schema($authorization), 'read');

		$this->assertFalse($verdict);
		$this->assertContains('error: [PermissionHandler] Authorization unresolvable; denying action (fail-closed)', $this->lines);
		$this->assertSame([], $this->warnings);
	}//end testANonOpenSchemaRefusesWhenTheCascadeFails()

	public function testAnOpenSchemaGrantsWhenTheCascadeFailsAndNamesTheObjectAndWhatWasMissing(): void {
		$verdict = $this->asks($this->handler(), self::schema(['onUndecidable' => 'open']), 'update');

		$this->assertTrue($verdict);
		$this->assertNotContains('error: [PermissionHandler] Authorization unresolvable; denying action (fail-closed)', $this->lines);
		$this->assertCount(1, $this->warnings);
		$this->assertSame(41, $this->warnings[0]['schemaId']);
		$this->assertSame('update', $this->warnings[0]['action']);
		$this->assertSame('ana', $this->warnings[0]['userId']);
		$this->assertSame('11111111-2222-3333-4444-555555555555', $this->warnings[0]['object']);
		$this->assertSame('Unable to resolve register for schema 41', $this->warnings[0]['error']);
	}//end testAnOpenSchemaGrantsWhenTheCascadeFailsAndNamesTheObjectAndWhatWasMissing()

	public function testAPostureOnlyBlockStillTakesTheRegisterRules(): void {
		$handler = $this->handler(registerRules: ['read' => ['afdeling-b']]);

		$this->assertFalse($this->asks($handler, self::schema(['onUndecidable' => 'open']), 'read'));
		$this->assertSame([], $this->warnings);
	}//end testAPostureOnlyBlockStillTakesTheRegisterRules()

	public function testOpenDoesNotWidenASchemaWhoseOwnRulesDecide(): void {
		$schema = self::schema(['read' => ['afdeling-b'], 'onUndecidable' => 'open']);

		$this->assertFalse($this->asks($this->handler(), $schema, 'read'));
		$this->assertTrue($this->asks($this->handler(), self::schema(['read' => ['behandelaars'], 'onUndecidable' => 'open']), 'read'));
		$this->assertSame([], $this->warnings);
	}//end testOpenDoesNotWidenASchemaWhoseOwnRulesDecide()
}//end class
