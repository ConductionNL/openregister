<?php

/**
 * Unit tests for the multi-value / `in`-operator filter fix on the
 * value/grouped path (REQ-AGG-106, bug #2027).
 *
 * Before this fix:
 *  - a bare array filter value (`{tags: ['a', 'b']}`, no `in` wrapper) was
 *    NOT translated to an `IN (...)` predicate. On the native-SQL path its
 *    numeric array keys (`0`, `1`, …) failed the operator allow-list and
 *    bailed the WHOLE query to the PHP fallback; the PHP fallback then
 *    iterated those same numeric keys through `checkOp()`, where they
 *    matched no known operator and fell to `default => true`, so the
 *    clause silently matched EVERY row instead of any-of the list;
 *  - a multi-value (array-typed) schema property compared via plain
 *    equality or `IN (...)` never matched, because the stored column value
 *    is the WHOLE JSON array, not its members.
 *
 * This suite proves: (1) the native path now emits a genuine `IN (...)`
 * for a bare array filter and counts correctly; (2) the PHP fallback path
 * gives the identical any-of count (previously it silently counted
 * everything); (3) the wrapped `in` operator continues to count correctly
 * (regression); (4) a multi-value (JSON-array) property correctly overlap-
 * matches a scalar or list filter via the PHP fallback, which the native
 * path now deliberately defers to instead of emitting a wrong-but-
 * plausible equality/IN predicate.
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
 * @spec openspec/changes/adhoc-aggregation-suite/specs/aggregation-api/spec.md
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
 */
class AggregationRunnerMultiValueFilterTest extends TestCase
{

    /**
     * Five rows: 2 open, 1 in-progress, 2 closed. Used for the plain
     * scalar-property bare-array / `in` scenarios.
     *
     * @return array<int, array<string, mixed>>
     */
    private function statusDataset(): array
    {
        return [
            ['status' => 'open',        'region' => 'nl', 'amount' => 10],
            ['status' => 'open',        'region' => 'nl', 'amount' => 20],
            ['status' => 'in-progress', 'region' => 'nl', 'amount' => 30],
            ['status' => 'closed',      'region' => 'be', 'amount' => 40],
            ['status' => 'closed',      'region' => 'be', 'amount' => 50],
        ];
    }

