<?php

/**
 * Unit tests for multi-metric aggregation requests (REQ-AGG-102).
 *
 * A `metrics` list (`[{metric}, {metric, field}, ...]`) computes every
 * requested metric in one call; each result carries a `values` map keyed
 * by {@see AggregationQuery::metricResponseKey()} (`count`, `sum_price`, …)
 * instead of a single scalar `value`. Covers: ungrouped, single-field
 * grouped, multi-field grouped, the PHP-fallback path, and the "any single
 * metric bails natively → the whole multi-metric attempt falls through to
 * PHP" contract documented on `AggregationRunner::tryNativeMultiMetric()`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * No `@covers` metadata, deliberately — `beStrictAboutCoverageMetadata="true"`
 * discards the coverage of any test that touches a collaborator it did not
 * name, and naming two was still not enough. See
 * {@see AggregationJoinAndCompositeGroupByTest} and #2847.
 */
class AggregationRunnerMultiMetricTest extends TestCase {

	/**
	 * Five rows across two statuses; `amount` drives the sum/avg/min/max
	 * metrics. open: [10, 20] (sum=30, avg=15); in-progress: [30]; total
	 * sum = 30 + 20 + ... — see per-test comments for the exact arithmetic.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dataset(): array {
		return [
			['status' => 'open',        'amount' => 10],
			['status' => 'open',        'amount' => 20],
			['status' => 'in-progress', 'amount' => 5],
		];
	}

	// -----------------------------------------------------------------------
	// Native path (SQLite, grouped — the ungrouped scalar native path is
	// Postgres-only; grouped runs natively on SQLite, see AggregationRunner's
	// tryNativeAggregation() docblock).
	// -----------------------------------------------------------------------

	public function testNativeGroupedMultiMetricCarriesValuesMap(): void {
		$runner = $this->makeNativeRunner();

		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				groupBy: ['field' => 'status'],
				metrics: [
					['metric' => 'count'],
					['metric' => 'sum', 'field' => 'amount'],
				]
			)
		);

		$this->assertSame('sqlite', $result['backend']);
		$folded = [];
		foreach ($result['groups'] as $group) {
			$this->assertArrayHasKey('key', $group);
			$this->assertArrayHasKey('values', $group);
			$this->assertArrayNotHasKey('value', $group, 'multi-metric groups MUST carry `values`, not `value`');
			// SQLite's native SUM() returns an int for whole-number columns
			// (unlike Postgres's ::numeric cast, which always yields a
			// float) — cast for a type-agnostic comparison, same convention
			// AggregationRunnerMultiFieldGroupByTest::foldMulti() uses.
			$folded[(string)$group['key']] = [
				'count' => $group['values']['count'],
				'sum_amount' => (float)$group['values']['sum_amount'],
			];
		}

		$this->assertSame(['count' => 2, 'sum_amount' => 30.0], $folded['open']);
		$this->assertSame(['count' => 1, 'sum_amount' => 5.0], $folded['in-progress']);

	}//end testNativeGroupedMultiMetricCarriesValuesMap()

	public function testNativeUngroupedMultiMetricBailsToPhpFallback(): void {
		// Ungrouped scalar native path is Postgres-only; on SQLite the
		// FIRST per-metric native call already returns null, so the whole
		// multi-metric attempt bails to bucketInPhp() rather than mixing
		// native+PHP results.
		$runner = $this->makeNativeRunner();

		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				metrics: [
					['metric' => 'count'],
					['metric' => 'sum', 'field' => 'amount'],
				]
			)
		);

		$this->assertSame('php-fallback', $result['backend']);
		$this->assertSame(['count' => 3, 'sum_amount' => 35.0], $result['values']);
		$this->assertArrayNotHasKey('value', $result);

	}//end testNativeUngroupedMultiMetricBailsToPhpFallback()

	// -----------------------------------------------------------------------
	// PHP-fallback path (forced via an unrecognised platform).
	// -----------------------------------------------------------------------

	public function testPhpFallbackUngroupedMultiMetric(): void {
		$runner = $this->makePhpFallbackRunner();

		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				metrics: [
					['metric' => 'count'],
					['metric' => 'sum', 'field' => 'amount'],
					['metric' => 'avg', 'field' => 'amount'],
				]
			)
		);

		$this->assertSame('php-fallback', $result['backend']);
		$this->assertSame(3, $result['values']['count']);
		$this->assertSame(35.0, $result['values']['sum_amount']);
		$this->assertEqualsWithDelta(35.0 / 3.0, $result['values']['avg_amount'], 0.0001);

	}//end testPhpFallbackUngroupedMultiMetric()

	public function testPhpFallbackGroupedMultiMetric(): void {
		$runner = $this->makePhpFallbackRunner();

		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				groupBy: ['field' => 'status'],
				metrics: [
					['metric' => 'count'],
					['metric' => 'sum', 'field' => 'amount'],
				]
			)
		);

		$folded = [];
		foreach ($result['groups'] as $group) {
			$folded[(string)$group['key']] = $group['values'];
		}

		$this->assertSame(['count' => 2, 'sum_amount' => 30.0], $folded['open']);
		$this->assertSame(['count' => 1, 'sum_amount' => 5.0], $folded['in-progress']);

	}//end testPhpFallbackGroupedMultiMetric()

	// -----------------------------------------------------------------------
	// Legacy single-metric requests are unaffected (regression).
	// -----------------------------------------------------------------------

	public function testLegacySingleMetricRequestStillReturnsScalarValue(): void {
		$runner = $this->makePhpFallbackRunner();

		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(metric: 'count')
		);

		$this->assertSame(3, $result['value']);
		$this->assertArrayNotHasKey('values', $result);

	}//end testLegacySingleMetricRequestStillReturnsScalarValue()

	public function testOneElementMetricsListBehavesLikeLegacySingleMetric(): void {
		$runner = $this->makePhpFallbackRunner();

		$result = $runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'count',
				metrics: [['metric' => 'count']]
			)
		);

		$this->assertSame(3, $result['value']);
		$this->assertArrayNotHasKey('values', $result);

	}//end testOneElementMetricsListBehavesLikeLegacySingleMetric()

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Runner whose IDBConnection executes real SQL against an in-memory
	 * SQLite database seeded with {@see dataset()}.
	 *
	 * @return AggregationRunner
	 */
	private function makeNativeRunner(): AggregationRunner {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE "oc_register_1_schema_x" ("_deleted" TEXT, "_organisation" TEXT, "status" TEXT, "amount" INTEGER)');
		$insert = $pdo->prepare('INSERT INTO "oc_register_1_schema_x" ("_deleted", "_organisation", "status", "amount") VALUES (NULL, ?, ?, ?)');
		foreach ($this->dataset() as $row) {
			$insert->execute(['__no_active_org__', $row['status'], $row['amount']]);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(SqlitePlatform::class));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($pdo): IPreparedStatement {
				$pdoStmt = $pdo->prepare($sql);
				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function ($bindings = []) use ($pdoStmt): IResult {
						$pdoStmt->execute(($bindings ?? []));
						return $this->createMock(IResult::class);
					}
				);
				$stmt->method('fetch')->willReturnCallback(static fn () => $pdoStmt->fetch(PDO::FETCH_ASSOC));
				return $stmt;
			}
		);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_x');

		$entities = [];
		foreach ($this->dataset() as $row) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($row);
			$entities[] = $entity;
		}

		$magicMapper->method('findAllInRegisterSchemaTable')->willReturn($entities);

		return $this->makeRunner(db: $db, magicMapper: $magicMapper);
	}//end makeNativeRunner()

	/**
	 * Runner whose platform is unrecognised (forcing the PHP fallback).
	 *
	 * @return AggregationRunner
	 */
	private function makePhpFallbackRunner(): AggregationRunner {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

		$entities = [];
		foreach ($this->dataset() as $row) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($row);
			$entities[] = $entity;
		}

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturn($entities);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_x');

		return $this->makeRunner(db: $db, magicMapper: $magicMapper);
	}//end makePhpFallbackRunner()

	private function makeRunner(IDBConnection $db, MagicMapper $magicMapper): AggregationRunner {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturn(null);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn(true);

		$cache = $this->createMock(AggregationCache::class);
		$cache->method('getAdhoc')->willReturn(null);

		$translationHandler = $this->createMock(TranslationHandler::class);
		$translationHandler->method('getTranslatableProperties')->willReturn([]);

		$languageService = $this->createMock(LanguageService::class);
		$languageService->method('shouldReturnAllTranslations')->willReturn(false);

		return new AggregationRunner(
			magicMapper: $magicMapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			placeholders: new PlaceholderResolver($userSession),
			db: $db,
			cache: $cache,
			permissionHandler: $permissionHandler,
			userSession: $userSession,
			organisationService: $organisationService,
			translationHandler: $translationHandler,
			languageService: $languageService,
		);

	}//end makeRunner()

	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('bookkeeping');
		$register->setSchemas([1]);
		return $register;
	}//end makeRegister()

	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('items');
		$schema->setId(1);
		$schema->setProperties(
			[
				'status' => ['type' => 'string'],
				'amount' => ['type' => 'number'],
			]
		);
		return $schema;
	}//end makeSchema()
}//end class
