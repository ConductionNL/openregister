<?php

/**
 * Unit tests for cross-schema aggregation support in AggregationRunner.
 *
 * Covers:
 * - @self.<field> reference resolution in where clauses
 * - `select` as alias for `metric`
 * - `where` as alias for `filter`
 * - Delegation to runCrossSchema() when `from` is present
 * - Schema-not-found error propagation
 * - Malformed where-clause handling
 * - Register-not-found error propagation
 * - Permission gate on the target schema
 *
 * The Postgres-native and PHP-fallback paths are tested through the
 * integration test (AggregationRunnerIntegrationTest). These unit tests
 * verify the routing, aliasing, and error-handling logic.
 *
 * Schema and Register are Nextcloud Entity subclasses — they use `__call`
 * magic for getters/setters and cannot be mocked directly. Tests use real
 * entity instances populated via their setter methods.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 */
class CrossSchemaAggregationRunnerTest extends TestCase
{

    private MagicMapper&MockObject $magicMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private PlaceholderResolver $placeholderResolver;
    private IDBConnection&MockObject $db;
    private AggregationCache&MockObject $cache;
    private PermissionHandler&MockObject $permissionHandler;
    private IUserSession&MockObject $userSession;
    private OrganisationService&MockObject $organisationService;
    private AggregationRunner $runner;


