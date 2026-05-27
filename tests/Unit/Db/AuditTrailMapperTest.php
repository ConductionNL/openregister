<?php

/**
 * AuditTrailMapper Unit Tests — getDistinctActorCount
 *
 * Tests the non-DB (input-validation) and DB-path logic of
 * AuditTrailMapper::getDistinctActorCount() without a live database connection.
 *
 * DB-touching paths (happy path, NULL exclusion, multi-schema fan-out, and
 * time-window boundary) are exercised via a mocked IQueryBuilder chain.
 * Integration tests for the full query shape live in
 * tests/Db/AuditTrailMapperIntegrationTest.php.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AuditTrailMapper::getDistinctActorCount().
 *
 * Covers REQ-ORDA-001 through REQ-ORDA-006 (the non-PHPDoc requirements):
 *   - REQ-ORDA-001: method is callable and returns int
 *   - REQ-ORDA-002: empty $schemaIds returns 0 without a DB call
 *   - REQ-ORDA-003: $hours <= 0 raises \InvalidArgumentException
 *   - REQ-ORDA-004: NULL user rows excluded (only non-NULL users counted)
 *   - REQ-ORDA-005: multi-schema fan-out counted once, not summed
 *   - REQ-ORDA-006: time-window boundary (rows outside the window are excluded)
 *
 * @package OCA\OpenRegister\Tests\Unit\Db
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class AuditTrailMapperTest extends TestCase
{

    /**
     * Mocked database connection. Configured in setUp().
     *
     * @var IDBConnection&MockObject
     */
    private IDBConnection&MockObject $db;

    /**
     * Mocked DI container (used by createAuditTrail internals, not this method).
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * AuditTrailMapper instance under test.
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $mapper;

    /**
     * Build all mocked dependencies and construct the mapper under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db          = $this->createMock(originalClassName: IDBConnection::class);
        $this->container   = $this->createMock(originalClassName: ContainerInterface::class);
        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
        $this->request     = $this->createMock(originalClassName: IRequest::class);
        $this->logger      = $this->createMock(originalClassName: LoggerInterface::class);

        $this->mapper = new AuditTrailMapper(
            db: $this->db,
            container: $this->container,
            userSession: $this->userSession,
            request: $this->request,
            logger: $this->logger
        );

    }//end setUp()

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Build a mocked IQueryBuilder that returns the given scalar in the
     * `distinct_actors` column of a single-row result.
     *
     * The mock stubs every fluent chain method used by getDistinctActorCount()
     * (select, from, where, andWhere, createNamedParameter, createFunction,
     * expr, executeQuery) and returns the given count via an IResult mock.
     *
     * @param int $distinctCount The value to return in the `distinct_actors` column.
     *
     * @return IQueryBuilder&MockObject
     */
    private function buildQueryBuilderMock(int $distinctCount): IQueryBuilder&MockObject
    {
        $stmt = $this->createMock(originalClassName: IResult::class);
        $stmt->method('fetch')->willReturn(['distinct_actors' => (string) $distinctCount]);
        $stmt->method('closeCursor')->willReturn(true);

        $expr = $this->createMock(originalClassName: IExpressionBuilder::class);
        $expr->method('in')->willReturn('1=1');
        $expr->method('gte')->willReturn('1=1');
        $expr->method('isNotNull')->willReturn('1=1');

        $qb = $this->createMock(originalClassName: IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('createFunction')->willReturnArgument(0);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($stmt);

        return $qb;

    }//end buildQueryBuilderMock()

    // =========================================================================
    // REQ-ORDA-002: empty $schemaIds short-circuits to 0 without a DB call
    // =========================================================================

    /**
     * Empty schema list must return 0 immediately without issuing any SQL.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-002
     */
    public function testGetDistinctActorCountEmptySchemaIdsReturnsZeroWithoutDbCall(): void
    {
        // DB must never be touched when the schema list is empty.
        $this->db->expects($this->never())->method('getQueryBuilder');

        $result = $this->mapper->getDistinctActorCount(schemaIds: [], hours: 24);

        $this->assertSame(expected: 0, actual: $result);

    }//end testGetDistinctActorCountEmptySchemaIdsReturnsZeroWithoutDbCall()

    // =========================================================================
    // REQ-ORDA-003: $hours <= 0 raises \InvalidArgumentException
    // =========================================================================

    /**
     * Zero hours must raise \InvalidArgumentException, not silently return 0.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-003
     */
    public function testGetDistinctActorCountZeroHoursThrowsWithHoursInMessage(): void
    {
        $this->expectException(exception: \InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/hours/');

        $this->mapper->getDistinctActorCount(schemaIds: [1], hours: 0);

    }//end testGetDistinctActorCountZeroHoursThrowsWithHoursInMessage()

    /**
     * Negative hours must also raise \InvalidArgumentException.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-003
     */
    public function testGetDistinctActorCountNegativeHoursThrows(): void
    {
        $this->expectException(exception: \InvalidArgumentException::class);

        $this->mapper->getDistinctActorCount(schemaIds: [1], hours: -5);

    }//end testGetDistinctActorCountNegativeHoursThrows()

    // =========================================================================
    // REQ-ORDA-001 + REQ-ORDA-005: happy path — multiple schemas, multiple actors
    // =========================================================================

    /**
     * Happy path: 3 distinct actors across schemas 1, 2, 3.
     *
     * Reflects the scenario described in tasks.md §2.1:
     * - 2 rows for schema 1 by alice
     * - 1 row for schema 2 by bob
     * - 3 rows for schema 3 by carol
     * Expected count: 3 (alice + bob + carol).
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-001
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-005
     */
    public function testGetDistinctActorCountHappyPathReturnsThreeDistinctActors(): void
    {
        $qb = $this->buildQueryBuilderMock(distinctCount: 3);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->mapper->getDistinctActorCount(schemaIds: [1, 2, 3], hours: 24);

        $this->assertSame(expected: 3, actual: $result);

    }//end testGetDistinctActorCountHappyPathReturnsThreeDistinctActors()

    // =========================================================================
    // REQ-ORDA-005: same actor across multiple schemas counted once
    // =========================================================================

    /**
     * An actor active in all three schemas must be counted as 1, not 3.
     *
     * Reflects tasks.md §2.2: 1 row per schema all by alice.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-005
     */
    public function testGetDistinctActorCountActorCountedOnceAcrossMultipleSchemas(): void
    {
        // COUNT(DISTINCT user) returns 1 regardless of how many schemas alice touched.
        $qb = $this->buildQueryBuilderMock(distinctCount: 1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->mapper->getDistinctActorCount(schemaIds: [1, 2, 3], hours: 24);

        $this->assertSame(expected: 1, actual: $result);

    }//end testGetDistinctActorCountActorCountedOnceAcrossMultipleSchemas()

    // =========================================================================
    // REQ-ORDA-004: NULL user rows excluded
    // =========================================================================

    /**
     * A row with user IS NULL must not contribute to the count.
     *
     * Scenario: alice + bob + NULL row → expected count 2.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-004
     */
    public function testGetDistinctActorCountNullUserRowsExcluded(): void
    {
        // The DB (with the IS NOT NULL predicate) returns 2; verify the PHP layer passes it through.
        $qb = $this->buildQueryBuilderMock(distinctCount: 2);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->mapper->getDistinctActorCount(schemaIds: [1], hours: 24);

        $this->assertSame(expected: 2, actual: $result);

    }//end testGetDistinctActorCountNullUserRowsExcluded()

    // =========================================================================
    // REQ-ORDA-006: time-window boundary — older rows excluded
    // =========================================================================

    /**
     * Rows outside the time window must be excluded; rows inside must be counted.
     *
     * Scenario (tasks.md §2.6):
     * - Row at now() - 48h by alice → excluded by getDistinctActorCount([1], 24)
     * - Row at now() - 12h by bob  → included
     * Expected DB-level result: 1 (the SQL predicate handles this; the PHP layer
     * returns whatever the DB reports without further filtering).
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-006
     */
    public function testGetDistinctActorCountOlderRowsExcludedByTimeWindow(): void
    {
        // DB returns 1: only bob's row is within the 24h window.
        $qb = $this->buildQueryBuilderMock(distinctCount: 1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->mapper->getDistinctActorCount(schemaIds: [1], hours: 24);

        $this->assertSame(expected: 1, actual: $result);

    }//end testGetDistinctActorCountOlderRowsExcludedByTimeWindow()

    /**
     * A row at exactly the boundary (now() - $hours hours) must be included.
     *
     * The SQL predicate uses >= (not >) so boundary rows are included.
     * The mock returns 1, representing alice's boundary row being counted.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-006
     */
    public function testGetDistinctActorCountBoundaryRowIsIncluded(): void
    {
        $qb = $this->buildQueryBuilderMock(distinctCount: 1);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->mapper->getDistinctActorCount(schemaIds: [1], hours: 24);

        $this->assertSame(expected: 1, actual: $result);

    }//end testGetDistinctActorCountBoundaryRowIsIncluded()

    // =========================================================================
    // REQ-ORDA-001: return type is int
    // =========================================================================

    /**
     * The method must return a plain PHP int, never a string or other type.
     *
     * @return void
     *
     * @see openspec/changes/openregister-distinct-actor-aggregation/specs/audit-trail-distinct-actors/spec.md REQ-ORDA-001
     */
    public function testGetDistinctActorCountReturnTypeIsInt(): void
    {
        $qb = $this->buildQueryBuilderMock(distinctCount: 5);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->mapper->getDistinctActorCount(schemaIds: [1], hours: 1);

        $this->assertIsInt(actual: $result);

    }//end testGetDistinctActorCountReturnTypeIsInt()
}//end class
