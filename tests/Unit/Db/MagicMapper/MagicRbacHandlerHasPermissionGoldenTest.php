<?php

/**
 * Golden test for MagicRbacHandler::hasPermission().
 *
 * Records the verdict, the collaborator calls and the log lines per caller,
 * authorization block, action, object and wiring of the container, so a
 * decomposition keeps the exact decision and the order of its lookups.
 * Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Service\Rbac\GrantLookup;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @uses   \OCA\OpenRegister\Exception\AuthorizationUnresolvableException
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 */
class MagicRbacHandlerHasPermissionGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/rbac-has-permission-golden.json';

	private array $calls = [];

	private static function authorizations(): array {
		$open = ['status' => 'open'];
		return [
			'none' => null,
			'empty' => [],
			'public read' => ['read' => ['public']],
			'group read' => ['read' => ['team-a']],
			'other group read' => ['read' => ['team-z']],
			'authenticated read' => ['read' => ['authenticated']],
			'user override read' => ['read' => ['user:jan']],
			'empty user override' => ['read' => ['user:']],
			'conditional group' => ['read' => [['group' => 'team-a', 'match' => $open]]],
			'conditional other group' => ['read' => [['group' => 'team-z', 'match' => $open]]],
			'conditional public' => ['read' => [['group' => 'public', 'match' => $open]]],
			'conditional authenticated' => ['read' => [['group' => 'authenticated', 'match' => $open]]],
			'conditional user' => ['read' => [['user' => 'jan', 'match' => $open]]],
			'conditional user not a string' => ['read' => [['user' => 42, 'group' => 'team-a', 'match' => $open]]],
			'conditional empty match' => ['read' => [['group' => 'team-a', 'match' => []]]],
			'conditional no match' => ['read' => [['group' => 'team-a']]],
			'conditional without subject' => ['read' => [['match' => $open]]],
			'two rules' => ['read' => ['team-z', ['group' => 'team-a', 'match' => ['_owner' => 'jan']]]],
			'update only' => ['update' => ['team-a']],
			'read and update' => ['read' => ['team-a'], 'update' => ['public']],
			'unknown rule shape' => ['read' => [42]],
			'deny my group' => ['read' => ['team-a'], 'deny' => ['read' => ['team-a']]],
			'deny conditional' => ['read' => ['public'], 'deny' => ['read' => [['group' => 'team-a', 'match' => ['status' => 'closed']]]]],
			'deny other group' => ['read' => ['authenticated'], 'deny' => ['read' => ['team-q']]],
			'private schema' => ['scope' => 'private', 'read' => ['team-a', 'team-q']],
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

	private static function objects(): array {
		return [
			'no object' => [null, null, null, null],
			'own open' => ['jan', ['status' => 'open', '_owner' => 'jan'], null, 'u-own'],
			'other closed' => ['kees', ['status' => 'closed', '_owner' => 'kees'], null, 'u-closed'],
			'private granted' => ['kees', ['status' => 'open'], ['scope' => 'private'], 'u-granted'],
			'private not granted' => ['kees', ['status' => 'open'], ['scope' => 'private'], 'u-other'],
			'public object of private schema' => ['kees', ['status' => 'open'], ['scope' => 'public'], 'u-public'],
			'object denies my group' => ['kees', ['status' => 'open'], ['deny' => ['read' => ['team-a'], 'update' => ['team-q']]], 'u-deny'],
		];
	}//end objects()

	public function testHasPermissionMatchesGolden(): void {
		$actual = [];
		foreach (['wired', 'inherit off', 'bare', 'unresolvable'] as $wiring) {
			foreach (self::callers() as $callerName => $caller) {
				foreach (self::authorizations() as $authName => $authorization) {
					foreach (['read', 'update'] as $action) {
						foreach (self::objects() as $objectName => $object) {
							$name = "{$wiring} / {$callerName} / {$authName} / {$action} / {$objectName}";
							$actual[$name] = $this->decide(
								setup: [$wiring, $caller, $authorization],
								call: [$action, $object]
							);
						}
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
	}//end testHasPermissionMatchesGolden()

	private function decide(array $setup, array $call): array {
		[$wiring, [$uid, $groups], $authorization] = $setup;
		[$action, [$owner, $data, $objectAuthorization, $uuid]] = $call;
		$this->calls = [];

		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		if ($uid !== null && $uid !== '@anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
			$groupManager->method('getUserGroupIds')->willReturnCallback(
				function () use ($groups): array {
					$this->calls[] = 'groups';
					return $groups;
				}
			);
		}

		$handler = new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->appConfig(),
			$this->conditionMatcher(),
			$this->container(wiring: $wiring),
			$this->logger()
		);

		$schema = new Schema();
		$schema->setId(1);
		$schema->setAuthorization($authorization);
		$schema->setTitle('Golden');

		$outcome = [];
		try {
			$decide = fn () => $handler->hasPermission($schema, $action);
			if ($uuid !== null) {
				$row = new ObjectEntity();
				$row->setOwner($owner);
				$row->setObject($data);
				$row->setAuthorization($objectAuthorization);
				$row->setUuid($uuid);
				$decide = fn () => $handler->hasRowPermission($schema, $action, $row);
			}

			if ($uid === '@anonymous') {
				$outcome['verdict'] = AnonymousEvaluationContext::run($decide);
			} else {
				$outcome['verdict'] = $decide();
			}
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['calls'] = $this->calls;
		return $outcome;
	}//end decide()

	private function container(string $wiring): ContainerInterface {
		$permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$permissionHandler->method('resolveAuthorization')->willReturnCallback(
			function (Schema $schema) use ($wiring): ?array {
				$this->calls[] = 'resolveAuthorization';
				if ($wiring === 'unresolvable') {
					throw new AuthorizationUnresolvableException('register gone');
				}

				return PermissionHandler::stripMcpScope(authorization: $schema->getAuthorization());
			}
		);
		$permissionHandler->method('resolveInheritFromPublic')->willReturnCallback(
			function () use ($wiring): bool {
				$this->calls[] = 'resolveInheritFromPublic';
				return $wiring !== 'inherit off';
			}
		);

		$grants = $this->createMock(ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturnCallback(
			function (GrantLookup $lookup, string $action = 'read'): bool {
				$this->calls[] = "isGranted {$lookup->userId} {$lookup->objectUuid} {$action}";
				return $lookup->userId === 'jan' && $lookup->objectUuid === 'u-granted';
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($wiring, $permissionHandler, $grants): object {
				$this->calls[] = "get {$id}";
				if ($wiring === 'bare') {
					throw new RuntimeException('not wired');
				}

				if ($id === ObjectGrantResolver::class) {
					return $grants;
				}

				return $permissionHandler;
			}
		);
		return $container;
	}//end container()

	private function conditionMatcher(): ConditionMatcher {
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			function (array $object, array $match): bool {
				$this->calls[] = 'objectMatchesConditions ' . json_encode($match);
				foreach ($match as $key => $value) {
					if (($object[$key] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}
		);
		$matcher->method('namesUnknownMetadata')->willReturnCallback(
			function (array $match): bool {
				$this->calls[] = 'namesUnknownMetadata ' . json_encode($match);
				return false;
			}
		);
		return $matcher;
	}//end conditionMatcher()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = "{$level}: {$message}";
				}
			);
		}

		return $logger;
	}//end logger()

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'deny_enforcement' ? 'enforcing' : $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			function (string $app, string $key, bool $default = false): bool {
				$this->calls[] = "getValueBool {$key}";
				return false;
			}
		);
		return $appConfig;
	}//end appConfig()
}//end class
