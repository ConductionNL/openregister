<?php

/**
 * Golden test for the cross-schema (`from`) path of AggregationRunner::run().
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
use OCA\OpenRegister\Search\Service\Aggregation\AggregationSlot;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
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
 * Each case names a cross-schema spec, which schemas the caller may read,
 * the session user, the registers that exist and the cache state. The
 * envelope (or exception), every permission check, cache read and write,
 * table read and native SQL statement with its bindings are compared with
 * fixtures/cross-schema-aggregation-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 */
class CrossSchemaAggregationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/cross-schema-aggregation-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['parent' => 'p1', 'status' => 'open', 'amount' => 10, 'team' => 'a'],
			['parent' => 'p1', 'status' => 'done', 'amount' => 20, 'team' => 'b'],
			['parent' => 'p2', 'status' => 'open', 'amount' => 5, 'team' => 'a'],
		];
		$base = [
			'rows' => $rows,
			'allow' => ['parent' => true, 'lines' => true],
			'qualifies' => false,
			'user' => null,
			'org' => null,
			'cached' => null,
			'bypass' => false,
			'parentRow' => ['slug' => 'p1'],
			'targetRegister' => true,
		];
		$specs = [
			'count-default' => ['from' => 'lines'],
			'select-alias' => ['from' => 'lines', 'select' => 'count'],
			'sum-field' => ['from' => 'lines', 'metric' => 'sum', 'field' => 'amount'],
			'where-self' => ['from' => 'lines', 'metric' => 'sum', 'field' => 'amount', 'where' => ['parent' => '@self.slug']],
			'filter-alias' => ['from' => 'lines', 'metric' => 'count', 'filter' => ['status' => 'open']],
			'where-beats-filter' => ['from' => 'lines', 'metric' => 'count', 'where' => ['status' => 'open'], 'filter' => ['status' => 'done']],
			'where-operator' => ['from' => 'lines', 'metric' => 'avg', 'field' => 'amount', 'where' => ['amount' => ['gte' => 10]]],
			'where-not-array' => ['from' => 'lines', 'metric' => 'count', 'where' => 'status'],
			'group-by-string' => ['from' => 'lines', 'metric' => 'count', 'groupBy' => 'status'],
			'group-by-list' => ['from' => 'lines', 'metric' => 'sum', 'field' => 'amount', 'groupBy' => ['status', 'team']],
			'metrics-two' => ['from' => 'lines', 'metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount', 'as' => 'total']]],
			'metrics-grouped' => ['from' => 'lines', 'metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']], 'groupBy' => ['status']],
			'metrics-empty' => ['from' => 'lines', 'metric' => 'max', 'field' => 'amount', 'metrics' => []],
			'field-non-string' => ['from' => 'lines', 'metric' => 'count', 'field' => 7],
			'missing-target' => ['from' => 'nowhere'],
			'self-missing-field' => ['from' => 'lines', 'where' => ['parent' => '@self.unknown']],
		];
		$cases = [];
		foreach ($specs as $key => $spec) {
			$cases[$key] = [['spec' => $spec] + $base];
		}

		$sum = ['from' => 'lines', 'metric' => 'sum', 'field' => 'amount', 'where' => ['parent' => '@self.slug']];
		$targetDenied = ['parent' => true, 'lines' => false];
		$bothDenied = ['parent' => false, 'lines' => false];
		$cases['target-denied'] = [['spec' => $sum, 'allow' => $targetDenied] + $base];
		$cases['target-denied-qualifies'] = [['spec' => $sum, 'allow' => $targetDenied, 'qualifies' => true] + $base];
		$cases['target-denied-bypass'] = [['spec' => $sum, 'allow' => $targetDenied, 'bypass' => true] + $base];
		$cases['parent-denied'] = [['spec' => $sum, 'allow' => ['parent' => false, 'lines' => true]] + $base];
		$cases['both-denied-bypass'] = [['spec' => $sum, 'allow' => $bothDenied, 'bypass' => true] + $base];
		$cases['no-target-register'] = [['spec' => $sum, 'targetRegister' => false] + $base];
		$cases['user-and-org'] = [['spec' => $sum, 'user' => 'alice', 'org' => 'org-1'] + $base];
		$cases['cache-hit'] = [['spec' => $sum, 'cached' => ['name' => 'agg', 'value' => 99]] + $base];
		$cases['parent-row-empty'] = [['spec' => $sum, 'parentRow' => []] + $base];
		$cases['row-cap'] = [['spec' => $sum, 'rows' => array_fill(0, 10000, ['parent' => 'p1', 'amount' => 1])] + $base];
		$cases['current-user-placeholder'] = [['spec' => ['from' => 'lines', 'where' => ['owner' => '$currentUser']], 'user' => 'alice'] + $base];
		$native = ['from' => 'lines', 'metric' => 'count', 'groupBy' => ['status'], 'where' => ['team' => 'a']];
		$nativeRows = [['g0' => 'open', 'agg' => '3'], ['g0' => 'done', 'agg' => '1']];
		$cases['native-grouped'] = [['spec' => $native, 'platform' => 'sqlite', 'nativeRows' => $nativeRows] + $base];
		$sqlite = ['platform' => 'sqlite', 'nativeRows' => $nativeRows];
		$cases['native-grouped-bypass'] = [['spec' => $native, 'bypass' => true] + $sqlite + $base];
		$cases['native-grouped-user'] = [['spec' => $native, 'user' => 'alice', 'org' => 'org-2'] + $sqlite + $base];
		$scalar = ['from' => 'lines', 'metric' => 'sum', 'field' => 'amount'];
		$cases['native-scalar-sum'] = [['spec' => $scalar, 'platform' => 'sqlite', 'nativeRows' => [['agg' => '35']]] + $base];
		$metricsOnly = ['from' => 'lines', 'metrics' => [['metric' => 'count']]];
		$cases['native-skipped-for-metrics'] = [['spec' => $metricsOnly, 'platform' => 'sqlite', 'nativeRows' => [['agg' => '9']]] + $base];

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testCrossSchemaRunMatchesTheGolden(array $case): void {
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
	}//end testCrossSchemaRunMatchesTheGolden()

	/**
	 * Run the case and record its outcome with every collaborator call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $case): array {
		$this->calls = [];
		$runner = $this->runner(case: $case);
		try {
			$result = $runner->run(
				ref: new NamedAggregationRef(register: 'reg', schema: 'parent', name: 'agg'),
				options: new NamedAggregationOptions(bypassRbac: $case['bypass'], parentRow: $case['parentRow'])
			);
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
	 * @return AggregationRunner
	 */
	private function runner(array $case): AggregationRunner {
		$register = new Register();
		$register->setId(3);
		$register->setSlug('reg');
		$register->setSchemas([5]);
		$parent = new Schema();
		$parent->setId(5);
		$parent->setSlug('parent');
		$parent->setConfiguration(['x-openregister-aggregations' => ['agg' => $case['spec']]]);
		$target = new Schema();
		$target->setId(7);
		$target->setSlug('lines');
		$target->setProperties(
			[
				'parent' => ['type' => 'string'],
				'status' => ['type' => 'string'],
				'amount' => ['type' => 'number'],
				'team' => ['type' => 'string'],
			]
		);
		$targetRegister = new Register();
		$targetRegister->setId(4);
		$targetRegister->setSlug('treg');
		$targetRegister->setSchemas(['7']);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$registers = [$register];
		if ($case['targetRegister'] === true) {
			$registers[] = $targetRegister;
		}

		$registerMapper->method('findAll')->willReturn($registers);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn($parent);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $ref) use ($target): Schema {
				$this->calls[] = ['schemaFind' => $ref];
				if ($ref === 'lines') {
					return $target;
				}

				throw new RuntimeException('absent');
			}
		);

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('callerQualifiesForAction')->willReturnCallback(
			function (Schema $schema, string $action) use ($case): bool {
				$this->calls[] = ['qualifies' => [$schema->getSlug(), $action]];
				return $case['qualifies'];
			}
		);
		$magic->method('getTableNameForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema): string {
				$this->calls[] = ['tableName' => [$register->getSlug(), $schema->getSlug()]];
				return 'oc_or_' . $register->getId() . '_' . $schema->getId();
			}
		);
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
		$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (...$args) use ($case): bool {
				$schema = ($args['schema'] ?? $args[0]);
				$action = ($args['action'] ?? $args[1] ?? null);
				$userId = ($args['userId'] ?? $args[2] ?? null);
				$this->calls[] = ['permission' => [$schema->getSlug(), $action, $userId]];
				return $case['allow'][$schema->getSlug()];
			}
		));

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

		return new AggregationRunner(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $magic),
			placeholders: new PlaceholderResolver($session),
			db: $db,
			cache: $cache,
			permissionHandler: $permissions,
			userSession: $session,
			organisationService: $orgs,
			organizationHandler: $orgHandler,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class)
		);
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
