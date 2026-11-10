<?php

/**
 * Golden test for MagicRbacHandler::applyRbacFilters().
 *
 * Records every query-builder call the RBAC filter makes, and its log lines,
 * per caller (anonymous, member, admin), authorization block, action and
 * table, so a decomposition keeps the exact predicate. Re-record with
 * UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class MagicRbacHandlerApplyFiltersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/rbac-apply-filters-golden.json';

	private const EDGE_FIXTURE = __DIR__ . '/fixtures/rbac-apply-filters-edge-golden.json';

	private array $logs = [];

	private bool $webRequest = false;

	private bool $unresolvable = false;

	private bool $statusMissing = false;

	private static function authorizations(): array {
		return [
			'none' => null,
			'empty' => [],
			'public read' => ['read' => ['public']],
			'group read' => ['read' => ['team-a']],
			'other group read' => ['read' => ['team-z']],
			'authenticated read' => ['read' => ['authenticated']],
			'conditional read' => ['read' => [['group' => 'team-a', 'match' => ['status' => 'open']]]],
			'conditional other group' => ['read' => [['group' => 'team-z', 'match' => ['status' => 'open']]]],
			'two rules' => ['read' => ['team-z', ['group' => 'team-a', 'match' => ['_owner' => '$userId']]]],
			'update only' => ['update' => ['team-a']],
			'read and update' => ['read' => ['team-a'], 'update' => ['public']],
			'unknown rule shape' => ['read' => [42]],
			'deny my group' => ['read' => ['team-a'], 'deny' => ['read' => ['team-a']]],
			'deny conditional' => ['read' => ['public'], 'deny' => ['read' => [['group' => 'team-a', 'match' => ['status' => 'closed']]]]],
			'deny other group' => ['read' => ['authenticated'], 'deny' => ['read' => ['team-q']]],
		];
	}//end authorizations()

	private static function callers(): array {
		return [
			'anonymous' => [null, []],
			'anonymous evaluation' => ['@anonymous', []],
			'member' => ['jan', ['team-a']],
			'outsider' => ['piet', ['team-q']],
			'admin' => ['root', ['admin']],
		];
	}//end callers()

	public function testApplyRbacFiltersMatchesGolden(): void {
		$actual = [];
		foreach (self::callers() as $callerName => [$uid, $groups]) {
			foreach (self::authorizations() as $authName => $authorization) {
				foreach (['read', 'update'] as $action) {
					foreach ([null, 'openregister_table_1_2'] as $table) {
						$name = "{$callerName} / {$authName} / {$action} / " . ($table ?? 'no table');
						$actual[$name] = $this->filter(uid: $uid, groups: $groups, authorization: $authorization, call: [$action, $table]);
					}
				}
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testApplyRbacFiltersMatchesGolden()

	public function testWebRequestsAndUnresolvableAuthorizationMatchGolden(): void {
		$actual = [];
		$modes = [
			'web request' => [true, false, false],
			'unresolvable' => [false, true, false],
			'web unresolvable' => [true, true, false],
			'web status missing' => [true, false, true],
		];
		foreach ($modes as $modeName => $mode) {
			[$this->webRequest, $this->unresolvable, $this->statusMissing] = $mode;
			foreach (self::callers() as $callerName => [$uid, $groups]) {
				foreach (self::authorizations() as $authName => $authorization) {
					foreach ([null, 'openregister_table_1_2'] as $table) {
						$name = "{$modeName} / {$callerName} / {$authName} / " . ($table ?? 'no table');
						$actual[$name] = $this->filter(uid: $uid, groups: $groups, authorization: $authorization, call: ['read', $table]);
					}
				}
			}
		}

		$this->webRequest = false;
		$this->unresolvable = false;
		$this->statusMissing = false;

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::EDGE_FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::EDGE_FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testWebRequestsAndUnresolvableAuthorizationMatchGolden()

	private function filter(?string $uid, array $groups, ?array $authorization, array $call): array {
		$this->queryLog = [];
		$this->logs = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;

		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		if ($uid !== null && $uid !== '@anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
			$groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logs[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$container = $this->createMock(ContainerInterface::class);
		if ($this->unresolvable === true) {
			$permissionHandler = $this->createMock(PermissionHandlerInterface::class);
			$permissionHandler->method('resolveAuthorization')->willThrowException(
				new AuthorizationUnresolvableException('register cascade unreadable')
			);
			$container->method('get')->willReturnCallback(
				static fn (string $id) => ($id === PermissionHandlerInterface::class ? $permissionHandler : throw new \RuntimeException('no ' . $id))
			);
		}

		$healer = null;
		if ($this->statusMissing === true) {
			$healer = $this->createMock(MagicMetadataColumnHealer::class);
			$healer->method('missingFor')->willReturnCallback(
				static fn (string $table): array => ($table === 'openregister_table_1_2' ? ['_status'] : [])
			);
		}

		$args = [
			new MagicMapperIdentity($userSession, $groupManager, $this->createMock(IUserManager::class)),
			new MagicRbacSettings(appConfig: $this->appConfig(), metadataColumns: $healer),
			new MagicRbacSupport($this->createMock(ConditionMatcher::class), $container, $logger),
		];

		$handler = new MagicRbacHandler(...$args);
		if ($this->webRequest === true) {
			$handler = new class(...$args) extends MagicRbacHandler {
				protected function isCommandLine(): bool {
					return false;
				}
			};
		}

		$schema = new Schema();
		$schema->setId(1);
		$schema->setAuthorization($authorization);
		$schema->setTitle('Golden');

		$outcome = [];
		try {
			$apply = fn () => $handler->applyRbacFilters($this->recordingBuilder(), $schema, $call[0], $call[1]);
			if ($uid === '@anonymous') {
				\OCA\OpenRegister\Service\AnonymousEvaluationContext::run($apply);
			} else {
				$apply();
			}
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		$outcome['logs'] = $this->logs;
		return $outcome;
	}//end filter()

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'deny_enforcement' ? 'enforcing' : $default)
		);
		return $appConfig;
	}//end appConfig()
}//end class
