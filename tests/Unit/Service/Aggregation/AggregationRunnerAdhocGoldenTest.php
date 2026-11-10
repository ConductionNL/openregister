<?php

/**
 * Golden test for AggregationRunner::runAdhoc().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Each case names an ad-hoc query, the caller's permission, the session
 * user, the cache state, the database platform and whether the schema is
 * DBAL-sourced. The envelope (or exception), every permission check, cache
 * read and write, DBAL call, table read and native SQL statement with its
 * bindings are compared with fixtures/aggregation-runner-adhoc-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class AggregationRunnerAdhocGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/aggregation-runner-adhoc-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['status' => 'open', 'amount' => 10, 'team' => 'a', 'created' => '2026-05-01T10:00:00Z'],
			['status' => 'open', 'amount' => 20, 'team' => 'b', 'created' => '2026-05-02T10:00:00Z'],
			['status' => 'done', 'amount' => 5, 'team' => 'a', 'created' => '1777716000'],
			['status' => 'done', 'amount' => 7, 'team' => 'b', 'created' => null],
			['status' => 'done', 'amount' => 9, 'team' => 'b', 'created' => '2026-06-01T00:00:00Z'],
		];
		$base = [
			'rows' => $rows,
			'allow' => true,
			'qualifies' => false,
			'user' => null,
			'org' => null,
			'cached' => null,
			'bypass' => false,
			'dbal' => null,
		];
		$day = ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-31T00:00:00Z', 'gap' => 'day'];
		$queries = [
			'count' => ['metric' => 'count'],
			'sum' => ['metric' => 'sum', 'field' => 'amount'],
			'avg-filter' => ['metric' => 'avg', 'field' => 'amount', 'filter' => ['status' => 'open']],
			'max-operator' => ['metric' => 'max', 'field' => 'amount', 'filter' => ['amount' => ['lt' => 20]]],
			'grouped' => ['metric' => 'count', 'groupBy' => ['field' => 'status']],
			'grouped-two' => ['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['fields' => ['status', 'team']]],
			'metrics-two' => ['metric' => 'count', 'metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']]],
			'metrics-one' => ['metric' => 'min', 'field' => 'amount', 'metrics' => [['metric' => 'max', 'field' => 'amount']]],
			'metrics-grouped' => [
				'metric' => 'count',
				'metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']],
				'groupBy' => ['field' => 'team'],
			],
			'date-bucket' => ['metric' => 'sum', 'field' => 'amount', 'dateBucket' => $day],
			'date-bucket-cumulative' => ['metric' => 'sum', 'field' => 'amount', 'dateBucket' => $day, 'cumulative' => true],
			'date-bucket-month' => ['metric' => 'count', 'dateBucket' => ['gap' => 'month'] + $day],
			'current-user' => ['metric' => 'count', 'filter' => ['owner' => '$currentUser']],
		];
		$cases = [];
		foreach ($queries as $key => $query) {
			$cases[$key] = [['query' => $query] + $base];
		}

		$sum = ['metric' => 'sum', 'field' => 'amount'];
		$cases['denied'] = [['query' => $sum, 'allow' => false] + $base];
		$cases['denied-qualifies'] = [['query' => $sum, 'allow' => false, 'qualifies' => true] + $base];
		$cases['denied-bypass'] = [['query' => $sum, 'allow' => false, 'bypass' => true] + $base];
		$cases['user-and-org'] = [['query' => $sum, 'user' => 'alice', 'org' => 'org-1'] + $base];
		$cases['explicit-field'] = [['query' => ['metric' => 'max', 'field' => 'bsn']] + $base];
		$cases['explicit-field-bypass'] = [['query' => ['metric' => 'max', 'field' => 'bsn'], 'bypass' => true] + $base];
		$cases['explicit-group'] = [['query' => ['metric' => 'count', 'groupBy' => ['field' => 'bsn']]] + $base];
		$explicitMetrics = ['metric' => 'count', 'metrics' => [['metric' => 'count'], ['metric' => 'min', 'field' => 'bsn']]];
		$cases['explicit-metrics'] = [['query' => $explicitMetrics] + $base];
		$cases['explicit-filter'] = [['query' => ['metric' => 'count', 'filter' => ['bsn' => '123']]] + $base];
		$cases['denied-and-explicit'] = [['query' => ['metric' => 'max', 'field' => 'bsn'], 'allow' => false] + $base];
		$cases['cache-hit'] = [['query' => $sum, 'cached' => ['value' => 99, 'backend' => 'php-fallback', 'cached' => false]] + $base];
		$cases['cache-hit-grouped'] = [
			[
				'query' => ['metric' => 'count', 'groupBy' => ['field' => 'status']],
				'cached' => ['groups' => [['key' => 'open', 'value' => 2]], 'backend' => 'sqlite', 'cached' => false],
			] + $base,
		];
		$dbalSource = ['provider' => 'dbal-source', 'config' => ['table' => 'ext']];
		$cases['dbal'] = [['query' => $sum, 'dbal' => ['source' => $dbalSource, 'answer' => ['value' => 42]]] + $base];
		$cases['dbal-null'] = [['query' => $sum, 'dbal' => ['source' => $dbalSource, 'answer' => null]] + $base];
		$cases['dbal-throws'] = [['query' => $sum, 'dbal' => ['source' => $dbalSource, 'answer' => 'throw']] + $base];
		$cases['dbal-multi-metric'] = [['query' => $queries['metrics-two'], 'dbal' => ['source' => $dbalSource, 'answer' => ['value' => 42]]] + $base];
		$cases['dbal-other-provider'] = [['query' => $sum, 'dbal' => ['source' => ['provider' => 'other'], 'answer' => ['value' => 42]]] + $base];
		$cases['dbal-source-not-array'] = [['query' => $sum, 'dbal' => ['source' => 'dbal-source', 'answer' => ['value' => 42]]] + $base];
		$badConfig = ['provider' => 'dbal-source', 'config' => 'x'];
		$cases['dbal-config-not-array'] = [['query' => $sum, 'dbal' => ['source' => $badConfig, 'answer' => ['value' => 42]]] + $base];
		$cases['dbal-no-config'] = [['query' => $sum, 'dbal' => ['source' => ['provider' => 'dbal-source'], 'answer' => ['value' => 1]]] + $base];
		$sqlite = ['platform' => 'sqlite'];
		$cases['native-count'] = [['query' => ['metric' => 'count', 'filter' => ['team' => 'a']], 'nativeRows' => [['agg' => '4']]] + $sqlite + $base];
		$cases['native-count-bypass'] = [['query' => ['metric' => 'count'], 'nativeRows' => [['agg' => '4']], 'bypass' => true] + $sqlite + $base];
		$cases['native-grouped'] = [
			[
				'query' => ['metric' => 'count', 'groupBy' => ['field' => 'status']],
				'nativeRows' => [['g0' => 'open', 'agg' => '3'], ['g0' => 'done', 'agg' => '1']],
				'user' => 'alice',
				'org' => 'org-2',
			] + $sqlite + $base,
		];
		$cases['native-bucket'] = [
			[
				'query' => ['metric' => 'count', 'dateBucket' => $day, 'cumulative' => true],
				'nativeRows' => [['bucket' => '2026-05-01 00:00:00', 'agg' => '2'], ['bucket' => '2026-05-02T00:00:00+02:00', 'agg' => '1']],
			] + $sqlite + $base,
		];
		$cases['native-metrics-two'] = [['query' => $queries['metrics-two'], 'nativeRows' => [['agg' => '4']]] + $sqlite + $base];

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testRunAdhocMatchesTheGolden(array $case): void {
		$name = (string)$this->dataName();
		$actual = $this->observe(case: $case);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'no golden recorded for ' . $name);
		$this->assertSame(json_encode($golden[$name]), json_encode($actual));
	}//end testRunAdhocMatchesTheGolden()

	/**
	 * Run the case and record its outcome with every collaborator call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $case): array {
		$this->calls = [];
		[$runner, $register, $schema] = $this->runner(case: $case);
		$q = $case['query'];
		try {
			$query = AggregationQuery::create(
				metric: $q['metric'],
				field: ($q['field'] ?? null),
				filter: ($q['filter'] ?? []),
				groupBy: ($q['groupBy'] ?? null),
				dateBucket: ($q['dateBucket'] ?? null),
				metrics: ($q['metrics'] ?? null),
				cumulative: ($q['cumulative'] ?? false)
			);
			$result = $runner->runAdhoc(register: $register, schema: $schema, query: $query, bypassRbac: $case['bypass']);
			$outcome = ['result' => $result];
		} catch (Throwable $e) {
			$outcome = ['error' => get_class($e), 'message' => $e->getMessage()];
		}

		return $outcome + ['calls' => $this->calls];
	}//end observe()

	/**
	 * Build a runner whose collaborators record their calls.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{0: AggregationRunner, 1: Register, 2: Schema}
	 */
	private function runner(array $case): array {
		$register = new Register();
		$register->setId(3);
		$register->setSlug('reg');
		$register->setSchemas([5]);
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('things');
		$schema->setProperties(
			[
				'status' => ['type' => 'string'],
				'amount' => ['type' => 'number'],
				'team' => ['type' => 'string'],
				'created' => ['type' => 'string', 'format' => 'date-time'],
				'bsn' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'staff']], 'reveal' => 'explicit']],
			]
		);
		if ($case['dbal'] !== null) {
			$schema->setConfiguration(['x-openregister-object-source' => $case['dbal']['source']]);
		}

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('callerQualifiesForAction')->willReturnCallback(
			function (Schema $schema, string $action) use ($case): bool {
				$this->calls[] = ['qualifies' => [$schema->getSlug(), $action]];
				return $case['qualifies'];
			}
		);
		$magic->method('getTableNameForRegisterSchema')->willReturn('oc_or_3_5');
		$magic->method('rbacRowPredicateSql')->willReturnCallback(
			function () {
				$this->calls[] = ['rowPredicate' => true];
				return 'rbac_pred = 1';
			}
		);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (Register $register, Schema $schema, ?int $limit = null) use ($case): array {
				$this->calls[] = ['table' => [$register->getSlug(), $schema->getSlug(), $limit]];
				$entities = [];
				foreach ($case['rows'] as $row) {
					$entities[] = $this->entity(row: $row);
				}

				return $entities;
			}
		);

		$cache = $this->createMock(AggregationCache::class);
		$cache->method('getAdhoc')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, AggregationQuery $query) use ($case): ?array {
				$this->calls[] = ['cacheGet' => [$registerSlug, $schemaSlug, $query->toArray()]];
				return $case['cached'];
			}
		);
		$cache->method('setAdhoc')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, AggregationQuery $query, array $result): void {
				$this->calls[] = ['cacheSet' => [$registerSlug, $schemaSlug, $query->toArray(), $result]];
			}
		);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (...$args) use ($case): bool {
				$action = ($args['action'] ?? $args[1] ?? null);
				$userId = ($args['userId'] ?? $args[2] ?? null);
				$this->calls[] = ['permission' => [$action, $userId]];
				return $case['allow'];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);

		$orgs = $this->createMock(OrganisationService::class);
		$org = null;
		if ($case['org'] !== null) {
			$org = new Organisation();
			$org->setUuid($case['org']);
		}

		$orgs->method('getActiveOrganisation')->willReturn($org);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->platform(case: $case));
		$db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->statement(sql: $sql, rows: ($case['nativeRows'] ?? []))
		);

		$orgHandler = $this->createMock(MagicOrganizationHandler::class);
		$orgHandler->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['__none__']]
		);

		$dbal = $this->createMock(DbalObjectSourceProvider::class);
		$dbal->method('aggregate')->willReturnCallback(
			function (...$args) use ($case): ?array {
				unset($args['register'], $args['schema']);
				$this->calls[] = ['dbal' => $args];
				$answer = $case['dbal']['answer'];
				if ($answer === 'throw') {
					throw new RuntimeException('external database unreachable');
				}

				return $answer;
			}
		);

		$propertyRbac = $this->createMock(PropertyRbacHandler::class);
		$propertyRbac->method('canReadProperty')->willReturn(true);

		$runner = new AggregationRunner(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $magic),
			placeholders: new PlaceholderResolver($session),
			db: $db,
			cache: $cache,
			permissionHandler: $permissions,
			userSession: $session,
			organisationService: $orgs,
			organizationHandler: $orgHandler,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
			logger: null,
			dbalSourceProvider: $dbal,
			propertyRbac: $propertyRbac,
			explicitReveal: new ExplicitReveal()
		);

		return [$runner, $register, $schema];
	}//end runner()

	/**
	 * The database platform the case runs on.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return object
	 */
	private function platform(array $case): object {
		if (($case['platform'] ?? null) === 'sqlite') {
			return $this->createMock(SqlitePlatform::class);
		}

		return new class {
			public function __toString(): string {
				return 'OtherPlatform';
			}
		};
	}//end platform()

	/**
	 * A prepared statement that records its SQL and bindings.
	 *
	 * @param string                     $sql  The SQL.
	 * @param list<array<string, mixed>> $rows The rows it fetches.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(string $sql, array $rows): IPreparedStatement {
		$index = count($this->calls);
		$this->calls[$index] = ['sql' => (string)preg_replace('/\s+/', ' ', trim($sql))];
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturnCallback(
			function (array $bindings = []) use ($index): IResult {
				$this->calls[$index]['bindings'] = $bindings;
				return $this->createMock(IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		return $stmt;
	}//end statement()

	/**
	 * An object entity holding the row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		return $entity;
	}//end entity()
}//end class
