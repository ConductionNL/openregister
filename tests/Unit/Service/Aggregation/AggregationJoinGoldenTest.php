<?php

/**
 * Golden test for the `join` path of AggregationRunner::run().
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Each case names a spec with a `join`, which schemas the caller may read,
 * the caller's narrowing filter and the registers that exist. The
 * envelope (or exception), every permission check, cache read and write,
 * table read and native SQL statement with its bindings are compared with
 * fixtures/aggregation-join-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 */
class AggregationJoinGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/aggregation-join-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['status' => 'open', 'amount' => 10, 'team' => 'a', 'adm' => 'x'],
			['status' => 'open', 'amount' => 20, 'team' => 'b', 'adm' => 'y'],
			['status' => 'done', 'amount' => 5, 'team' => 'a', 'adm' => 'x'],
		];
		$joinedRows = [
			['status' => 'open', 'team' => 'a', 'budget' => 100, 'adm' => 'x', 'region' => 'n'],
			['status' => 'open', 'team' => 'b', 'budget' => 50, 'adm' => 'y', 'region' => 's'],
			['status' => 'done', 'team' => 'a', 'budget' => 7, 'adm' => 'x', 'region' => 'n'],
			['status' => 'done', 'team' => 'a', 'budget' => 3, 'adm' => 'y', 'region' => 's'],
		];
		$base = [
			'rows' => $rows,
			'joinedRows' => $joinedRows,
			'allow' => ['lines' => true, 'budgets' => true],
			'qualifies' => false,
			'bypass' => false,
			'extra' => [],
			'targetRegister' => true,
		];
		$join = ['through' => 'budgets', 'on' => 'Budget.status', 'select' => ['budget']];
		$grouped = ['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['status']];
		$specs = [
			'string-on' => $grouped + ['join' => $join],
			'string-on-first-group' => ['metric' => 'count', 'groupBy' => ['team', 'status'], 'join' => ['on' => 'Budget.adm'] + $join],
			'map-on' => ['metric' => 'count', 'groupBy' => ['status', 'team'], 'join' => ['on' => ['status' => 'Budget.status', 'team' => 'team']] + $join],
			'select-objects' => $grouped + [
				'join' => [
					'select' => ['budget', ['field' => 'Budget.budget', 'metric' => 'max', 'alias' => 'top'], ['field' => 'budget', 'metric' => 'count']],
				] + $join,
			],
			'join-filter' => $grouped + ['join' => ['filter' => ['adm' => 'x']] + $join],
			'join-where' => $grouped + ['join' => ['where' => ['adm' => 'y']] + $join],
			'no-join' => $grouped,
			'join-not-array' => $grouped + ['join' => 'budgets'],
			'no-group-by' => ['metric' => 'count', 'join' => $join],
			'through-empty' => $grouped + ['join' => ['through' => ''] + $join],
			'on-missing' => $grouped + ['join' => ['through' => 'budgets', 'select' => ['budget']]],
			'on-empty-column' => $grouped + ['join' => ['on' => 'Budget.'] + $join],
			'on-map-unknown-parent' => $grouped + ['join' => ['on' => ['team' => 'team']] + $join],
			'on-map-empty' => $grouped + ['join' => ['on' => ['status' => '']] + $join],
			'select-empty' => $grouped + ['join' => ['select' => []] + $join],
			'select-bad-entry' => $grouped + ['join' => ['select' => [7]] + $join],
			'select-bad-metric' => $grouped + ['join' => ['select' => [['field' => 'budget', 'metric' => 'median']]] + $join],
			'missing-target' => $grouped + ['join' => ['through' => 'nowhere'] + $join],
			'metrics-grouped' => ['metrics' => [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']], 'groupBy' => ['status'], 'join' => $join],
			'qualified-group' => ['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['budgets.region'], 'join' => ['on' => ['status' => 'status']] + $join],
		];
		$cases = [];
		foreach ($specs as $key => $spec) {
			$cases[$key] = [['spec' => $spec] + $base];
		}

		$plain = $grouped + ['join' => $join];
		$narrowed = $grouped + ['join' => ['filter' => ['adm' => 'x']] + $join];
		$cases['joined-denied'] = [['spec' => $plain, 'allow' => ['lines' => true, 'budgets' => false]] + $base];
		$cases['joined-denied-qualifies'] = [['spec' => $plain, 'allow' => ['lines' => true, 'budgets' => false], 'qualifies' => true] + $base];
		$cases['joined-denied-bypass'] = [['spec' => $plain, 'allow' => ['lines' => true, 'budgets' => false], 'bypass' => true] + $base];
		$cases['parent-denied'] = [['spec' => $plain, 'allow' => ['lines' => false, 'budgets' => true]] + $base];
		$cases['no-target-register'] = [['spec' => $plain, 'targetRegister' => false] + $base];
		$cases['extra-applicable'] = [['spec' => $plain, 'extra' => ['adm' => 'x']] + $base];
		$cases['extra-ignored'] = [['spec' => $plain, 'extra' => ['team' => 'a']] + $base];
		$cases['extra-mixed'] = [['spec' => $plain, 'extra' => ['team' => 'a', 'adm' => 'y']] + $base];
		$cases['extra-conflicts-join-filter'] = [['spec' => $narrowed, 'extra' => ['adm' => 'y']] + $base];
		$cases['extra-bypass'] = [['spec' => $plain, 'extra' => ['adm' => 'x'], 'bypass' => true] + $base];
		$sqlite = ['platform' => 'sqlite', 'nativeRows' => [['g0' => 'open', 'agg' => '3'], ['g0' => 'done', 'agg' => '1']]];
		$cases['native'] = [['spec' => $plain] + $sqlite + $base];
		$cases['native-extra'] = [['spec' => $plain, 'extra' => ['adm' => 'x']] + $sqlite + $base];
		$cases['native-bypass'] = [['spec' => $plain, 'bypass' => true] + $sqlite + $base];
		$cases['native-multi-key'] = [['spec' => $specs['map-on']] + $sqlite + $base];

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testJoinRunMatchesTheGolden(array $case): void {
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
	}//end testJoinRunMatchesTheGolden()

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
				registerRef: 'reg',
				schemaRef: 'lines',
				name: 'agg',
				bypassRbac: $case['bypass'],
				extraFilter: $case['extra']
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
		$parent->setSlug('lines');
		$parent->setConfiguration(['x-openregister-aggregations' => ['agg' => $case['spec']]]);
		$parent->setProperties(
			[
				'status' => ['type' => 'string'],
				'amount' => ['type' => 'number'],
				'team' => ['type' => 'string'],
				'adm' => ['type' => 'string'],
			]
		);
		$target = new Schema();
		$target->setId(7);
		$target->setSlug('budgets');
		$target->setProperties(
			[
				'status' => ['type' => 'string'],
				'team' => ['type' => 'string'],
				'budget' => ['type' => 'number'],
				'adm' => ['type' => 'string'],
				'region' => ['type' => 'string'],
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
				if ($ref === 'budgets') {
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
				$source = $case['rows'];
				if ($schema->getSlug() === 'budgets') {
					$source = $case['joinedRows'];
				}

				foreach ($source as $row) {
					$entities[] = $this->entity(row: $row);
				}

				return $entities;
			}
		);

		$cache = $this->createMock(AggregationCache::class);
		$cache->method('get')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, string $name, array $filter): ?array {
				$this->calls[] = ['cacheGet' => [$registerSlug, $schemaSlug, $name, $filter]];
				return null;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, string $name, array $filter, array $result): void {
				$this->calls[] = ['cacheSet' => [$registerSlug, $schemaSlug, $name, $filter, $result]];
			}
		);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (...$args) use ($case): bool {
				$schema = ($args['schema'] ?? $args[0]);
				$action = ($args['action'] ?? $args[1] ?? null);
				$userId = ($args['userId'] ?? $args[2] ?? null);
				$this->calls[] = ['permission' => [$schema->getSlug(), $action, $userId]];
				return $case['allow'][$schema->getSlug()];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);

		$orgs = $this->createMock(OrganisationService::class);
		$orgs->method('getActiveOrganisation')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['debug' => [$message, $context]];
			}
		);

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
			magicMapper: $magic,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			placeholders: new PlaceholderResolver($session),
			db: $db,
			cache: $cache,
			permissionHandler: $permissions,
			userSession: $session,
			organisationService: $orgs,
			organizationHandler: $orgHandler,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
			logger: $logger
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
