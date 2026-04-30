<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for MagicSearchHandler comparison-operator filter support.
 *
 * Before this fix, array values with keys such as 'gte'/'lte' (produced by
 * PHP's bracket-notation URL parsing or buildSearchQuery's underscore
 * expansion) were silently turned into IN clauses, so
 *
 *   ?publicatiedatum[gte]=2025-12-31T23:59:59Z
 *   &publicatiedatum[lte]=2027-01-01T00:00:00Z
 *
 * generated  `publicatiedatum IN ('2025-12-31…', '2027-01-01…')`  instead of
 *            `publicatiedatum >= '2025-12-31…' AND publicatiedatum <= '2027-01-01…'`
 *
 * These tests cover buildObjectFilterConditionsSql() (the raw-SQL path).
 */
class MagicSearchHandlerTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private LoggerInterface&MockObject $logger;

    private MagicRbacHandler&MockObject $rbacHandler;

    private MagicOrganizationHandler&MockObject $organizationHandler;

    private MagicSearchHandler $handler;

    protected function setUp(): void
    {
        $this->db          = $this->createMock(IDBConnection::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->rbacHandler = $this->createMock(MagicRbacHandler::class);
        $this->organizationHandler = $this->createMock(MagicOrganizationHandler::class);

        $this->handler = new MagicSearchHandler(
            db: $this->db,
            logger: $this->logger,
            rbacHandler: $this->rbacHandler,
            organizationHandler: $this->organizationHandler,
            schemaTypeConverter: new SchemaTypeConverter()
        );
    }//end setUp()

    /**
     * Invoke the private buildObjectFilterConditionsSql() method via reflection.
     *
     * @param array  $query      Filters to apply (field => value).
     * @param array  $properties Schema properties array (field => ['type' => ...]).
     * @param object $connection Mocked DB connection with a quote() method.
     *
     * @return string[] Generated SQL condition strings.
     */
    private function invokeMethod(array $query, array $properties, object $connection): array
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn($properties);

        $method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
        $method->setAccessible(true);

        return $method->invoke($this->handler, $query, $schema, $connection);
    }//end invokeMethod()

    /**
     * Build a connection mock whose quote() method wraps values in single quotes.
     */
    private function makeConnection(): object
    {
        $conn = $this->createMock(IDBConnection::class);
        $conn->method('quote')->willReturnCallback(fn($v) => "'{$v}'");
        return $conn;
    }//end makeConnection()

    // -------------------------------------------------------------------------
    // [gte] / [lte] — the original bug report
    // -------------------------------------------------------------------------
    public function testGteProducesGreaterThanOrEqualCondition(): void
    {
        $conditions = $this->invokeMethod(
            query: ['publicatiedatum' => ['gte' => '2025-12-31T23:59:59Z']],
            properties: ['publicatiedatum' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("publicatiedatum >= '2025-12-31T23:59:59Z'", $conditions[0]);
    }//end testGteProducesGreaterThanOrEqualCondition()

    public function testLteProducesLessThanOrEqualCondition(): void
    {
        $conditions = $this->invokeMethod(
            query: ['publicatiedatum' => ['lte' => '2027-01-01T00:00:00Z']],
            properties: ['publicatiedatum' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("publicatiedatum <= '2027-01-01T00:00:00Z'", $conditions[0]);
    }//end testLteProducesLessThanOrEqualCondition()

    public function testGteAndLteTogetherProduceTwoRangeConditions(): void
    {
        $conditions = $this->invokeMethod(
            query: ['publicatiedatum' => ['gte' => '2025-12-31T23:59:59Z', 'lte' => '2027-01-01T00:00:00Z']],
            properties: ['publicatiedatum' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(2, $conditions);
        $this->assertSame("publicatiedatum >= '2025-12-31T23:59:59Z'", $conditions[0]);
        $this->assertSame("publicatiedatum <= '2027-01-01T00:00:00Z'", $conditions[1]);
    }//end testGteAndLteTogetherProduceTwoRangeConditions()

    // -------------------------------------------------------------------------
    // [gt] / [lt]
    // -------------------------------------------------------------------------
    public function testGtProducesStrictGreaterThanCondition(): void
    {
        $conditions = $this->invokeMethod(
            query: ['bedrag' => ['gt' => '100']],
            properties: ['bedrag' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("bedrag > '100'", $conditions[0]);
    }//end testGtProducesStrictGreaterThanCondition()

    public function testLtProducesStrictLessThanCondition(): void
    {
        $conditions = $this->invokeMethod(
            query: ['bedrag' => ['lt' => '500']],
            properties: ['bedrag' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("bedrag < '500'", $conditions[0]);
    }//end testLtProducesStrictLessThanCondition()

    // -------------------------------------------------------------------------
    // [in] as an operator key
    // -------------------------------------------------------------------------
    public function testInOperatorKeyProducesInClause(): void
    {
        $conditions = $this->invokeMethod(
            query: ['status' => ['in' => ['open', 'pending']]],
            properties: ['status' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("status IN ('open', 'pending')", $conditions[0]);
    }//end testInOperatorKeyProducesInClause()

    public function testInOperatorKeyWithSingleStringValueProducesInClause(): void
    {
        $conditions = $this->invokeMethod(
            query: ['status' => ['in' => 'open']],
            properties: ['status' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("status IN ('open')", $conditions[0]);
    }//end testInOperatorKeyWithSingleStringValueProducesInClause()

    // -------------------------------------------------------------------------
    // Plain array values (backward compatibility — must still produce IN clause)
    // -------------------------------------------------------------------------
    public function testPlainArrayValueStillProducesInClause(): void
    {
        $conditions = $this->invokeMethod(
            query: ['status' => ['open', 'closed']],
            properties: ['status' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("status IN ('open', 'closed')", $conditions[0]);
    }//end testPlainArrayValueStillProducesInClause()

    // -------------------------------------------------------------------------
    // Simple scalar equality (unchanged behaviour)
    // -------------------------------------------------------------------------
    public function testScalarValueProducesEqualityCondition(): void
    {
        $conditions = $this->invokeMethod(
            query: ['status' => 'open'],
            properties: ['status' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame("status = 'open'", $conditions[0]);
    }//end testScalarValueProducesEqualityCondition()

    // -------------------------------------------------------------------------
    // Unknown property must still produce the 1=0 guard condition
    // -------------------------------------------------------------------------
    public function testUnknownPropertyProducesImpossibleCondition(): void
    {
        $conditions = $this->invokeMethod(
            query: ['nonexistent' => 'value'],
            properties: ['status' => ['type' => 'string']],
            connection: $this->makeConnection()
        );

        $this->assertCount(1, $conditions);
        $this->assertSame('1=0', $conditions[0]);
    }//end testUnknownPropertyProducesImpossibleCondition()
}//end class