    protected function setUp(): void
    {
        parent::setUp();

        $this->magicMapper         = $this->createMock(MagicMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->db                  = $this->createMock(IDBConnection::class);
        $this->cache               = $this->createMock(AggregationCache::class);
        $this->permissionHandler   = $this->createMock(PermissionHandler::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->organisationService = $this->createMock(OrganisationService::class);

        // PlaceholderResolver is declared `final` and cannot be mocked.
        // We use a real instance wired to the same mock session.
        $this->placeholderResolver = new PlaceholderResolver($this->userSession);

        // Default: no active user session (unauthenticated / CLI context).
        $this->userSession->method('getUser')->willReturn(null);
        // Default: cache always misses.
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set');
        // Default: no active organisation.
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $this->runner = new AggregationRunner(
            magicMapper: $this->magicMapper,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            placeholders: $this->placeholderResolver,
            db: $this->db,
            cache: $this->cache,
            permissionHandler: $this->permissionHandler,
            userSession: $this->userSession,
            organisationService: $this->organisationService,
            searchBackend: null
        );

    }//end setUp()


    // -----------------------------------------------------------------------
    // Helper factories — use real entity instances (NC Entity __call magic
    // prevents mocking getSlug/getId/getConfiguration on Schema/Register).
    // -----------------------------------------------------------------------

    /**
     * Build a Schema entity with the given slug, id, and aggregations.
     *
     * @param string               $slug         Schema slug.
     * @param int                  $id           Schema DB id.
     * @param array<string, mixed> $aggregations Aggregation annotation map.
     *
     * @return Schema
     */
    private function makeSchema(string $slug, int $id, array $aggregations=[]): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setId($id);
        if ($aggregations !== []) {
            $schema->setConfiguration(['x-openregister-aggregations' => $aggregations]);
        }

        return $schema;
    }//end makeSchema()


    /**
     * Build a Register entity whose schemas list contains the given IDs.
     *
     * @param string $slug      Register slug.
     * @param int[]  $schemaIds Schema IDs in this register.
     *
     * @return Register
     */
    private function makeRegister(string $slug, array $schemaIds=[]): Register
    {
        $register = new Register();
        $register->setSlug($slug);
        $register->setSchemas($schemaIds);
        return $register;
    }//end makeRegister()


    /**
     * Build a stub ObjectEntity that returns a fixed object array.
     *
     * @param array<string, mixed> $data The object data.
     *
     * @return ObjectEntity&MockObject
     */
    private function makeEntityStub(array $data): ObjectEntity&MockObject
    {
        $e = $this->createMock(ObjectEntity::class);
        $e->method('getObject')->willReturn($data);
        return $e;
    }//end makeEntityStub()


    /**
     * Configure the IDBConnection mock to look like a non-Postgres platform
     * so tryNativeAggregation() returns null (forces PHP-fallback path).
     */
    private function usePhpFallback(): void
    {
        $platform = new class {
            public function __toString(): string { return 'OtherPlatform'; }
        };
        $this->db->method('getDatabasePlatform')->willReturn($platform);
    }//end usePhpFallback()


    // -----------------------------------------------------------------------
    // Tests: @self reference resolution
    // -----------------------------------------------------------------------

    /**
     * @test
     * Cross-schema count uses @self.slug from the parent row when resolving
     * the `regulationSlug` filter in the where clause.
     */
    public function testAtSelfFieldReferenceResolvedFromParentRow(): void
    {
        $parentSchema = $this->makeSchema('regulation', 10, [
            'mandatoryEnrolledCount' => [
                'from'   => 'scholiq-enrolment',
                'where'  => ['regulationSlug' => '@self.slug', 'mandatory' => true],
                'select' => 'count',
            ],
        ]);
        $targetSchema   = $this->makeSchema('scholiq-enrolment', 20);
        $parentRegister = $this->makeRegister('scholiq', [10]);
        $targetRegister = $this->makeRegister('scholiq', [20]);

        $this->registerMapper->method('find')
            ->with('scholiq')
            ->willReturn($parentRegister);

        $this->schemaMapper->method('find')
            ->willReturnMap([
                ['regulation', [], null, true, true, $parentSchema],
                ['scholiq-enrolment', [], null, true, true, $targetSchema],
            ]);

        $this->registerMapper->method('findAll')
            ->willReturn([$parentRegister, $targetRegister]);

        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->usePhpFallback();

        // Three enrolment rows: 2 match regulationSlug='adr-2026' + mandatory=true.
        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([
                $this->makeEntityStub(['regulationSlug' => 'adr-2026', 'mandatory' => true]),
                $this->makeEntityStub(['regulationSlug' => 'adr-2026', 'mandatory' => true]),
                $this->makeEntityStub(['regulationSlug' => 'other-reg', 'mandatory' => true]),
            ]);

        $result = $this->runner->run(
            registerRef: 'scholiq',
            schemaRef: 'regulation',
            name: 'mandatoryEnrolledCount',
            bypassRbac: true,
            parentRow: ['slug' => 'adr-2026']
        );

        $this->assertSame(2, $result['value']);
        $this->assertSame('scholiq-enrolment', $result['from'] ?? null);
        $this->assertSame('count', $result['metric']);

    }//end testAtSelfFieldReferenceResolvedFromParentRow()


    /**
     * @test
     * An `@self.<field>` reference to a field absent in the parent row
     * resolves to null — the filter matches nothing (fail-closed).
     */
    public function testAtSelfMissingFieldResolvesToNull(): void
    {
        $parentSchema = $this->makeSchema('regulation', 10, [
            'orphanCount' => [
                'from'  => 'scholiq-enrolment',
                'where' => ['parentId' => '@self.nonExistentField'],
            ],
        ]);
        $targetSchema   = $this->makeSchema('scholiq-enrolment', 20);
        $parentRegister = $this->makeRegister('scholiq', [10]);
        $targetRegister = $this->makeRegister('scholiq', [20]);

        $this->registerMapper->method('find')->willReturn($parentRegister);
        $this->schemaMapper->method('find')
            ->willReturnMap([
                ['regulation', [], null, true, true, $parentSchema],
                ['scholiq-enrolment', [], null, true, true, $targetSchema],
            ]);
        $this->registerMapper->method('findAll')->willReturn([$parentRegister, $targetRegister]);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->usePhpFallback();

        // Row whose parentId is 'something' — null !== 'something' → filtered out.
        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([$this->makeEntityStub(['parentId' => 'something'])]);

        $result = $this->runner->run(
            registerRef: 'scholiq',
            schemaRef: 'regulation',
            name: 'orphanCount',
            bypassRbac: true,
            parentRow: []
        );

        $this->assertSame(0, $result['value']);

    }//end testAtSelfMissingFieldResolvesToNull()


    // -----------------------------------------------------------------------
    // Tests: select/where aliases
    // -----------------------------------------------------------------------

    /**
     * @test
     * `select` is accepted as an alias for `metric` in an intra-schema spec.
     */
    public function testSelectAliasWorksForIntraSchemaSpec(): void
    {
        $schema   = $this->makeSchema('task', 1, ['totalCount' => ['select' => 'count']]);
        $register = $this->makeRegister('tasks-reg', [1]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->usePhpFallback();

        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([$this->makeEntityStub(['title' => 'Task A'])]);

        $result = $this->runner->run('tasks-reg', 'task', 'totalCount', true);

        $this->assertSame('count', $result['metric']);
        $this->assertSame(1, $result['value']);

    }//end testSelectAliasWorksForIntraSchemaSpec()


    /**
     * @test
     * `where` is accepted as an alias for `filter` in an intra-schema spec.
     */
    public function testWhereAliasWorksForIntraSchemaSpec(): void
    {
        $schema   = $this->makeSchema('task', 1, [
            'openCount' => ['metric' => 'count', 'where' => ['status' => 'open']],
        ]);
        $register = $this->makeRegister('tasks-reg', [1]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->usePhpFallback();

        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([
                $this->makeEntityStub(['status' => 'open']),
                $this->makeEntityStub(['status' => 'open']),
                $this->makeEntityStub(['status' => 'closed']),
            ]);

        $result = $this->runner->run('tasks-reg', 'task', 'openCount', true);

        $this->assertSame(2, $result['value']);

    }//end testWhereAliasWorksForIntraSchemaSpec()


    // -----------------------------------------------------------------------
    // Tests: in-operator in cross-schema where clause
    // -----------------------------------------------------------------------

    /**
     * @test
     * The `in` operator in a cross-schema `where` clause filters correctly
     * in the PHP-fallback path.
     */
    public function testInOperatorInCrossSchemaWhere(): void
    {
        $parentSchema = $this->makeSchema('regulation', 10, [
            'activeEnrolled' => [
                'from'  => 'scholiq-enrolment',
                'where' => ['lifecycleState' => ['in' => ['active', 'completed']]],
            ],
        ]);
        $targetSchema   = $this->makeSchema('scholiq-enrolment', 20);
        $parentRegister = $this->makeRegister('scholiq', [10]);
        $targetRegister = $this->makeRegister('scholiq', [20]);

        $this->registerMapper->method('find')->willReturn($parentRegister);
        $this->schemaMapper->method('find')
            ->willReturnMap([
                ['regulation', [], null, true, true, $parentSchema],
                ['scholiq-enrolment', [], null, true, true, $targetSchema],
            ]);
        $this->registerMapper->method('findAll')->willReturn([$parentRegister, $targetRegister]);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->usePhpFallback();

        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([
                $this->makeEntityStub(['lifecycleState' => 'active']),
                $this->makeEntityStub(['lifecycleState' => 'pending']),
                $this->makeEntityStub(['lifecycleState' => 'completed']),
                $this->makeEntityStub(['lifecycleState' => 'cancelled']),
            ]);

        $result = $this->runner->run('scholiq', 'regulation', 'activeEnrolled', true);

        // Only 'active' and 'completed' match.
        $this->assertSame(2, $result['value']);

    }//end testInOperatorInCrossSchemaWhere()


    // -----------------------------------------------------------------------
    // Tests: error paths
    // -----------------------------------------------------------------------

    /**
     * @test
     * When the target schema named in `from` does not exist, a RuntimeException
     * is thrown.
     */
    public function testCrossSchemaThrowsWhenTargetSchemaNotFound(): void
    {
        $parentSchema   = $this->makeSchema('regulation', 10, [
            'enrolCount' => ['from' => 'nonexistent-schema'],
        ]);
        $parentRegister = $this->makeRegister('scholiq', [10]);

        $this->registerMapper->method('find')->willReturn($parentRegister);
        $this->permissionHandler->method('hasPermission')->willReturn(true);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function (string $ref) use ($parentSchema) {
                if ($ref === 'regulation') {
                    return $parentSchema;
                }

                throw new DoesNotExistException('not found');
            });

        $this->expectException(RuntimeException::class);

        $this->runner->run('scholiq', 'regulation', 'enrolCount', true);

    }//end testCrossSchemaThrowsWhenTargetSchemaNotFound()


    /**
     * @test
     * When no register contains the target schema, a RuntimeException is thrown.
     */
    public function testCrossSchemaThrowsWhenNoRegisterContainsTargetSchema(): void
    {
        $parentSchema   = $this->makeSchema('regulation', 10, [
            'enrolCount' => ['from' => 'orphan-schema'],
        ]);
        $targetSchema   = $this->makeSchema('orphan-schema', 99);
        $parentRegister = $this->makeRegister('scholiq', [10]);

        $this->registerMapper->method('find')->willReturn($parentRegister);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->schemaMapper->method('find')
            ->willReturnMap([
                ['regulation', [], null, true, true, $parentSchema],
                ['orphan-schema', [], null, true, true, $targetSchema],
            ]);
        // findAll returns only registers that don't contain schema 99.
        $this->registerMapper->method('findAll')->willReturn([$parentRegister]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No register found that contains schema "orphan-schema"');

        $this->runner->run('scholiq', 'regulation', 'enrolCount', true);

    }//end testCrossSchemaThrowsWhenNoRegisterContainsTargetSchema()


    /**
     * @test
     * When the `where` clause is a non-array scalar, the runner is defensive:
     * the cast to array produces an empty filter, so all rows pass through.
     */
    public function testMalformedWhereClauseCastToArrayDoesNotCrash(): void
    {
        $parentSchema = $this->makeSchema('regulation', 10, [
            'enrolCount' => [
                'from'  => 'scholiq-enrolment',
                'where' => 'this-is-not-a-map',  // invalid — (array) cast → [0 => 'this-is-not-a-map']
            ],
        ]);
        $targetSchema   = $this->makeSchema('scholiq-enrolment', 20);
        $parentRegister = $this->makeRegister('scholiq', [10]);
        $targetRegister = $this->makeRegister('scholiq', [20]);

        $this->registerMapper->method('find')->willReturn($parentRegister);
        $this->schemaMapper->method('find')
            ->willReturnMap([
                ['regulation', [], null, true, true, $parentSchema],
                ['scholiq-enrolment', [], null, true, true, $targetSchema],
            ]);
        $this->registerMapper->method('findAll')->willReturn([$parentRegister, $targetRegister]);
        $this->permissionHandler->method('hasPermission')->willReturn(true);
        $this->usePhpFallback();

        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([$this->makeEntityStub(['regulationSlug' => 'abc'])]);

        // Should not crash; check a value key exists in result.
        $result = $this->runner->run('scholiq', 'regulation', 'enrolCount', true);
        $this->assertArrayHasKey('value', $result);

    }//end testMalformedWhereClauseCastToArrayDoesNotCrash()


    /**
     * @test
     * When bypassRbac=false and the caller lacks list permission on the target
     * schema, the runner throws a RuntimeException (forbidden).
     */
    public function testCrossSchemaRbacGateOnTargetSchema(): void
    {
        $parentSchema   = $this->makeSchema('regulation', 10, [
            'enrolCount' => ['from' => 'scholiq-enrolment'],
        ]);
        $targetSchema   = $this->makeSchema('scholiq-enrolment', 20);
        $parentRegister = $this->makeRegister('scholiq', [10]);
        $targetRegister = $this->makeRegister('scholiq', [20]);

        $this->registerMapper->method('find')->willReturn($parentRegister);
        $this->schemaMapper->method('find')
            ->willReturnMap([
                ['regulation', [], null, true, true, $parentSchema],
                ['scholiq-enrolment', [], null, true, true, $targetSchema],
            ]);
        $this->registerMapper->method('findAll')->willReturn([$parentRegister, $targetRegister]);

        // Parent schema: permitted. Target schema: forbidden.
        $callCount = 0;
        $this->permissionHandler->method('hasPermission')
            ->willReturnCallback(
                function () use (&$callCount): bool {
                    $callCount++;
                    // First call = parent schema (allowed), second = target schema (denied).
                    return $callCount === 1;
                }
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden');

        $this->runner->run('scholiq', 'regulation', 'enrolCount', bypassRbac: false);

    }//end testCrossSchemaRbacGateOnTargetSchema()


}//end class
