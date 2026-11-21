<?php

/**
 * The list and row paths take the schema's declared posture when its authorization cannot be resolved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-a-schema-declares-what-an-undecidable-access-question-means
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacFilterCall;
use OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\UndecidablePosture
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacAccessCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @uses   \OCA\OpenRegister\Exception\AuthorizationUnresolvableException
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\RbacCollaborators
 * @uses   \OCA\OpenRegister\Service\Rbac\RbacFallbacks
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 */
final class MagicRbacUndecidablePostureTest extends TestCase {

	/**
	 * Every log line, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $logs = [];

	/**
	 * The context of every warning.
	 *
	 * @var array<int, array>
	 */
	private array $warnings = [];

	private function handler(bool $resolvable = false): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['medewerkers']);

		$permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$permissionHandler->method('resolveAuthorization')->willReturnCallback(
			static function (Schema $schema) use ($resolvable): ?array {
				if ($resolvable === false) {
					throw new AuthorizationUnresolvableException('register gone');
				}

				return PermissionHandler::stripMcpScope(authorization: $schema->getAuthorization());
			}
		);
		$permissionHandler->method('resolveInheritFromPublic')->willReturn(false);

		$grants = $this->createMock(ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($grants, $permissionHandler): object {
				if ($id === ObjectGrantResolver::class) {
					return $grants;
				}

				return $permissionHandler;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = "{$level}: {$message}";
					if ($level === 'warning') {
						$this->warnings[] = $context;
					}
				}
			);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueBool')->willReturn(false);

		return new MagicRbacHandler(
			identity: new MagicMapperIdentity(
				userSession: $userSession,
				groupManager: $groupManager,
				userManager: $this->createMock(IUserManager::class)
			),
			settings: new MagicRbacSettings(appConfig: $appConfig),
			support: new MagicRbacSupport(
				conditionMatcher: $this->createMock(ConditionMatcher::class),
				container: $container,
				logger: $logger
			)
		);
	}//end handler()

	private static function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Case');
		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	private static function row(): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid('u-1');
		$row->setOwner('someone-else');
		$row->setObject(['assignee' => 'someone-else']);
		return $row;
	}//end row()

	/**
	 * The blocks that say nothing, or say closed.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function closedBlocks(): array {
		return [
			'no posture'     => [['read' => ['medewerkers']]],
			'closed'         => [['read' => ['medewerkers'], 'onUndecidable' => 'closed']],
			'a stray value'  => [['read' => ['medewerkers'], 'onUndecidable' => 'OPEN']],
		];
	}//end closedBlocks()

	/**
	 * A schema that does not say open refuses on the find, row, qualification and condition paths.
	 *
	 * @param array $authorization The schema's block.
	 *
	 * @return void
	 *
	 * @dataProvider closedBlocks
	 */
	public function testAClosedSchemaRefusesOnEveryPath(array $authorization): void {
		$handler = $this->handler();
		$schema  = self::schema($authorization);

		$this->assertFalse($handler->hasPermission(new RbacPermissionAsk($schema, 'read')));
		$this->assertFalse($handler->hasRowPermission(new RbacPermissionAsk($schema, 'read'), self::row()));
		$this->assertFalse($handler->callerQualifiesForAction($schema, 'read'));
		$conditions = $handler->buildRbacConditionsSql((new RbacAccessCall(schema: $schema))->forAction('read'));
		$this->assertSame(['bypass' => false, 'conditions' => []], $conditions);
		$this->assertSame([], $this->warnings);
	}//end testAClosedSchemaRefusesOnEveryPath()

	/**
	 * A schema that does not say open clamps the list query.
	 *
	 * @param array $authorization The schema's block.
	 *
	 * @return void
	 *
	 * @dataProvider closedBlocks
	 */
	public function testAClosedSchemaClampsTheListToNoRows(array $authorization): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->expects($this->once())->method('andWhere');

		$this->handler()->applyRbacFilters((new RbacFilterCall(qb: $qb, schema: self::schema($authorization)))->forAction('read'));

		$this->assertContains('error: [MagicRbacHandler] Authorization unresolvable; clamping query to deny-all (fail-closed)', $this->logs);
	}//end testAClosedSchemaClampsTheListToNoRows()

	public function testAnOpenSchemaGrantsOnEveryPathAndSaysSo(): void {
		$handler = $this->handler();
		$schema  = self::schema(['read' => ['medewerkers'], 'onUndecidable' => 'open']);

		$this->assertTrue($handler->hasPermission(new RbacPermissionAsk($schema, 'read')));
		$this->assertTrue($handler->hasRowPermission(new RbacPermissionAsk($schema, 'update'), self::row()));
		$this->assertTrue($handler->callerQualifiesForAction($schema, 'read'));
		$conditions = $handler->buildRbacConditionsSql((new RbacAccessCall(schema: $schema))->forAction('read'));
		$this->assertSame(['bypass' => true, 'conditions' => []], $conditions);

		$this->assertCount(4, $this->warnings);
		foreach ($this->warnings as $context) {
			$this->assertSame(7, $context['schemaId']);
			$this->assertSame('register gone', $context['error']);
		}

		$this->assertSame('update', $this->warnings[1]['action']);
		$this->assertSame('jan', $this->warnings[1]['userId']);
		$this->assertNotContains(
			'error: [MagicRbacHandler] Authorization unresolvable; returning deny-all conditions (fail-closed)',
			$this->logs
		);
	}//end testAnOpenSchemaGrantsOnEveryPathAndSaysSo()

	public function testAnOpenSchemaLeavesTheListUnclamped(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');

		$this->handler()->applyRbacFilters(
			(new RbacFilterCall(qb: $qb, schema: self::schema(['read' => ['medewerkers'], 'onUndecidable' => 'open'])))->forAction('read')
		);

		$this->assertSame(
			['warning: [MagicRbacHandler] Authorization unresolvable; granting access because the schema declares onUndecidable: open'],
			$this->logs
		);
	}//end testAnOpenSchemaLeavesTheListUnclamped()

	public function testOpenDoesNotWidenAQuestionThatCanBeDecided(): void {
		$handler = $this->handler(resolvable: true);
		$schema  = self::schema(['read' => ['afdeling-b'], 'update' => ['afdeling-b'], 'onUndecidable' => 'open']);

		$this->assertFalse($handler->hasPermission(new RbacPermissionAsk($schema, 'read')));
		$this->assertFalse($handler->hasRowPermission(new RbacPermissionAsk($schema, 'update'), self::row()));
		$this->assertFalse($handler->callerQualifiesForAction($schema, 'read'));
		$this->assertSame([], $this->warnings);
	}//end testOpenDoesNotWidenAQuestionThatCanBeDecided()
}//end class
