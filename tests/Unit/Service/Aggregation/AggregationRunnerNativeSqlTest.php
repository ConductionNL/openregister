<?php

/**
 * The SQL, bindings and result of AggregationRunner's native single-metric path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

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
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins tryNativeAggregation() across platforms, query shapes, filter shapes,
 * organisation scopes, the draft lens and the row predicate.
 *
 * Expected values live in `fixtures/native-aggregation-sql.php`, one entry per case.
 */
class AggregationRunnerNativeSqlTest extends TestCase {

	/**
	 * Every case: the call arguments and the collaborators' answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$in       = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-1', 'org-2']];
		$groupRow = [['g0' => 'open', 'agg' => '3'], ['g0' => null, 'agg' => '1.5']];

		return [
			'pg count scalar'                  => ['platform' => 'postgres', 'rows' => [['agg' => '7']]],
			'pg count scalar no row'           => ['platform' => 'postgres', 'rows' => []],
			'pg sum scalar string'             => ['platform' => 'postgres', 'metric' => 'sum', 'field' => 'amount', 'rows' => [['agg' => '12.5']]],
			'pg avg scalar null'               => ['platform' => 'postgres', 'metric' => 'avg', 'field' => 'amount', 'rows' => [['agg' => null]]],
			'pg max scalar float'              => ['platform' => 'postgres', 'metric' => 'max', 'field' => 'amount', 'rows' => [['agg' => 4.0]]],
			'mysql scalar refused'             => ['platform' => 'mysql'],
			'unknown platform refused'         => ['platform' => 'unknown'],
			'unsupported metric refused'       => ['platform' => 'postgres', 'metric' => 'median', 'field' => 'amount'],
			'value metric without field'       => ['platform' => 'postgres', 'metric' => 'sum', 'field' => ''],
			'unsupported operator refused'     => ['platform' => 'postgres', 'filter' => ['title' => ['like' => 'x']]],
			'array property filter refused'    => ['platform' => 'postgres', 'filter' => ['tags' => 'a']],
			'conditional metric refused'       => [
				'platform' => 'postgres',
				'metrics'  => [['metric' => 'sum', 'field' => 'amount', 'condition' => ['kind' => 'debit']]],
			],
			'dotted group field refused'       => ['platform' => 'postgres', 'groupBy' => ['field' => 'parent.code']],
			'empty table name refused'         => ['platform' => 'postgres', 'table' => ''],
			'org none'                         => ['platform' => 'postgres', 'org' => ['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []], 'rows' => [['agg' => 0]]],
			'org null only'                    => ['platform' => 'postgres', 'org' => ['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => []], 'rows' => [['agg' => 1]]],
			'org in'                           => ['platform' => 'postgres', 'org' => $in, 'rows' => [['agg' => 2]]],
			'org in or null'                   => [
				'platform' => 'postgres',
				'org'      => ['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-1']],
				'rows'     => [['agg' => 2]],
			],
			'org unknown mode refused'         => ['platform' => 'postgres', 'org' => ['mode' => 'mystery', 'uuids' => []]],
			'org waived by rbac'               => ['platform' => 'postgres', 'org' => $in, 'waived' => true, 'rows' => [['agg' => 9]]],
			'org widened by rbac'              => ['platform' => 'postgres', 'org' => $in, 'admitted' => true, 'rows' => [['agg' => 9]]],
			'rbac off ignores rbac answers'    => [
				'platform'     => 'postgres',
				'org'          => $in,
				'waived'       => true,
				'rowPredicate' => 'learner = 1',
				'rowRbac'      => false,
				'rows'         => [['agg' => 9]],
			],
			'row predicate'                    => ['platform' => 'postgres', 'rowPredicate' => '("learner" = \'u1\')', 'rows' => [['agg' => 1]]],
			'draft lens with owner'            => ['platform' => 'postgres', 'user' => 'alice', 'rows' => [['agg' => 1]]],
			'draft lens without status column' => ['platform' => 'postgres', 'user' => 'alice', 'statusMissing' => true, 'rows' => [['agg' => 1]]],
			'filters of every shape'           => [
				'platform' => 'postgres',
				'filter'   => [
					'status'    => 'open',
					'kind'      => ['a', 'b'],
					'none'      => [],
					'flag'      => true,
					'code'      => ['in' => ['x', 'y']],
					'emptyIn'   => ['in' => []],
					'notCode'   => ['notIn' => ['z']],
					'emptyNot'  => ['notIn' => []],
					'scalarIn'  => ['in' => 'q'],
					'closedAt'  => 'IS NULL',
					'openedAt'  => ['isnull' => false],
					'amount'    => ['gt' => 1, 'gte' => 2, 'lt' => 3, 'lte' => 4, 'ne' => 5],
					'ownerName' => null,
				],
				'rows'     => [['agg' => 4]],
			],
			'pg group single'                  => ['platform' => 'postgres', 'groupBy' => ['field' => 'status'], 'rows' => $groupRow],
			'pg group single sum'              => [
				'platform' => 'postgres',
				'metric'   => 'sum',
				'field'    => 'amount',
				'groupBy'  => ['field' => 'status'],
				'rows'     => [['g0' => 'open', 'agg' => '3'], ['g0' => 'x', 'agg' => 2], ['g0' => 'y', 'agg' => null]],
			],
			'mysql group multi'                => [
				'platform' => 'mysql',
				'groupBy'  => ['fields' => ['status', 'kind']],
				'rows'     => [['g0' => 'open', 'g1' => 'a', 'agg' => '2']],
			],
			'sqlite group list'                => ['platform' => 'sqlite', 'groupBy' => ['status'], 'rows' => $groupRow],
			'pg bucket cumulative'             => [
				'platform'   => 'postgres',
				'dateBucket' => ['field' => 'created', 'start' => '2026-01-01', 'end' => '2026-02-01', 'gap' => 'day'],
				'cumulative' => true,
				'filter'     => ['status' => 'open'],
				'rows'       => [['bucket' => '2026-01-01 00:00:00', 'agg' => '2', 'cumulative_agg' => '2'], ['bucket' => '2026-01-02 00:00:00', 'agg' => '3', 'cumulative_agg' => '5']],
			],
			'pg bucket plain sum'              => [
				'platform'   => 'postgres',
				'metric'     => 'sum',
				'field'      => 'amount',
				'dateBucket' => ['field' => 'created', 'start' => '2026-01-01', 'end' => '2026-02-01', 'gap' => 'month'],
				'rows'       => [['bucket' => '2026-01-01 00:00:00', 'agg' => '2.5']],
			],
			'mysql bucket cumulative'          => [
				'platform'   => 'mysql',
				'dateBucket' => ['field' => 'created', 'start' => '2026-01-01', 'end' => '2026-01-03', 'gap' => 'day'],
				'cumulative' => true,
				'rows'       => [['bucket' => '2026-01-01T00:00:00Z', 'agg' => '2'], ['bucket' => '2026-01-02T00:00:00Z', 'agg' => '3']],
			],
			'sqlite bucket'                    => [
				'platform'   => 'sqlite',
				'dateBucket' => ['field' => 'created', 'start' => '2026-01-01', 'end' => '2026-01-03', 'gap' => 'week'],
				'rows'       => [['bucket' => '2026-01-01T00:00:00Z', 'agg' => '2']],
			],
			'query failure falls back'         => ['platform' => 'postgres', 'prepareFails' => true],
		];
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/native-aggregation-sql.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The native path answers each case exactly as recorded.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded statements and result
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testTheNativePathAnswersAsRecorded(array $case, array $expected): void {
		$this->assertSame($expected, $this->runCase(case: $case));
	}//end testTheNativePathAnswersAsRecorded()

	/**
	 * Run one case and record every prepared statement, its bindings and the result.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array{statements: array<int, array{sql: string, bindings: array}>, result: array|null}
	 */
	public function runCase(array $case): array {
		$statements = new \ArrayObject();
		$runner     = $this->runnerFor(case: $case, statements: $statements);

		$method = new ReflectionMethod(AggregationRunner::class, 'tryNativeAggregation');
		$result = $method->invoke(
			$runner,
			$this->register(),
			$this->schema(),
			$case['metric'] ?? 'count',
			$case['field'] ?? null,
			$case['filter'] ?? [],
			$case['groupBy'] ?? null,
			$case['dateBucket'] ?? null,
			$case['metrics'] ?? null,
			$case['cumulative'] ?? false,
			$case['rowRbac'] ?? true
		);

		return ['statements' => $statements->getArrayCopy(), 'result' => $result];
	}//end runCase()

