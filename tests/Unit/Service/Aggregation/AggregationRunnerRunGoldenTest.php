<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationSlot;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\AggregationAccess;
use OCA\OpenRegister\Service\Aggregation\AggregationCaller;
use OCA\OpenRegister\Service\Aggregation\AggregationPresentation;
use OCA\OpenRegister\Service\Aggregation\AggregationSources;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden test for AggregationRunner::run() on the PHP fallback path.
 *
 * Each case names an aggregation spec, the caller's permission, the
 * session user and the cache state. The returned envelope (or the
 * exception), every cache read and write and every table read are
 * compared with fixtures/aggregation-runner-run-golden.json. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class AggregationRunnerRunGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/aggregation-runner-run-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['status' => 'open', 'amount' => 10, 'team' => 'a'],
			['status' => 'open', 'amount' => 20, 'team' => 'b'],
			['status' => 'done', 'amount' => 5, 'team' => 'a'],
		];
		$base = ['rows' => $rows, 'allow' => true, 'qualifies' => false, 'user' => null, 'org' => null, 'cached' => null, 'bypass' => false, 'extra' => [], 'name' => 'agg'];
		$specs = [
			'count-select' => ['select' => 'count'],
			'count-metric' => ['metric' => 'count'],
			'sum-field' => ['metric' => 'sum', 'field' => 'amount'],
			'avg-where' => ['metric' => 'avg', 'field' => 'amount', 'where' => ['status' => 'open']],
			'sum-filter-operator' => ['metric' => 'sum', 'field' => 'amount', 'filter' => ['amount' => ['gte' => 10]]],
			'group-by-string' => ['metric' => 'count', 'groupBy' => 'status'],
			'group-by-list' => ['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['status', 'team']],
			'metrics-two' => ['metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']]],
			'metrics-one' => ['metric' => 'max', 'field' => 'amount', 'metrics' => [['metric' => 'count']]],
			'metrics-empty' => ['metric' => 'min', 'field' => 'amount', 'metrics' => []],
			'metrics-grouped' => ['metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']], 'groupBy' => ['status']],
			'field-non-string' => ['metric' => 'count', 'field' => 7],
		];
		$cases = [];
		foreach ($specs as $key => $spec) {
			$cases[$key] = [['spec' => ['agg' => $spec]] + $base];
		}

		$sum = ['agg' => ['metric' => 'sum', 'field' => 'amount']];
		$cases['denied'] = [['spec' => $sum, 'allow' => false] + $base];
		$cases['denied-but-qualifies'] = [['spec' => $sum, 'allow' => false, 'qualifies' => true] + $base];
		$cases['denied-bypass'] = [['spec' => $sum, 'allow' => false, 'bypass' => true] + $base];
		$cases['no-annotation'] = [['spec' => null] + $base];
		$cases['unknown-name'] = [['spec' => $sum, 'name' => 'other'] + $base];
		$cases['name-not-array'] = [['spec' => ['agg' => 'sum']] + $base];
		$cases['user-and-org'] = [['spec' => $sum, 'user' => 'alice', 'org' => 'org-1'] + $base];
		$cases['cache-hit'] = [['spec' => $sum, 'cached' => ['name' => 'agg', 'value' => 99]] + $base];
		$cases['cache-hit-grouped'] = [['spec' => ['agg' => ['metric' => 'count', 'groupBy' => 'status']], 'cached' => ['groups' => [['key' => 'open', 'value' => 2]]]] + $base];
		$cases['narrowing-new-key'] = [['spec' => ['agg' => ['metric' => 'count', 'where' => ['status' => 'open']]], 'extra' => ['team' => 'a']] + $base];
		$cases['narrowing-declared-key'] = [['spec' => ['agg' => ['metric' => 'count', 'where' => ['status' => 'open']]], 'extra' => ['status' => 'done']] + $base];
		$cases['narrowing-operator'] = [['spec' => $sum, 'extra' => ['amount' => ['gte' => 1]]] + $base];
		$governed = ['metric' => 'sum', 'field' => 'salary'];
		$cases['governed-field'] = [['spec' => ['agg' => $governed]] + $base];
		$cases['governed-field-bypass'] = [['spec' => ['agg' => $governed], 'bypass' => true] + $base];
		$cases['governed-metrics-field'] = [['spec' => ['agg' => ['metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'salary']]]]] + $base];
		$cases['governed-group-by'] = [['spec' => ['agg' => ['metric' => 'count', 'groupBy' => ['salary']]]] + $base];
		$native = ['agg' => ['metric' => 'count', 'groupBy' => ['status'], 'where' => ['team' => 'a']]];
		$nativeRows = [['g0' => 'open', 'agg' => '3'], ['g0' => 'done', 'agg' => '1']];
		$cases['native-grouped'] = [['spec' => $native, 'platform' => 'sqlite', 'nativeRows' => $nativeRows] + $base];
		$cases['native-grouped-bypass'] = [['spec' => $native, 'platform' => 'sqlite', 'nativeRows' => $nativeRows, 'bypass' => true] + $base];
		$cases['native-grouped-user'] = [['spec' => $native, 'platform' => 'sqlite', 'nativeRows' => $nativeRows, 'user' => 'alice', 'org' => 'org-2'] + $base];
		$cases['from-empty'] = [['spec' => ['agg' => ['metric' => 'count', 'from' => '']]] + $base];
		$cases['row-cap'] = [['spec' => $sum, 'rows' => array_fill(0, 10000, ['amount' => 1])] + $base];
		$cases['current-user-placeholder'] = [['spec' => ['agg' => ['metric' => 'count', 'where' => ['owner' => '$currentUser']]], 'user' => 'alice'] + $base];

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testRunMatchesTheGolden(array $case): void {
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
	}//end testRunMatchesTheGolden()

	/**
	 * @param array<string, mixed> $case
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $case): array {
		$this->calls = [];
		$runner = $this->runner(case: $case);
		try {
			$result = $runner->run(
				ref: new NamedAggregationRef(register: 'reg', schema: 'things', name: $case['name']),
				options: new NamedAggregationOptions(bypassRbac: $case['bypass'], extraFilter: $case['extra'])
			);
			$outcome = ['result' => $result];
		} catch (Throwable $e) {
			$outcome = ['error' => get_class($e), 'message' => $e->getMessage()];
		}

		return $outcome + ['calls' => $this->calls];
	}//end observe()

	/**
	 * @param array<string, mixed> $case
	 */
	private function runner(array $case): AggregationRunner {
		$register = new Register();
		$register->setId(3);
		$register->setSlug('reg');
		$register->setSchemas([5]);
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('things');
		$schema->setProperties(['status' => ['type' => 'string'], 'amount' => ['type' => 'number'], 'team' => ['type' => 'string'], 'salary' => ['type' => 'number', 'authorization' => ['read' => ['hr']]]]);
		if ($case['spec'] !== null) {
			$schema->setConfiguration(['x-openregister-aggregations' => $case['spec']]);
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn($schema);

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('callerQualifiesForAction')->willReturn($case['qualifies']);
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
					$entities[] = ($entities[0] ?? null)?->getObject() === $row ? $entities[0] : $this->entity(row: $row);
				}

				return $entities;
			}
		);

		$cache = $this->createMock(AggregationCache::class);
		$cache->method('get')->willReturnCallback(
			function (AggregationSlot $slot) use ($case): ?array {
				$this->calls[] = ['cacheGet' => [$slot->registerSlug, $slot->schemaSlug, $slot->name, $slot->filter]];
				return $case['cached'];
			}
		);
		$cache->method('set')->willReturnCallback(
			function (AggregationSlot $slot, array $result): void {
				$this->calls[] = ['cacheSet' => [$slot->registerSlug, $slot->schemaSlug, $slot->name, $slot->filter, $result]];
			}
		);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (...$args) use ($case): bool {
				$this->calls[] = ['permission' => [$args['action'] ?? $args[1] ?? null, $args['userId'] ?? $args[2] ?? null]];
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
		$orgHandler->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['__none__']]);

		return new AggregationRunner(
			sources: new AggregationSources(
				catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $magic),
				db: $db,
				cache: $cache
			),
			access: new AggregationAccess(
				permissionHandler: $permissions,
				organizationHandler: $orgHandler
			),
			caller: new AggregationCaller(
				userSession: $session,
				organisationService: $orgs,
				placeholders: new PlaceholderResolver($session)
			),
			presentation: new AggregationPresentation(
				translationHandler: $this->createMock(TranslationHandler::class),
				languageService: $this->createMock(LanguageService::class)
			)
		);
	}//end runner()

	/**
	 * @param array<string, mixed> $case
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
	 * @param list<array<string, mixed>> $rows
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
	 * @param array<string, mixed> $row
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		return $entity;
	}//end entity()
}//end class
