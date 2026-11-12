<?php

/**
 * Golden test: what MultiTenancyTrait allows and refuses.
 *
 * Pins, for a matrix of sessions, organisations, configs and shared master
 * data declarations, the exact predicates the organisation filter adds, which
 * writes the organisation guard refuses (and with which exception), every
 * entity RBAC verdict, and what the create hooks stamp on an entity. Recorded
 * on the code before the trait was split into role traits, so a split that
 * changes any allowed or refused outcome fails here.
 *
 * Log lines are pinned by level, message, context keys and the `type` value;
 * the `file` and `line` context values name the source location and are left
 * out on purpose.
 *
 * Regenerate (only when an outcome is meant to change) with
 * GOLDEN_WRITE=1 phpunit tests/Unit/Db/MultiTenancyTraitGoldenTest.php.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\MultiTenancyTrait;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\AppFramework\Db\Entity;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use SplObjectStorage;
use Stringable;

/**
 * An entity with the two columns the trait reads.
 *
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 */
class TenancyGoldenEntity extends Entity {

	/**
	 * The holding organisation.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * The owner uid.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;
}//end class

/**
 * An entity with neither column.
 */
class TenancyGoldenBareEntity extends Entity {
}//end class

/**
 * A logger that keeps what it was told.
 */
class TenancyGoldenLogger extends AbstractLogger {

	/**
	 * Recorded lines.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $lines = [];

	/**
	 * Record one line, without the source location.
	 *
	 * @param mixed             $level   The level.
	 * @param string|Stringable $message The message.
	 * @param array             $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		if ($level === 'debug') {
			return;
		}

		$keys = array_keys($context);
		sort($keys);
		$this->lines[] = [
			'level' => $level,
			'message' => (string) $message,
			'keys' => $keys,
			'type' => ($context['type'] ?? null),
		];
	}//end log()
}//end class

/**
 * A mapper-shaped host for the real trait, every collaborator configurable.
 */
class TenancyGoldenHost {
	use MultiTenancyTrait;

	/**
	 * The connection a mapper inherits from QBMapper.
	 *
	 * @var IDBConnection
	 */
	protected IDBConnection $db;

	/**
	 * Session.
	 *
	 * @var IUserSession
	 */
	protected IUserSession $userSession;

	/**
	 * Groups.
	 *
	 * @var IGroupManager
	 */
	protected IGroupManager $groupManager;

	/**
	 * Organisations.
	 *
	 * @var OrganisationMapper
	 */
	protected OrganisationMapper $organisationMapper;

	/**
	 * App config, left unset when the scenario has none.
	 *
	 * @var IAppConfig
	 */
	protected IAppConfig $appConfig;

	/**
	 * Logger.
	 *
	 * @var TenancyGoldenLogger
	 */
	public TenancyGoldenLogger $logger;

	/**
	 * The table this host maps.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Build the host from one scenario's collaborators.
	 *
	 * @param array<string, mixed> $deps The collaborators.
	 */
	public function __construct(array $deps) {
		$this->db = $deps['db'];
		$this->userSession = $deps['userSession'];
		$this->groupManager = $deps['groupManager'];
		$this->organisationMapper = $deps['organisationMapper'];
		if ($deps['appConfig'] !== null) {
			$this->appConfig = $deps['appConfig'];
		}

		$this->logger = $deps['logger'];
		$this->table = $deps['table'];
	}//end __construct()

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public function getTableName(): string {
		return $this->table;
	}//end getTableName()

	/**
	 * Run a protected trait method.
	 *
	 * @param string $method The method.
	 * @param array  $args   Named arguments.
	 *
	 * @return mixed
	 */
	public function call(string $method, array $args): mixed {
		return $this->{$method}(...$args);
	}//end call()
}//end class

/**
 * @coversNothing
 */
final class MultiTenancyTraitGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/multi-tenancy-trait-golden.json';

	private const ORG_A = 'aaaaaaaa-0000-0000-0000-000000000001';

	private const ORG_PARENT = 'aaaaaaaa-0000-0000-0000-000000000002';