	/**
	 * Build a runner whose collaborators answer as the case says.
	 *
	 * @param array<string, mixed> $case       The case
	 * @param \ArrayObject         $statements Collects the prepared statements
	 *
	 * @return AggregationRunner
	 */
	private function runnerFor(array $case, \ArrayObject $statements): AggregationRunner {
		$platforms = [
			'postgres' => PostgreSQLPlatform::class,
			'mysql'    => MySQLPlatform::class,
			'sqlite'   => SqlitePlatform::class,
			'unknown'  => OraclePlatform::class,
		];
		$db        = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platforms[$case['platform']]));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($case, $statements): IPreparedStatement {
				if (($case['prepareFails'] ?? false) === true) {
					throw new RuntimeException('no such table');
				}

				return $this->statement(sql: $sql, rows: $case['rows'] ?? [], statements: $statements);
			}
		);

		$healer = $this->createMock(MagicMetadataColumnHealer::class);
		$healer->method('missingFor')->willReturn(($case['statusMissing'] ?? false) === true ? ['_status'] : []);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn($case['table'] ?? 'register_1_schema_case');
		$magicMapper->method('getMetadataColumnHealer')->willReturn($healer);
		$magicMapper->method('organisationBoundaryWaivedByRbac')->willReturn($case['waived'] ?? false);
		$magicMapper->method('organisationlessRowsAdmittedByRbac')->willReturn($case['admitted'] ?? false);
		$magicMapper->method('rbacRowPredicateSql')->willReturn($case['rowPredicate'] ?? null);

		$orgHandler = $this->createMock(MagicOrganizationHandler::class);
		$orgHandler->method('resolveOrganizationScope')
			->willReturn($case['org'] ?? ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []]);

		$userSession = $this->createMock(IUserSession::class);
		$user        = null;
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$userSession->method('getUser')->willReturn($user);

		return new AggregationRunner(
			magicMapper: $magicMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			placeholders: new PlaceholderResolver($userSession),
			db: $db,
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $userSession,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $orgHandler,
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
		);
	}//end runnerFor()

	/**
	 * A prepared statement that records its SQL and bindings and yields the rows.
	 *
	 * @param string       $sql        The prepared SQL
	 * @param array        $rows       The rows to fetch
	 * @param \ArrayObject $statements Collects the prepared statements
	 *
	 * @return IPreparedStatement
	 */
	private function statement(string $sql, array $rows, \ArrayObject $statements): IPreparedStatement {
		$index              = count($statements);
		$statements[$index] = ['sql' => (string) preg_replace('/\s+/', ' ', trim($sql)), 'bindings' => null];

		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturnCallback(
			function (array $bindings = []) use ($statements, $index): IResult {
				$statements[$index] = ['sql' => $statements[$index]['sql'], 'bindings' => $bindings];
				return $this->createMock(IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

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
				'tags'   => ['type' => 'array'],
				'amount' => ['type' => 'number'],
			]
		);

		return $schema;
	}//end schema()
}//end class
