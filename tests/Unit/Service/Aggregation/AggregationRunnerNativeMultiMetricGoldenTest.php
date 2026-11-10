<?php

/**
 * Golden test for the native multi-metric dispatch of AggregationRunner.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\NativeAggregationRequest;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Each case calls tryNativeWithSpec() with a request and gives each
 * prepared statement its own rows. The statements with their bindings and
 * the result are compared with fixtures/native-multi-metric-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class AggregationRunnerNativeMultiMetricGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/native-multi-metric-golden.json';

	/** @var list<array<string, mixed>> */
	private array $statements = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$two = [['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']];
		$single = [
			[['g0' => 'open', 'agg' => '3'], ['g0' => 'done', 'agg' => '1']],
			[['g0' => 'done', 'agg' => '4.5'], ['g0' => 'new', 'agg' => '2']],
		];
		$multi = [
			[['g0' => 'open', 'g1' => 'a', 'agg' => '3'], ['g0' => 'done', 'g1' => 'b', 'agg' => '1']],
			[['g0' => 'open', 'g1' => 'a', 'agg' => '7'], ['g0' => 'open', 'g1' => 'b', 'agg' => '2']],
		];
		$status = ['field' => 'status'];
		$pg = ['platform' => 'postgres', 'metrics' => $two, 'groupBy' => $status, 'rows' => $single];
		return [
			'pg grouped single field' => [['platform' => 'postgres', 'metrics' => $two, 'groupBy' => ['field' => 'status'], 'rows' => $single]],
			'pg grouped multi field' => [['platform' => 'postgres', 'metrics' => $two, 'groupBy' => ['fields' => ['status', 'kind']], 'rows' => $multi]],
			'mysql grouped list' => [['platform' => 'mysql', 'metrics' => $two, 'groupBy' => ['status'], 'rows' => $single]],
			'sqlite grouped with filter' => [
				['platform' => 'sqlite', 'metrics' => $two, 'groupBy' => ['field' => 'status'], 'filter' => ['kind' => 'x'], 'rows' => $single],
			],
			'pg grouped no rbac' => [['rowRbac' => false] + $pg],
			'pg ungrouped' => [['platform' => 'postgres', 'metrics' => $two, 'rows' => [[['agg' => '5']], [['agg' => '6.5']]]]],
			'sqlite ungrouped bails' => [['platform' => 'sqlite', 'metrics' => $two, 'rows' => [[['agg' => '5']], [['agg' => '6']]]]],
			'second metric bails' => [
				['metrics' => [['metric' => 'count'], ['metric' => 'median', 'field' => 'amount']]] + $pg,
			],
			'duplicate response key' => [
				['metrics' => [['metric' => 'count'], ['metric' => 'count', 'field' => null]]] + $pg,
			],
			'duplicate response key ungrouped' => [
				[
					'platform' => 'postgres',
					'metrics' => [['metric' => 'sum', 'field' => 'amount'], ['metric' => 'count'], ['metric' => 'sum', 'field' => 'amount']],
					'rows' => [[['agg' => '1']], [['agg' => '2']], [['agg' => '3']]],
				],
			],
			'three metrics grouped' => [
				[
					'platform' => 'postgres',
					'metrics' => [['metric' => 'count'], ['metric' => 'min', 'field' => 'amount'], ['metric' => 'max', 'field' => 'amount']],
					'groupBy' => ['field' => 'status'],
					'rows' => [[['g0' => 'open', 'agg' => '1']], [], [['g0' => 'x', 'agg' => '9']]],
				],
			],
			'one metric uses single path' => [['metrics' => [['metric' => 'count']]] + $pg],
			'empty metrics list' => [['platform' => 'postgres', 'metrics' => [], 'rows' => [[['agg' => '4']]]]],
			'unknown platform' => [['platform' => 'unknown'] + $pg],
			'empty table name' => [['table' => ''] + $pg],
			'array property filter' => [['filter' => ['tags' => 'a']] + $pg],
			'single metric bucket' => [
				[
					'platform' => 'postgres',
					'dateBucket' => ['field' => 'created', 'start' => '2026-01-01T00:00:00Z', 'end' => '2026-03-01T00:00:00Z', 'gap' => 'month'],
					'cumulative' => true,
					'rows' => [[['bucket' => '2026-01-01 00:00:00', 'agg' => '2', 'cumulative' => '2']]],
				],
			],
			'single metric org scoped' => [
				[
					'platform' => 'postgres',
					'metric' => 'sum',
					'field' => 'amount',
					'org' => ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['o1', 'o2']],
					'rows' => [[['agg' => '3']]],
				],
			],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testNativeMultiMetricMatchesTheGolden(array $case): void {
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
	}//end testNativeMultiMetricMatchesTheGolden()

	/**
	 * Entries whose metric or field is not a string, on every native platform shape.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function untypedEntries(): array {
		$entries = [
			'int metric' => [['metric' => 1], ['metric' => 'count']],
			'bool metric' => [['metric' => true], ['metric' => 'count']],
			'missing metric' => [['field' => 'amount'], ['metric' => 'count']],
			'int field' => [['metric' => 'sum', 'field' => 7], ['metric' => 'count']],
		];
		$shapes = [
			'pg grouped' => ['platform' => 'postgres', 'groupBy' => ['field' => 'status']],
			'mysql ungrouped' => ['platform' => 'mysql'],
			'sqlite ungrouped' => ['platform' => 'sqlite'],
			'unknown platform' => ['platform' => 'unknown', 'groupBy' => ['field' => 'status']],
		];
		$cases = [];
		foreach ($entries as $entryName => $metrics) {
			foreach ($shapes as $shapeName => $shape) {
				$cases[$entryName . ', ' . $shapeName] = [$shape + ['metrics' => $metrics, 'rows' => []]];
			}
		}

		return $cases;
	}//end untypedEntries()

	#[DataProvider('untypedEntries')]
	public function testANonStringEntryMetricOrFieldThrowsInsteadOfFallingBack(array $case): void {
		$this->expectException(\TypeError::class);
		// A missing key warns before the TypeError; the warning is not what this pins.
		set_error_handler(static fn (): bool => true, E_WARNING);
		try {
			$this->observe(case: $case);
		} finally {
			restore_error_handler();
		}
	}//end testANonStringEntryMetricOrFieldThrowsInsteadOfFallingBack()

	/**
	 * Run the case and record the statements and the result.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $case): array {
		$this->statements = [];
		$method = new ReflectionMethod(AggregationRunner::class, 'tryNativeWithSpec');
		$result = $method->invoke(
			$this->runner(case: $case),
			new NativeAggregationRequest(
				register: $this->register(),
				schema: $this->schema(),
				spec: [
					'metric'     => $case['metric'] ?? 'count',
					'field'      => $case['field'] ?? null,
					'filter'     => $case['filter'] ?? [],
					'groupBy'    => $case['groupBy'] ?? null,
					'dateBucket' => $case['dateBucket'] ?? null,
					'metrics'    => $case['metrics'] ?? null,
					'cumulative' => $case['cumulative'] ?? false,
					'rowRbac'    => $case['rowRbac'] ?? true,
				]
			)
		);

		return ['statements' => $this->statements, 'result' => $result];
	}//end observe()

	/**
	 * Build a runner whose collaborators answer as the case says.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return AggregationRunner
	 */
	private function runner(array $case): AggregationRunner {
		$platforms = [
			'postgres' => PostgreSQLPlatform::class,
			'mysql' => MySQLPlatform::class,
			'sqlite' => SqlitePlatform::class,
			'unknown' => OraclePlatform::class,
		];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platforms[$case['platform']]));
		$db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->statement(sql: $sql, rowSets: $case['rows'])
		);

		$healer = $this->createMock(MagicMetadataColumnHealer::class);
		$healer->method('missingFor')->willReturn([]);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn($case['table'] ?? 'register_1_schema_case');
		$magicMapper->method('getMetadataColumnHealer')->willReturn($healer);
		$magicMapper->method('organisationBoundaryWaivedByRbac')->willReturn(false);
		$magicMapper->method('organisationBoundaryHasCrossOrganisationBranch')->willReturn(false);
		$magicMapper->method('organisationlessRowsAdmittedByRbac')->willReturn(false);
		$magicMapper->method('rbacRowPredicateSql')->willReturn('rbac_pred = 1');

		$orgHandler = $this->createMock(MagicOrganizationHandler::class);
		$orgHandler->method('resolveOrganizationScope')
			->willReturn($case['org'] ?? ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new AggregationRunner(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $magicMapper),
			placeholders: new PlaceholderResolver($userSession),
			db: $db,
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $userSession,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $orgHandler,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class)
		);
	}//end runner()

	/**
	 * A prepared statement that records its SQL and bindings and yields its own rows.
	 *
	 * @param string                           $sql     The prepared SQL.
	 * @param list<list<array<string, mixed>>> $rowSets The rows of each statement, in prepare order.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(string $sql, array $rowSets): IPreparedStatement {
		$index = count($this->statements);
		$this->statements[$index] = ['sql' => (string)preg_replace('/\s+/', ' ', trim($sql)), 'bindings' => null];

		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturnCallback(
			function (array $bindings = []) use ($index): IResult {
				$this->statements[$index]['bindings'] = $bindings;
				return $this->createMock(IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...array_merge(($rowSets[$index] ?? []), [false]));

		return $stmt;
	}//end statement()

	/**
	 * The register every case aggregates in.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('cases');

		return $register;
	}//end register()

	/**
	 * The schema every case aggregates over; `tags` is an array property.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'status' => ['type' => 'string'],
				'kind' => ['type' => 'string'],
				'tags' => ['type' => 'array'],
				'amount' => ['type' => 'number'],
				'created' => ['type' => 'string', 'format' => 'date-time'],
			]
		);

		return $schema;
	}//end schema()
}//end class