    /**
     * Four rows whose `tags` property is a multi-value JSON array. Row 4
     * (`['x']`) is excluded by every scenario in this suite, proving the
     * overlap test is genuinely selective, not an always-true no-op.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tagsDataset(): array
    {
        return [
            ['id' => 1, 'tags' => ['a', 'b']],
            ['id' => 2, 'tags' => ['b', 'c']],
            ['id' => 3, 'tags' => ['a']],
            ['id' => 4, 'tags' => ['x']],
        ];
    }

    // -----------------------------------------------------------------------
    // Native-SQL path: bare array => implicit `in`.
    // -----------------------------------------------------------------------

    /**
     * Note: the runner's ungrouped scalar native path is Postgres-only (see
     * `tryNativeAggregation()`'s "the ungrouped scalar path is Postgres-only"
     * docblock) — MySQL/SQLite run the categorical-groupBy and time-bucket
     * paths natively. These native-SQL tests therefore add a `groupBy` so
     * the fix is proven against REAL SQLite execution end-to-end (SQL text
     * AND resulting count), the same pattern `AggregationRunnerMultiFieldGroupByTest`
     * already established. The `region` field isolates the group so a
     * correct any-of filter surfaces as `nl => 3` with `be` absent entirely
     * (its rows are filtered out before GROUP BY, not miscounted).
     */
    public function testNativeBareArrayFilterEmitsInPredicateAndCountsAnyOf(): void
    {
        $runner = $this->makeNativeRunner(dataset: $this->statusDataset(), capturedSql: $captured);

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => ['open', 'in-progress']],
                groupBy: ['field' => 'region']
            )
        );

        $this->assertSame('sqlite', $result['backend'], 'native path MUST run on SQLite');
        $this->assertStringContainsString(
            '"status" IN (?, ?)',
            $captured['sql'],
            'a bare array filter value MUST emit an IN (...) predicate, not be silently dropped'
        );
        $this->assertSame(
            ['nl' => 3],
            $this->foldSingle($result['groups']),
            '2 open + 1 in-progress, all region nl = 3; region be is filtered out entirely (NOT all 5, NOT 0)'
        );

    }//end testNativeBareArrayFilterEmitsInPredicateAndCountsAnyOf()

    public function testNativeBareArrayFilterWithEmptyListMatchesNothing(): void
    {
        $runner = $this->makeNativeRunner(dataset: $this->statusDataset(), capturedSql: $captured);

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => []],
                groupBy: ['field' => 'region']
            )
        );

        $this->assertStringContainsString('1 = 0', $captured['sql']);
        $this->assertSame([], $result['groups'], 'an implicit `in` over an empty list MUST match nothing');

    }//end testNativeBareArrayFilterWithEmptyListMatchesNothing()

    public function testNativeWrappedInOperatorStillCountsAnyOf(): void
    {
        // Regression: the wrapped `{in: [...]}` shape already worked before
        // this fix — pin it stays correct.
        $runner = $this->makeNativeRunner(dataset: $this->statusDataset(), capturedSql: $captured);

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => ['in' => ['open', 'in-progress']]],
                groupBy: ['field' => 'region']
            )
        );

        $this->assertSame(['nl' => 3], $this->foldSingle($result['groups']));

    }//end testNativeWrappedInOperatorStillCountsAnyOf()

    /**
     * Fold a single-field grouped result into a `key => value` map.
     *
     * @param array<int, array{key: mixed, value: mixed}> $groups
     *
     * @return array<string, int|float>
     */
    private function foldSingle(array $groups): array
    {
        $folded = [];
        foreach ($groups as $group) {
            $folded[(string) $group['key']] = $group['value'];
        }

        return $folded;

    }//end foldSingle()

    // -----------------------------------------------------------------------
    // PHP-fallback path: the actual #2027 regression.
    // -----------------------------------------------------------------------

    public function testPhpFallbackBareArrayFilterCountsAnyOfNotAllRows(): void
    {
        // Before the fix: numeric array keys (0, 1, ...) matched no known
        // operator in checkOp() and fell through to `default => true`, so
        // EVERY row matched regardless of the filter (count would be 5,
        // not 3). This is the direct #2027 regression proof.
        $runner = $this->makePhpFallbackRunner(dataset: $this->statusDataset());

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => ['open', 'in-progress']]
            )
        );

        $this->assertSame('php-fallback', $result['backend']);
        $this->assertSame(3, $result['value'], '2 open + 1 in-progress = 3 (previously silently counted all 5)');

    }//end testPhpFallbackBareArrayFilterCountsAnyOfNotAllRows()

    public function testPhpFallbackWrappedInOperatorCountsAnyOf(): void
    {
        $runner = $this->makePhpFallbackRunner(dataset: $this->statusDataset());

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => ['in' => ['open', 'in-progress']]]
            )
        );

        $this->assertSame(3, $result['value']);

    }//end testPhpFallbackWrappedInOperatorCountsAnyOf()

    public function testPhpFallbackScalarEqualityIsUnaffected(): void
    {
        // Regression: plain scalar equality (the pre-existing, most common
        // shape) MUST still work exactly as before.
        $runner = $this->makePhpFallbackRunner(dataset: $this->statusDataset());

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(metric: 'count', filter: ['status' => 'open'])
        );

        $this->assertSame(2, $result['value']);

    }//end testPhpFallbackScalarEqualityIsUnaffected()

    // -----------------------------------------------------------------------
    // Multi-value (JSON-array) object property overlap.
    // -----------------------------------------------------------------------

    public function testNativePathDefersMultiValuePropertyFilterToPhpFallback(): void
    {
        // A `tags` filter on an array-typed property MUST NOT be translated
        // to a native `= ?` / `IN (...)` predicate (the column holds the
        // WHOLE array; equality/IN against it would silently mismatch) —
        // the runner defers to the PHP fallback, which does the overlap
        // test correctly.
        $runner = $this->makeNativeRunner(dataset: $this->tagsDataset(), capturedSql: $captured, schema: $this->makeTagsSchema());

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeTagsSchema(),
            query: AggregationQuery::create(metric: 'count', filter: ['tags' => 'a'])
        );

        $this->assertSame('php-fallback', $result['backend'], 'a multi-value property filter MUST defer to PHP, not native SQL');

    }//end testNativePathDefersMultiValuePropertyFilterToPhpFallback()

    public function testPhpFallbackScalarFilterOverlapsMultiValueProperty(): void
    {
        $runner = $this->makePhpFallbackRunnerForTags();

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeTagsSchema(),
            query: AggregationQuery::create(metric: 'count', filter: ['tags' => 'a'])
        );

        // Rows 1 (['a','b']) and 3 (['a']) contain 'a'; row 4 (['x']) does not.
        $this->assertSame(2, $result['value']);

    }//end testPhpFallbackScalarFilterOverlapsMultiValueProperty()

    public function testPhpFallbackBareArrayFilterOverlapsMultiValueProperty(): void
    {
        $runner = $this->makePhpFallbackRunnerForTags();

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeTagsSchema(),
            query: AggregationQuery::create(metric: 'count', filter: ['tags' => ['a', 'c']])
        );

        // Rows 1 (a,b — has a), 2 (b,c — has c), 3 (a — has a) overlap;
        // row 4 (x) does not.
        $this->assertSame(3, $result['value']);

    }//end testPhpFallbackBareArrayFilterOverlapsMultiValueProperty()

    public function testPhpFallbackInOperatorOverlapsMultiValueProperty(): void
    {
        $runner = $this->makePhpFallbackRunnerForTags();

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeTagsSchema(),
            query: AggregationQuery::create(metric: 'count', filter: ['tags' => ['in' => ['x']]])
        );

        // Only row 4 has 'x'.
        $this->assertSame(1, $result['value']);

    }//end testPhpFallbackInOperatorOverlapsMultiValueProperty()

    // -----------------------------------------------------------------------
    // Regression pin: single-field/single-metric response shape unchanged.
    // -----------------------------------------------------------------------

    public function testUngroupedSingleMetricResponseShapeIsByteIdentical(): void
    {
        $runner = $this->makeNativeRunner(dataset: $this->statusDataset(), capturedSql: $captured);

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(metric: 'count', filter: ['status' => 'open'])
        );

        $this->assertSame(
            ['backend', 'cached', 'value'],
            $this->sortedKeys($result),
            'single-metric ungrouped envelope MUST carry exactly {value, backend, cached} — no `values` key'
        );
        $this->assertSame(2, $result['value']);
        $this->assertArrayNotHasKey('values', $result);

    }//end testUngroupedSingleMetricResponseShapeIsByteIdentical()

    public function testGroupedSingleFieldSingleMetricResponseShapeIsByteIdentical(): void
    {
        $runner = $this->makeNativeRunner(dataset: $this->statusDataset(), capturedSql: $captured);

        $result = $runner->runAdhoc(
            register: $this->makeRegister(),
            schema: $this->makeStatusSchema(),
            query: AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status'])
        );

        $this->assertSame(['backend', 'cached', 'groups'], $this->sortedKeys($result));
        foreach ($result['groups'] as $group) {
            $this->assertSame(
                ['key', 'value'],
                $this->sortedKeys($group),
                'single-field grouped rows MUST carry exactly {key, value} — no `keys`/`values` keys'
            );
        }

    }//end testGroupedSingleFieldSingleMetricResponseShapeIsByteIdentical()

    /**
     * @param array<string, mixed> $arr
     *
     * @return array<int, string>
     */
    private function sortedKeys(array $arr): array
    {
        $keys = array_keys($arr);
        sort($keys);
        return $keys;

    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Build a runner whose IDBConnection executes real SQL against an
     * in-memory SQLite database seeded with the given dataset (status/amount
     * shape). Captures the prepared SQL string.
     *
     * @param array<int, array<string, mixed>> $dataset
     * @param mixed                             &$capturedSql
     * @param Schema|null                       $schema Schema used for the multi-value bail check (defaults to the status schema).
     *
     * @return AggregationRunner
     */
    private function makeNativeRunner(array $dataset, mixed &$capturedSql, ?Schema $schema=null): AggregationRunner
    {
        $captured = new \ArrayObject(['sql' => null]);
        $pdo      = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $isTags = ($schema !== null && $schema->getSlug() === 'tagged-items');
        if ($isTags === true) {
            $pdo->exec('CREATE TABLE "oc_register_1_schema_x" ("_deleted" TEXT, "_organisation" TEXT, "id" INTEGER, "tags" TEXT)');
            $insert = $pdo->prepare('INSERT INTO "oc_register_1_schema_x" ("_deleted", "_organisation", "id", "tags") VALUES (NULL, ?, ?, ?)');
            foreach ($dataset as $row) {
                $insert->execute(['__no_active_org__', $row['id'], json_encode($row['tags'])]);
            }
        } else {
            $pdo->exec('CREATE TABLE "oc_register_1_schema_x" ("_deleted" TEXT, "_organisation" TEXT, "status" TEXT, "region" TEXT, "amount" INTEGER)');
            $insert = $pdo->prepare('INSERT INTO "oc_register_1_schema_x" ("_deleted", "_organisation", "status", "region", "amount") VALUES (NULL, ?, ?, ?, ?)');
            foreach ($dataset as $row) {
                $insert->execute(['__no_active_org__', $row['status'], ($row['region'] ?? null), $row['amount']]);
            }
        }

        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabasePlatform')->willReturn($this->createMock(SqlitePlatform::class));
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($pdo, $captured): IPreparedStatement {
                $captured['sql'] = $sql;
                $pdoStmt         = $pdo->prepare($sql);
                $stmt            = $this->createMock(IPreparedStatement::class);
                $stmt->method('execute')->willReturnCallback(
                    function ($bindings=[]) use ($pdoStmt): IResult {
                        $pdoStmt->execute(($bindings ?? []));
                        return $this->createMock(IResult::class);
                    }
                );
                $stmt->method('fetch')->willReturnCallback(static fn() => $pdoStmt->fetch(PDO::FETCH_ASSOC));
                return $stmt;
            }
        );

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_x');

        // Always wire the PHP-fallback data source too (not just for the
        // `tags` dataset): the ungrouped scalar native path is Postgres-only
        // (see tryNativeAggregation()'s docblock), so an ungrouped query
        // against this SQLite-platform runner falls through to bucketInPhp()
        // and needs real rows to filter, not an empty/null default.
        $entities = [];
        foreach ($dataset as $row) {
            $entity = $this->createMock(ObjectEntity::class);
            $entity->method('getObject')->willReturn($row);
            $entities[] = $entity;
        }

        $magicMapper->method('findAllInRegisterSchemaTable')->willReturn($entities);

        $runner       = $this->makeRunner(db: $db, magicMapper: $magicMapper);
        $capturedSql  = $captured;
        return $runner;

    }//end makeNativeRunner()

    /**
     * Build a runner whose platform is unrecognised (forcing the PHP
     * fallback) and whose MagicMapper returns the status/amount dataset.
     *
     * @param array<int, array<string, mixed>> $dataset
     *
     * @return AggregationRunner
     */
    private function makePhpFallbackRunner(array $dataset): AggregationRunner
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $entities = [];
        foreach ($dataset as $row) {
            $entity = $this->createMock(ObjectEntity::class);
            $entity->method('getObject')->willReturn($row);
            $entities[] = $entity;
        }

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('findAllInRegisterSchemaTable')->willReturn($entities);
        $magicMapper->method('getTableNameForRegisterSchema')->willReturn('register_1_schema_x');

        return $this->makeRunner(db: $db, magicMapper: $magicMapper);

    }//end makePhpFallbackRunner()

    /**
     * PHP-fallback runner for the multi-value `tags` dataset, forced via an
     * unrecognised platform (same mechanism as {@see makePhpFallbackRunner()}).
     *
     * @return AggregationRunner
     */
    private function makePhpFallbackRunnerForTags(): AggregationRunner
    {
        return $this->makePhpFallbackRunner(dataset: $this->tagsDataset());

    }//end makePhpFallbackRunnerForTags()

    /**
     * Assemble an AggregationRunner with the given DB + MagicMapper and
     * permissive RBAC / null-org / no-translation collaborators.
     *
     * @param IDBConnection $db          The (real-SQLite-backed or unknown) connection.
     * @param MagicMapper   $magicMapper The magic-table mapper.
     *
     * @return AggregationRunner
     */
    private function makeRunner(IDBConnection $db, MagicMapper $magicMapper): AggregationRunner
    {
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

    private function makeRegister(): Register
    {
        $register = new Register();
        $register->setSlug('bookkeeping');
        $register->setSchemas([1]);
        return $register;

    }//end makeRegister()

    private function makeStatusSchema(): Schema
    {
        $schema = new Schema();
        $schema->setSlug('status-items');
        $schema->setId(1);
        $schema->setProperties(
            [
                'status' => ['type' => 'string'],
                'region' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
            ]
        );
        return $schema;

    }//end makeStatusSchema()

    private function makeTagsSchema(): Schema
    {
        $schema = new Schema();
        $schema->setSlug('tagged-items');
        $schema->setId(1);
        $schema->setProperties(
            [
                'id'   => ['type' => 'integer'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]
        );
        return $schema;

    }//end makeTagsSchema()
}//end class
