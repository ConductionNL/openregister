<?php

/**
 * Unit tests for multi-field (cross-tab) categorical groupBy in
 * `AggregationRunner` — both the native-SQL path (executed against a real
 * in-memory SQLite database) and the PHP fallback path, plus proof the two
 * paths agree on a known dataset.
 *
 * The native path is driven through a real `PDO('sqlite::memory:')` behind
 * the mocked Nextcloud `IDBConnection` / `IPreparedStatement` surface, so
 * the emitted `GROUP BY a, b` SQL is actually parsed and executed by SQLite
 * and the grouped tuples/values are real database output — not fixtures.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
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
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationQuery
 */
class AggregationRunnerMultiFieldGroupByTest extends TestCase {

	/**
	 * The state filter shared by every test — mirrors shillinq's
	 * agedPayables* `where` clause.
	 *
	 * @var array<string, mixed>
	 */
	private const STATE_FILTER = ['state' => ['in' => ['issued', 'partially-paid', 'overdue', 'disputed']]];

	/**
	 * Canonical dataset. Each row is keyed by schema property name
	 * (camelCase); the native path reads the snake_case magic-table columns,
	 * the PHP path reads these keys directly. Row 7 (paid) is excluded by
	 * the state filter and proves filtering is honoured on both paths.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dataset(): array {
		return [
			['vendorId' => 'V1', 'dueDateBucket' => 'current', 'state' => 'issued',   'amount' => 100],
			['vendorId' => 'V1', 'dueDateBucket' => 'current', 'state' => 'issued',   'amount' => 50],
			['vendorId' => 'V1', 'dueDateBucket' => '30days',  'state' => 'overdue',  'amount' => 200],
			['vendorId' => 'V2', 'dueDateBucket' => 'current', 'state' => 'issued',   'amount' => 300],
			['vendorId' => 'V2', 'dueDateBucket' => '30days',  'state' => 'disputed', 'amount' => 75],
			['vendorId' => 'V2', 'dueDateBucket' => '30days',  'state' => 'overdue',  'amount' => 25],
			['vendorId' => 'V3', 'dueDateBucket' => 'current', 'state' => 'paid',     'amount' => 999],
		];
	}

	/**
	 * Two-field native groupBy returns one row per distinct (vendor, bucket)
	 * tuple with the correct SUM, and the emitted SQL is a real GROUP BY a, b.
	 */
	public function testNativeTwoFieldGroupBySum(): void {
		$runner = $this->makeNativeRunner(capturedSql: $captured);
		$query = AggregationQuery::create(
			metric: 'sum',
			field: 'amount',
			filter: self::STATE_FILTER,
			groupBy: ['fields' => ['vendorId', 'dueDateBucket']]
		);

		$result = $runner->runAdhoc(register: $this->makeRegister(), schema: $this->makeSchema(), query: $query);

		$this->assertSame('sqlite', $result['backend'], 'native path MUST run on SQLite');
		$this->assertStringContainsString(
			'GROUP BY "vendor_id", "due_date_bucket"',
			$captured['sql'],
			'native path MUST emit a two-column GROUP BY'
		);

		// foldMulti() ksorts, so the expected map is in key-sorted order.
		$expected = [
			'V1|30days' => 200.0,
			'V1|current' => 150.0,
			'V2|30days' => 100.0,
			'V2|current' => 300.0,
		];
		$this->assertSame($expected, $this->foldMulti($result['groups']));
	}

	/**
	 * Two-field native groupBy with the count metric.
	 */
	public function testNativeTwoFieldGroupByCount(): void {
		$runner = $this->makeNativeRunner(capturedSql: $captured);
		$query = AggregationQuery::create(
			metric: 'count',
			filter: self::STATE_FILTER,
			groupBy: ['vendorId', 'dueDateBucket']
		);

		$result = $runner->runAdhoc(register: $this->makeRegister(), schema: $this->makeSchema(), query: $query);
		$expected = [
			'V1|30days' => 1.0,
			'V1|current' => 2.0,
			'V2|30days' => 2.0,
			'V2|current' => 1.0,
		];
		$this->assertSame($expected, $this->foldMulti($result['groups']));
	}

	/**
	 * Single-field groupBy keeps the backward-compatible `{key, value}`
	 * shape on the native path (no `keys` map).
	 */
	public function testNativeSingleFieldGroupByIsBackwardCompatible(): void {
		$runner = $this->makeNativeRunner(capturedSql: $captured);
		$query = AggregationQuery::create(
			metric: 'sum',
			field: 'amount',
			filter: self::STATE_FILTER,
			groupBy: ['field' => 'vendorId']
		);

		$result = $runner->runAdhoc(register: $this->makeRegister(), schema: $this->makeSchema(), query: $query);

		foreach ($result['groups'] as $group) {
			$this->assertArrayHasKey('key', $group, 'single-field groups keep the legacy `key` shape');
			$this->assertArrayNotHasKey('keys', $group, 'single-field groups MUST NOT carry a composite `keys` map');
		}

		$folded = [];
		foreach ($result['groups'] as $group) {
			$folded[(string)$group['key']] = (float)$group['value'];
		}

		$this->assertSame(['V1' => 350.0, 'V2' => 400.0], $folded);
	}