	private const ORG_B = 'bbbbbbbb-0000-0000-0000-000000000001';

	/**
	 * Labels of composite expressions handed out by the fake builder.
	 *
	 * @var SplObjectStorage<ICompositeExpression, string>
	 */
	private SplObjectStorage $composites;

	/**
	 * Every outcome, keyed by scenario.
	 *
	 * @return void
	 */
	public function testEveryOutcomeMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->filterScenarios() as $name => $scenario) {
			$actual['filter: ' . $name] = $this->filterOutcome(scenario: $scenario);
		}

		foreach ($this->guardScenarios() as $name => $scenario) {
			$actual['guard: ' . $name] = $this->guardOutcome(scenario: $scenario);
		}

		foreach ($this->rbacScenarios() as $name => $scenario) {
			$actual['rbac: ' . $name] = $this->rbacOutcome(scenario: $scenario);
		}

		foreach ($this->createScenarios() as $name => $scenario) {
			$actual['create: ' . $name] = $this->createOutcome(scenario: $scenario);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string) json_encode($actual), true));
	}//end testEveryOutcomeMatchesTheRecording()

	/**
	 * The defaults every scenario starts from.
	 *
	 * @return array<string, mixed>
	 */
	private function base(): array {
		return [
			'table' => 'openregister_webhooks',
			'user' => 'alice',
			'groups' => ['users'],
			'isAdmin' => false,
			'activeOrg' => self::ORG_A,
			'defaultOrg' => null,
			'hierarchy' => [self::ORG_A],
			'hierarchyThrows' => false,
			'appConfig' => [],
			'hasAppConfig' => true,
			'shared' => [],
			'enforcement' => true,
			'authorization' => null,
			'orgLookupThrows' => false,
		];
	}//end base()

	/**
	 * Organisation filter scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function filterScenarios(): array {
		$saas = ['multitenancy' => json_encode(['adminOverride' => true, 'saasMode' => true])];
		$override = ['multitenancy' => json_encode(['adminOverride' => true])];
		$off = ['multitenancy' => json_encode(['enabled' => false])];
		$shared = [['id' => 7, 'title' => 'Codes', 'organisation' => self::ORG_B, 'shared_with' => json_encode([self::ORG_A])]];
		$cases = [
			'member, own org' => [],
			'member, own org, null allowed' => ['args' => ['allowNullOrg' => true]],
			'member, own org, aliased' => ['args' => ['tableAlias' => 't', 'columnName' => 'org']],
			'member, org with parent' => ['hierarchy' => [self::ORG_A, self::ORG_PARENT]],
			'hierarchy lookup throws' => ['hierarchyThrows' => true],
			'empty hierarchy' => ['hierarchy' => []],
			'multitenancy off by parameter' => ['args' => ['multiTenancyEnabled' => false]],
			'multitenancy off by config' => ['appConfig' => $off],
			'no app config' => ['hasAppConfig' => false],
			'no active org, nothing allowed' => ['activeOrg' => null],
			'no active org, null allowed' => ['activeOrg' => null, 'args' => ['allowNullOrg' => true]],
			'anonymous, default org' => ['user' => null, 'defaultOrg' => self::ORG_A],
			'anonymous, no default org' => ['user' => null],
			'admin without override' => ['groups' => ['admin'], 'isAdmin' => true],
			'admin with override' => ['groups' => ['admin'], 'isAdmin' => true, 'appConfig' => $override],
			'admin with override, no org' => ['groups' => ['admin'], 'isAdmin' => true, 'appConfig' => $override, 'activeOrg' => null],
			'admin in saas mode' => ['groups' => ['admin'], 'isAdmin' => true, 'appConfig' => $saas],
			'non-admin with override config' => ['appConfig' => $override],
			'registers, shared to me' => ['table' => 'openregister_registers', 'shared' => $shared],
			'registers, shared, aliased' => ['table' => 'openregister_registers', 'shared' => $shared, 'args' => ['tableAlias' => 'r']],
			'schemas, shared to someone else' => ['table' => 'openregister_schemas', 'shared' => [['id' => 9, 'title' => 'S', 'organisation' => self::ORG_B, 'shared_with' => json_encode(['other'])]]],
			'webhooks ignore shares' => ['shared' => $shared],
		];
		return $this->expand(cases: $cases);
	}//end filterScenarios()

	/**
	 * Organisation guard scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function guardScenarios(): array {
		$shared = [['id' => 7, 'title' => 'Codes', 'organisation' => self::ORG_B, 'shared_with' => json_encode([self::ORG_A])]];
		$cases = [
			'same org' => ['entityOrg' => self::ORG_A],
			'other org' => ['entityOrg' => self::ORG_B],
			'no org on entity' => ['entityOrg' => null],
			'admin, other org' => ['entityOrg' => self::ORG_B, 'groups' => ['admin'], 'isAdmin' => true],
			'no active org, entity has one' => ['entityOrg' => self::ORG_B, 'activeOrg' => null],
			'bare entity' => ['bare' => true],
			'registers, consumer writes shared row' => ['table' => 'openregister_registers', 'entityOrg' => self::ORG_B, 'entityId' => 7, 'shared' => $shared],
			'registers, consumer writes unshared row' => ['table' => 'openregister_registers', 'entityOrg' => self::ORG_B, 'entityId' => 8, 'shared' => $shared],
			'schemas, consumer writes shared row' => ['table' => 'openregister_schemas', 'entityOrg' => self::ORG_B, 'entityId' => 7, 'shared' => $shared],
			'registers, row without id' => ['table' => 'openregister_registers', 'entityOrg' => self::ORG_B, 'shared' => $shared],
		];
		return $this->expand(cases: $cases);
	}//end guardScenarios()

	/**
	 * Entity RBAC scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rbacScenarios(): array {
		$auth = ['register' => ['create' => ['editors'], 'read' => [], 'update' => ['*'], 'delete' => ['admin']]];
		$cases = [
			'admin' => ['isAdmin' => true],
			'cli without user' => ['user' => null],
			'cli without user, anonymous evaluation' => ['user' => null, 'anonymous' => true],
			'cli without user, system operation, anonymous evaluation' => ['user' => null, 'anonymous' => true, 'system' => true],
			'enforcement off' => ['enforcement' => false, 'authorization' => $auth],
			'no active org' => ['activeOrg' => null, 'authorization' => $auth],
			'org lookup throws' => ['orgLookupThrows' => true],
			'no authorization' => [],
			'empty authorization' => ['authorization' => []],
			'entity type not configured' => ['authorization' => $auth, 'entityType' => 'schema'],
			'action not configured' => ['authorization' => $auth, 'action' => 'publish'],
			'empty group list' => ['authorization' => $auth, 'action' => 'read'],
			'in allowed group' => ['authorization' => $auth, 'groups' => ['users', 'editors']],
			'not in allowed group' => ['authorization' => $auth],
			'wildcard' => ['authorization' => $auth, 'action' => 'update'],
			'admin-only action, non-admin in admin-named group' => ['authorization' => $auth, 'action' => 'delete', 'groups' => ['admin']],
		];
		return $this->expand(cases: $cases);
	}//end rbacScenarios()

	/**
	 * Create hook scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function createScenarios(): array {
		$cases = [
			'active org, no owner' => [],
			'active org overrides supplied org' => ['entityOrg' => self::ORG_B],
			'no active org, default org config' => ['activeOrg' => null, 'appConfig' => ['defaultOrganisation' => self::ORG_PARENT]],
			'no active org, supplied org kept' => ['activeOrg' => null, 'entityOrg' => self::ORG_B, 'appConfig' => ['defaultOrganisation' => self::ORG_PARENT]],
			'no active org, no app config' => ['activeOrg' => null, 'hasAppConfig' => false],
			'owner already set' => ['entityOwner' => 'bob'],
			'empty owner' => ['entityOwner' => ''],
			'anonymous' => ['user' => null],
			'bare entity' => ['bare' => true],
		];
		return $this->expand(cases: $cases);
	}//end createScenarios()

	/**
	 * Merge each case over the defaults.
	 *
	 * @param array<string, array<string, mixed>> $cases The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function expand(array $cases): array {
		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = array_merge($this->base(), $case);
		}

		return $out;
	}//end expand()

	/**
	 * The predicates the filter adds, or what it threw.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function filterOutcome(array $scenario): array {
		$host = $this->host(s: $scenario);
		$recorded = [];
		$qb = $this->recordingBuilder(recorded: $recorded);
		$args = array_merge(['qb' => $qb], ($scenario['args'] ?? []));
		$host->call(method: 'applyOrganisationFilter', args: $args);
		return ['where' => $recorded, 'log' => $host->logger->lines];
	}//end filterOutcome()

	/**
	 * Whether the guard let the write through.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function guardOutcome(array $scenario): array {
		$host = $this->host(s: $scenario);
		$entity = $this->entity(scenario: $scenario);
		try {
			$host->call(method: 'verifyOrganisationAccess', args: ['entity' => $entity]);
			$verdict = 'allowed';
		} catch (Exception $e) {
			$verdict = ['refused' => get_class($e), 'code' => $e->getCode(), 'message' => $e->getMessage()];
		}

		return ['verdict' => $verdict, 'log' => $host->logger->lines];
	}//end guardOutcome()

	/**
	 * The RBAC verdict and the verify call's refusal.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function rbacOutcome(array $scenario): array {
		$args = ['action' => ($scenario['action'] ?? 'create'), 'entityType' => ($scenario['entityType'] ?? 'register')];
		$run = function () use ($scenario, $args): array {
			$host = $this->host(s: $scenario);
			$verdict = $host->call(method: 'hasRbacPermission', args: $args);
			try {
				$host->call(method: 'verifyRbacPermission', args: $args);
				$verify = 'allowed';
			} catch (Exception $e) {
				$verify = ['code' => $e->getCode(), 'message' => $e->getMessage()];
			}

			return ['has' => $verdict, 'verify' => $verify];
		};
		if (($scenario['anonymous'] ?? false) === true) {
			$inner = $run;
			$run = fn (): array => AnonymousEvaluationContext::run($inner);
		}

		if (($scenario['system'] ?? false) === true) {
			$inner2 = $run;
			$run = fn (): array => SystemOperationContext::run($inner2);
		}

		return $run();
	}//end rbacOutcome()

	/**
	 * What the create hooks stamped.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function createOutcome(array $scenario): array {
		$host = $this->host(s: $scenario);
		$entity = $this->entity(scenario: $scenario);
		$host->call(method: 'setOrganisationOnCreate', args: ['entity' => $entity]);
		$host->call(method: 'setOwnerOnCreate', args: ['entity' => $entity]);
		if ($entity instanceof TenancyGoldenBareEntity) {
			return ['bare' => $entity->getUpdatedFields()];
		}

		return ['organisation' => $entity->getOrganisation(), 'owner' => $entity->getOwner()];
	}//end createOutcome()

	/**
	 * The entity a scenario writes.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return Entity
	 */
	private function entity(array $scenario): Entity {
		if (($scenario['bare'] ?? false) === true) {
			return new TenancyGoldenBareEntity();
		}

		$entity = new TenancyGoldenEntity();
		$entity->setOrganisation($scenario['entityOrg'] ?? null);
		$entity->setOwner($scenario['entityOwner'] ?? null);
		if (isset($scenario['entityId']) === true) {
			$entity->setId($scenario['entityId']);
		}

		$entity->resetUpdatedFields();
		return $entity;
	}//end entity()

	/**
	 * A host wired for one scenario.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return TenancyGoldenHost
	 */
	private function host(array $s): TenancyGoldenHost {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($s['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($s['user']);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($s['groups']);
		$groups->method('isAdmin')->willReturn($s['isAdmin']);

		return new TenancyGoldenHost(
			[
				'db' => $this->sharedDb(rows: $s['shared']),
				'userSession' => $session,
				'groupManager' => $groups,
				'organisationMapper' => $this->organisations(s: $s),
				'appConfig' => $this->appConfig(s: $s),
				'logger' => new TenancyGoldenLogger(),
				'table' => $s['table'],
			]
		);
	}//end host()

	/**
	 * The organisation mapper a scenario answers with.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return OrganisationMapper
	 */
	private function organisations(array $s): OrganisationMapper {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('getActiveOrganisationWithFallback')->willReturn($s['activeOrg']);
		$mapper->method('getDefaultOrganisationFromConfig')->willReturn($s['defaultOrg']);
		if ($s['hierarchyThrows'] === true) {
			$mapper->method('getOrganisationHierarchy')->willThrowException(new RuntimeException('down'));
		} else {
			$mapper->method('getOrganisationHierarchy')->willReturn($s['hierarchy']);
		}

		$mapper->method('isEntityRbacEnforcementEnabled')->willReturn($s['enforcement']);
		if ($s['orgLookupThrows'] === true) {
			$mapper->method('findByUuid')->willThrowException(new RuntimeException('gone'));
			return $mapper;
		}

		$organisation = new Organisation();
		$organisation->setAuthorization($s['authorization']);
		$mapper->method('findByUuid')->willReturn($organisation);
		return $mapper;
	}//end organisations()

	/**
	 * The app config a scenario answers with, or null for none.
	 *
	 * @param array<string, mixed> $s The scenario.
	 *
	 * @return IAppConfig|null
	 */
	private function appConfig(array $s): ?IAppConfig {
		if ($s['hasAppConfig'] === false) {
			return null;
		}

		$values = $s['appConfig'];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
		return $config;
	}//end appConfig()

	/**
	 * A connection whose registers and schemas carry these share declarations.
	 *
	 * @param array<int, array<string, mixed>> $rows The declaration rows.
	 *
	 * @return IDBConnection
	 */
	private function sharedDb(array $rows): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->declarationBuilder(rows: $rows));
		return $db;
	}//end sharedDb()

	/**
	 * A builder that answers the declaration and holder-name queries.
	 *
	 * @param array<int, array<string, mixed>> $rows The declaration rows.
	 *
	 * @return IQueryBuilder
	 */
	private function declarationBuilder(array $rows): IQueryBuilder {
		$table = null;
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'where', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(
			function (string $from) use (&$table, $qb): IQueryBuilder {
				$table = $from;
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table, $rows): IResult {
				$queue = [];
				if ($table === 'openregister_registers') {
					$queue = $rows;
				}

				$queue[] = false;
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...$queue);
				$result->method('fetchOne')->willReturn('Holder Org');
				return $result;
			}
		);
		return $qb;
	}//end declarationBuilder()

	/**
	 * A builder that records every predicate as text.
	 *
	 * @param array<int, string> $recorded Receives the predicates.
	 *
	 * @return IQueryBuilder
	 */
	private function recordingBuilder(array &$recorded): IQueryBuilder {
		$this->composites = new SplObjectStorage();
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($col, $val): string => 'eq(' . $col . ',' . $val . ')');
		$expr->method('in')->willReturnCallback(fn ($col, $val): string => 'in(' . $col . ',' . $val . ')');
		$expr->method('isNull')->willReturnCallback(fn ($col): string => 'isNull(' . $col . ')');
		$expr->method('orX')->willReturnCallback(
			function (...$parts): ICompositeExpression {
				$composite = $this->createMock(ICompositeExpression::class);
				$this->composites[$composite] = 'or(' . implode(' | ', array_map(fn ($p): string => $this->label(part: $p), $parts)) . ')';
				return $composite;
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			fn ($value, $type = IQueryBuilder::PARAM_STR): string => ':' . json_encode($value) . '/' . (string) $type
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$parts) use (&$recorded, $qb): IQueryBuilder {
				foreach ($parts as $part) {
					$recorded[] = $this->label(part: $part);
				}

				return $qb;
			}
		);
		return $qb;
	}//end recordingBuilder()

	/**
	 * The text of one predicate.
	 *
	 * @param mixed $part A string predicate or a composite this builder made.
	 *
	 * @return string
	 */
	private function label(mixed $part): string {
		if ($part instanceof ICompositeExpression) {
			return $this->composites[$part];
		}

		return (string) $part;
	}//end label()
}//end class
