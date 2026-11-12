<?php

/**
 * Golden test for the row predicates and access answers of MagicRbacHandler.
 *
 * Pins, per caller (anonymous, anonymous evaluation, member, owner of another
 * organisation, outsider, system reader, admin), authorization block (public,
 * group, user override, conditional rules with every match operator, deny
 * rules, private scope, owner group, cross-organisation rules), platform
 * (MariaDB, PostgreSQL) and context (web, command line, system operation,
 * organisation context): the raw-SQL predicates, the QueryBuilder calls,
 * the boolean answers and the log lines. Allowed and refused outcomes are
 * both recorded, so a refactor that widens or narrows access shows up as a
 * diff. Re-record with UPDATE_GOLDEN=1, on unchanged code only.
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

use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacFilterCall;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 */
class MagicRbacHandlerGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/rbac-handler-golden.json';

	private array $logs = [];

	private string $denyMode = 'enforcing';

	private static function authorizations(): array {
		$conditional = static fn (array $match): array => ['read' => [['group' => 'team-a', 'match' => $match]], 'update' => [['group' => 'authenticated', 'match' => $match]]];
		$matchOfType = static fn (mixed $match): array => [
			'read' => [['group' => 'team-a', 'match' => $match], ['user' => 'jan', 'match' => $match]],
			'update' => [['group' => 'authenticated', 'match' => $match]],
		];
		return [
			'none' => null,
			'empty' => [],
			'public read' => ['read' => ['public']],
			'group read' => ['read' => ['team-a']],
			'other group read' => ['read' => ['team-z']],
			'authenticated read' => ['read' => ['authenticated']],
			'user override' => ['read' => ['user:jan']],
			'empty user override' => ['read' => ['user:']],
			'unknown rule shape' => ['read' => [42, ['match' => ['status' => 'open']]]],
			'eq string' => $conditional(['status' => 'open']),
			'eq number and bool' => $conditional(['level' => 3, 'isPrivate' => false, 'due' => true]),
			'null value' => $conditional(['due' => null]),
			'owner token' => $conditional(['_owner' => '$userId']),
			'organisation token' => $conditional(['_organisation' => '$organisation']),
			'unresolved token' => $conditional(['status' => '$missing', 'level' => 2]),
			'comparisons' => $conditional(['level' => ['$gt' => 1, '$gte' => 2, '$lt' => 9, '$lte' => 8, '$ne' => 5, '$eq' => 4]]),
			'comparison token' => $conditional(['due' => ['$lt' => '$now']]),
			'comparison array token' => $conditional(['status' => ['$eq' => '$user.groups']]),
			'comparison unresolved' => $conditional(['status' => ['$ne' => '$missing']]),
			'in list' => $conditional(['status' => ['$in' => ['open', "o'brien", 'back\\slash', 7, true, null]]]),
			'nin list' => $conditional(['status' => ['$nin' => ['closed']]]),
			'in empty' => $conditional(['status' => ['$in' => []]]),
			'nin empty' => $conditional(['status' => ['$nin' => []]]),
			'in map' => $conditional(['status' => ['$in' => ['a' => 'open']]]),
			'nin scalar' => $conditional(['status' => ['$nin' => 'open']]),
			'contains scalar' => $conditional(['tags' => ['$contains' => 'red']]),
			'contains token list' => $conditional(['tags' => ['$contains' => '$user.groups']]),
			'contains unresolved' => $conditional(['tags' => ['$contains' => '$missing']]),
			'contains empty list' => $conditional(['tags' => ['$contains' => [null]]]),
			'exists' => $conditional(['due' => ['$exists' => true], 'level' => ['$exists' => false]]),
			'unknown operator' => $conditional(['status' => ['$regex' => 'o.*']]),
			'numeric operator key' => $conditional(['status' => ['open', 'closed']]),
			'empty operator map' => $conditional(['status' => []]),
			'unknown metadata' => $conditional(['_bogus' => 'x']),
			'unknown column' => $conditional(['nonsense' => 'x']),
			'empty match' => ['read' => [['group' => 'team-a', 'match' => []]]],
			'match true' => $matchOfType(true),
			'match false' => $matchOfType(false),
			'match one' => $matchOfType(1),
			'match string' => $matchOfType('x'),
			'match null' => $matchOfType(null),
			'match empty list' => $matchOfType([]),
			'conditional public' => ['read' => [['group' => 'public', 'match' => ['status' => 'open']]]],
			'conditional authenticated' => ['read' => [['group' => 'authenticated', 'match' => ['status' => 'open']]]],
			'conditional user' => ['read' => [['user' => 'jan', 'match' => ['status' => 'open']]]],
			'conditional user not a string' => ['read' => [['user' => 42, 'group' => 'team-a', 'match' => ['status' => 'open']]]],
			'mixed rules' => ['read' => ['team-z', ['group' => 'team-a', 'match' => ['status' => 'open']], 'authenticated', ['group' => 'team-q', 'match' => ['level' => ['$gte' => 3]]]]],
			'cross organisation' => ['read' => [['group' => 'team-a', 'match' => ['status' => 'open'], 'crossOrganisation' => true]]],
			'cross organisation empty match' => ['read' => [['group' => 'team-a', 'match' => [], 'crossOrganisation' => true]]],
			'cross organisation unknown column' => ['read' => [['group' => 'team-a', 'match' => ['nonsense' => 1], 'crossOrganisation' => true], ['group' => 'team-q', 'match' => ['status' => 'x'], 'crossOrganisation' => true]]],
			'pins organisation' => ['read' => [['group' => 'team-a', 'match' => ['_organisation' => '$organisation']]]],
			'binds tenancy' => ['read' => [['group' => 'team-a', 'match' => ['owner_org' => '$organisation']]]],
			'private scope' => ['scope' => 'private', 'read' => ['team-a']],
			'owner group' => ['ownerGroup' => 'team-a', 'read' => ['team-z']],
			'deny group' => ['read' => ['authenticated'], 'deny' => ['read' => ['team-a']]],
			'deny conditional' => ['read' => ['public'], 'deny' => ['read' => [['group' => 'team-a', 'match' => ['status' => ['$in' => ['closed']], 'level' => ['$gt' => 3]]]]]],
			'deny uncompilable' => ['read' => ['public'], 'deny' => ['read' => [['group' => 'team-a', 'match' => ['nonsense' => 'x']]]]],
			'deny user' => ['read' => ['public'], 'deny' => ['read' => ['user:kees']]],
		];
	}//end authorizations()

	private static function callers(): array {
		return [
			'anonymous' => [null, []],
			'anonymous evaluation' => ['@anonymous', []],
			'member' => ['jan', ['team-a']],
			'member other organisation' => ['kees', ['team-a']],
			'outsider' => ['piet', ['team-q']],
			'system reader' => ['sys', ['readers']],
			'admin' => ['root', ['admin']],
		];
	}//end callers()

	public function testPredicatesAndAnswersMatchGolden(): void {
		$actual = [];
		foreach (['mysql', 'postgres'] as $platform) {
			foreach (self::callers() as $callerName => $caller) {
				if ($platform === 'postgres' && in_array($callerName, ['anonymous', 'member'], true) === false) {
					continue;
				}

				foreach (self::authorizations() as $authName => $authorization) {
					$actual["web / {$platform} / {$callerName} / {$authName}"] = $this->outcome($caller, $authName, $platform, 'web');
				}
			}
		}

		foreach (['cli', 'system operation', 'organisation context'] as $mode) {
			foreach (['anonymous', 'member'] as $callerName) {
				foreach (self::authorizations() as $authName => $authorization) {
					$actual["{$mode} / mysql / {$callerName} / {$authName}"] = $this->outcome(self::callers()[$callerName], $authName, 'mysql', $mode);
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
	}//end testPredicatesAndAnswersMatchGolden()

	public function testUnsafeAliasIsRefused(): void {
		$handler = $this->handler(null, [], 'mysql', 'web');
		$this->expectException(\InvalidArgumentException::class);
		$handler->buildRbacPredicateForAlias(new Schema(), new AliasedRows('r; DROP'));
	}//end testUnsafeAliasIsRefused()

	private function outcome(array $caller, string $authName, string $platform, string $mode): array {
		[$uid, $groups] = $caller;
		$authorization = self::authorizations()[$authName];
		// The deny term is long and the same for every rule, so it is enforced
		// where it is the subject, and on a few rule sets to pin how it combines.
		$this->denyMode = 'staging';
		if (str_starts_with($authName, 'deny') === true || in_array($authName, ['none', 'group read', 'mixed rules', 'owner group'], true) === true) {
			$this->denyMode = 'enforcing';
		}

		$this->logs = [];
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;

		$handler = $this->handler($uid, $groups, $platform, $mode);
		$schema = $this->schema($authorization);

		$run = function () use ($handler, $schema): array {
			$out = [];
			$out['sql read'] = $this->capture(fn () => $handler->buildRbacConditionsSql((new RbacAccessCall(schema: $schema, columnPrefix: ''))->forAction('read')->onTable('openregister_table_1_2')));
			$out['sql update prefixed'] = $this->capture(fn () => $handler->buildRbacConditionsSql((new RbacAccessCall(schema: $schema, columnPrefix: 'r.'))->forAction('update')));
			$out['alias predicate'] = $this->capture(fn () => $handler->buildRbacPredicateForAlias($schema, new AliasedRows('rel', 'read')));
			$out['row predicate'] = $this->capture(fn () => $handler->buildRbacRowPredicateSql((new RbacAccessCall(schema: $schema))->forAction('read')));
			$out['qualifies read'] = $this->capture(fn () => $handler->callerQualifiesForAction($schema, 'read'));
			$out['qualifies update'] = $this->capture(fn () => $handler->callerQualifiesForAction($schema, 'update'));
			$out['bypasses multitenancy'] = $this->capture(fn () => $handler->hasConditionalRulesBypassingMultitenancy($schema, 'read'));
			$out['bypasses multitenancy update'] = $this->capture(fn () => $handler->hasConditionalRulesBypassingMultitenancy($schema, 'update'));
			$out['cascade admits organisationless'] = $this->capture(fn () => $handler->admitsOrganisationlessRowsThroughCascade($schema, 'read'));
			$out['public lifts organisation'] = $this->capture(fn () => $handler->publicReadLiftsOrganisationFilter($schema));
			$out['has cross organisation'] = $this->capture(fn () => $handler->hasCrossOrganisationBranch($schema));
			$out['cross organisation sql'] = $this->capture(fn () => $handler->getCrossOrganisationBranchSql($schema));
			$out['holds grants'] = $this->capture(fn () => $handler->currentCallerHoldsObjectGrants());
			$out['quoted groups'] = $this->capture(fn () => $handler->quotedCallerGroups());

			$qb = $this->recordingBuilder();
			$branch = $this->capture(fn () => $handler->getCrossOrganisationBranch($qb, $schema));
			$out['cross organisation qb'] = [is_string($branch) || $branch instanceof \Stringable ? (string) $branch : gettype($branch), $this->queryLog];
			$this->queryLog = [];

			foreach (['read', 'update'] as $action) {
				$thrown = $this->capture(fn () => $handler->applyRbacFilters((new RbacFilterCall(qb: $this->recordingBuilder(), schema: $schema))->forAction($action)->onTable('openregister_table_1_2')));
				$out['qb ' . $action] = [$thrown, $this->queryLog];
				$this->queryLog = [];
			}

			return $out;
		};

		$outcome = $this->inMode($mode, $uid, $run);
		$outcome['logs'] = $this->logs;
		return $outcome;
	}//end outcome()

	private function capture(callable $call): mixed {
		try {
			return $call();
		} catch (\Throwable $e) {
			// The message of a TypeError names a file and line; only the reason is pinned.
			return 'thrown ' . get_class($e) . ': ' . preg_replace('/, called in .* on line \d+$/', '', $e->getMessage());
		}
	}//end capture()

	private function inMode(string $mode, ?string $uid, callable $run): array {
		$inner = $run;
		if ($uid === '@anonymous') {
			$inner = static fn () => AnonymousEvaluationContext::run($run);
		}

		return match ($mode) {
			'system operation' => SystemOperationContext::run($inner),
			'organisation context' => OrganisationContext::run('org-ctx', $inner),
			default => $inner(),
		};
	}//end inMode()

	private function schema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setTitle('Golden');
		$schema->setProperties([
			'status' => ['type' => 'string'],
			'level' => ['type' => 'integer'],
			'isPrivate' => ['type' => 'boolean'],
			'due' => ['type' => 'string'],
			'tags' => ['type' => 'array'],
			'owner_org' => ['type' => 'string'],
		]);
		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	private function handler(?string $uid, array $groups, string $platform, string $mode): MagicRbacHandler {
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		if ($uid !== null && $uid !== '@anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
			$groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		$args = [
			new MagicMapperIdentity($userSession, $groupManager, $this->createMock(IUserManager::class)),
			new MagicRbacSettings($this->appConfig()),
			new MagicRbacSupport($this->matcher($uid, $groups), $this->container($uid, $platform), $this->logger()),
		];

		if ($mode === 'cli') {
			return new MagicRbacHandler(...$args);
		}

		return new class(...$args) extends MagicRbacHandler {
			protected function isCommandLine(): bool {
				return false;
			}
		};
	}//end handler()

	private function matcher(?string $uid, array $groups): ConditionMatcher {
		$organisation = match ($uid) {
			'jan' => 'org-1',
			'kees' => 'org-2',
			default => null,
		};
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => match ($value) {
				'$userId' => ($uid === '@anonymous' ? null : $uid),
				'$organisation' => $organisation,
				'$user.groups' => $groups,
				'$now' => '2026-01-01 00:00:00',
				'$missing' => null,
				default => $value,
			}
		);
		$matcher->method('namesUnknownMetadata')->willReturnCallback(
			static fn (array $match): bool => array_key_exists('_bogus', $match)
		);
		return $matcher;
	}//end matcher()

	private function container(?string $uid, string $platform): ContainerInterface {
		$db = $this->createMock(IDBConnection::class);
		if ($platform === 'postgres') {
			$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
			$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		} else {
			$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
			$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		}

		$organisations = $this->createMock(OrganisationServiceInterface::class);
		$organisations->method('getSystemReaderGroups')->willReturn(['readers']);
		$organisations->method('getSystemUserId')->willReturn('system');

		$grants = $this->createMock(ObjectGrantResolver::class);
		$granted = ($uid === 'jan' ? ['uuid-1' => true, "uu'id-2" => true] : []);
		$grants->method('grantedObjectUuidsFor')->willReturn($granted);
		$grants->method('hasAnyGrant')->willReturn($granted !== []);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('resolveAuthorization')->willReturnCallback(
			static fn (Schema $schema): ?array => $schema->getAuthorization()
		);
		$permissions->method('resolveInheritFromPublic')->willReturn($uid === 'kees');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => match ($id) {
				PermissionHandlerInterface::class => $permissions,
				IDBConnection::class => $db,
				OrganisationServiceInterface::class => $organisations,
				ObjectGrantResolver::class => $grants,
				default => throw new RuntimeException('no ' . $id),
			}
		);
		return $container;
	}//end container()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logs[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'deny_enforcement' ? $this->denyMode : $default)
		);
		$appConfig->method('getValueBool')->willReturn(false);
		return $appConfig;
	}//end appConfig()
}//end class