	/**
	 * The native-SQL and PHP-fallback paths agree on the exact same grouped
	 * tuples and values for the two-field sum aggregation.
	 */
	public function testNativeAndPhpFallbackAgree(): void {
		$native = $this->makeNativeRunner(capturedSql: $ignored)->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'sum',
				field: 'amount',
				filter: self::STATE_FILTER,
				groupBy: ['fields' => ['vendorId', 'dueDateBucket']]
			)
		);

		$php = $this->makePhpFallbackRunner()->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: AggregationQuery::create(
				metric: 'sum',
				field: 'amount',
				filter: self::STATE_FILTER,
				groupBy: ['fields' => ['vendorId', 'dueDateBucket']]
			)
		);

		$this->assertSame('sqlite', $native['backend'], 'native path MUST report sqlite');
		$this->assertSame('php-fallback', $php['backend'], 'fallback path MUST report php-fallback');

		$foldedNative = $this->foldMulti($native['groups']);
		$foldedPhp = $this->foldMulti($php['groups']);

		$this->assertSame($foldedNative, $foldedPhp, 'native and PHP-fallback grouped results MUST agree');
		$this->assertSame(
			[
				'V1|30days' => 200.0,
				'V1|current' => 150.0,
				'V2|30days' => 100.0,
				'V2|current' => 300.0,
			],
			$foldedNative
		);
	}

	/**
	 * Fold a multi-field grouped result into a `"A|B" => float` map so the
	 * assertion is order-independent (native and PHP paths may emit tuples
	 * in different orders).
	 *
	 * @param array<int, array{keys: array<string, mixed>, value: mixed}> $groups Grouped rows.
	 *
	 * @return array<string, float>
	 */
	private function foldMulti(array $groups): array {
		$folded = [];
		foreach ($groups as $group) {
			$this->assertArrayHasKey('keys', $group, 'multi-field groups MUST expose a composite `keys` map');
			$key = $group['keys']['vendorId'] . '|' . $group['keys']['dueDateBucket'];
			$folded[$key] = (float)$group['value'];
		}

		ksort($folded);
		return $folded;
	}

	/**
	 * Build a runner whose IDBConnection executes real SQL against an
	 * in-memory SQLite database seeded with the canonical dataset. The
	 * prepared SQL string is captured into $capturedSql for assertion.
	 *
	 * @param mixed &$capturedSql Receives the last prepared SQL string.
	 *
	 * @return AggregationRunner
	 */
	private function makeNativeRunner(mixed &$capturedSql): AggregationRunner {
		$captured = new \ArrayObject(['sql' => null]);
		$pdo = $this->seedSqlite();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(SqlitePlatform::class));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($pdo, $captured): IPreparedStatement {
				$captured['sql'] = $sql;
				$pdoStmt = $pdo->prepare($sql);
				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function ($bindings = []) use ($pdoStmt): IResult {
						$pdoStmt->execute(($bindings ?? []));
						return $this->createMock(IResult::class);
					}
				);
				$stmt->method('fetch')->willReturnCallback(
					static fn () => $pdoStmt->fetch(PDO::FETCH_ASSOC)
				);
				return $stmt;
			}
		);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_ap_tx');

		$runner = $this->makeRunner(db: $db, magicMapper: $magicMapper);
		$capturedSql = $captured;
		return $runner;
	}

	/**
	 * Build a runner whose platform is unrecognised (so the native path
	 * bails immediately) and whose MagicMapper returns the canonical dataset
	 * as ObjectEntity rows — forcing the PHP fallback bucketer.
	 *
	 * @return AggregationRunner
	 */
	private function makePhpFallbackRunner(): AggregationRunner {
		$db = $this->createMock(IDBConnection::class);
		// AbstractPlatform mock class name contains neither postgres/mysql/
		// sqlite → detectDatabasePlatform() returns 'unknown' → native bails.
		$db->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

		$entities = [];
		foreach ($this->dataset() as $row) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($row);
			$entities[] = $entity;
		}

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturn($entities);
		$magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_ap_tx');

		return $this->makeRunner(db: $db, magicMapper: $magicMapper);
	}

	/**
	 * Create + seed an in-memory SQLite magic table with the canonical
	 * dataset. Column names mirror MagicMapper's snake_case sanitisation.
	 *
	 * @return PDO
	 */
	private function seedSqlite(): PDO {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(
			'CREATE TABLE "oc_register_1_schema_ap_tx" (
                "_deleted" TEXT,
                "_organisation" TEXT,
                "vendor_id" TEXT,
                "due_date_bucket" TEXT,
                "state" TEXT,
                "amount" INTEGER
            )'
		);

		$insert = $pdo->prepare(
			'INSERT INTO "oc_register_1_schema_ap_tx"
                ("_deleted", "_organisation", "vendor_id", "due_date_bucket", "state", "amount")
             VALUES (NULL, ?, ?, ?, ?, ?)'
		);
		foreach ($this->dataset() as $row) {
			$insert->execute(
				[
					'__no_active_org__',
					$row['vendorId'],
					$row['dueDateBucket'],
					$row['state'],
					$row['amount'],
				]
			);
		}

		return $pdo;
	}

	/**
	 * Assemble an AggregationRunner with the given DB + MagicMapper and
	 * permissive RBAC / null-org / no-translation collaborators.
	 *
	 * @param IDBConnection $db The (real-SQLite-backed or unknown) connection.
	 * @param MagicMapper $magicMapper The magic-table mapper.
	 *
	 * @return AggregationRunner
	 */
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
	}

	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('bookkeeping');
		$register->setSchemas([1]);
		return $register;
	}

	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('ap-tx');
		$schema->setId(1);
		return $schema;
	}
}//end class
