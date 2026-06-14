<?php
/**
 * Regression: UUID-valued filter on a numeric-typed column must compare as text.
 *
 * Reproduces the OpenRegister-core bug surfaced during fleet e2e/Newman runs:
 * filtering a magic-table column whose JSON-Schema type is `integer`/`number`
 * by a UUID string made PostgreSQL abort the whole query with
 * `SQLSTATE[22P02] invalid input syntax for type integer`. The fix casts the
 * numeric column to text whenever the filter value is a non-numeric scalar so
 * the comparison degrades safely instead of crashing; ordinary numeric filters
 * keep the indexed numeric path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

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
 * Locks the 22P02 fix around numeric-column filtering by non-numeric (UUID)
 * values in MagicSearchHandler.
 */
class MagicSearchHandlerNumericUuidFilterTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private LoggerInterface&MockObject $logger;

    private MagicRbacHandler&MockObject $rbacHandler;

    private MagicOrganizationHandler&MockObject $organizationHandler;

    private MagicSearchHandler $handler;

    private const SUBSCRIPTION_UUID = 'b3c1d2e4-5f6a-7b8c-9d0e-1f2a3b4c5d6e';

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
     * Build a connection mock whose quote() wraps values in single quotes.
     */
    private function makeConnection(): object
    {
        $conn = $this->createMock(IDBConnection::class);
        $conn->method('quote')->willReturnCallback(fn($v) => "'{$v}'");
        return $conn;
    }//end makeConnection()

    /**
     * Invoke the private buildObjectFilterConditionsSql() via reflection.
     *
     * @param array<string,mixed> $query      Search query.
     * @param array<string,mixed> $properties Schema properties (field => ['type' => ...]).
     * @param bool                $isPostgres Platform flag.
     *
     * @return string[] Generated SQL conditions.
     */
    private function invokeObjectFilters(array $query, array $properties, bool $isPostgres=true): array
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn($properties);

        $method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
        $method->setAccessible(true);
        return $method->invoke($this->handler, $query, $schema, $this->makeConnection(), $isPostgres);
    }//end invokeObjectFilters()

    /**
     * Invoke the private buildFilterColumnRef() via reflection.
     *
     * @param string $columnRef    Quoted column reference.
     * @param string $propertyType Declared schema type.
     * @param mixed  $value        Filter value.
     * @param bool   $isPostgres   Platform flag.
     *
     * @return string Possibly text-cast column reference.
     */
    private function invokeColumnRef(string $columnRef, string $propertyType, mixed $value, bool $isPostgres): string
    {
        $method = new ReflectionMethod(MagicSearchHandler::class, 'buildFilterColumnRef');
        $method->setAccessible(true);
        return $method->invoke($this->handler, $columnRef, $propertyType, $value, $isPostgres);
    }//end invokeColumnRef()

    // -------------------------------------------------------------------------
    // The reported bug: UUID filter on an integer column (event_message.subscriptionId)
    // -------------------------------------------------------------------------

    public function testUuidFilterOnIntegerColumnCastsColumnToTextOnPostgres(): void
    {
        // Mirrors GET /events/subscriptions/{uuid}/messages: filter the
        // event_message schema by a UUID-valued subscriptionId whose column was
        // materialised as integer. Before the fix this produced
        // `"subscription_id" = '<uuid>'` which PostgreSQL rejected with 22P02.
        $conditions = $this->invokeObjectFilters(
            query: ['subscriptionId' => self::SUBSCRIPTION_UUID],
            properties: ['subscriptionId' => ['type' => 'integer']],
            isPostgres: true
        );

        $this->assertCount(1, $conditions);
        // The integer column must be cast to text so the UUID compares safely.
        $this->assertStringContainsString('"subscription_id"::text', $conditions[0]);
        $this->assertStringContainsString("= '".self::SUBSCRIPTION_UUID."'", $conditions[0]);
    }//end testUuidFilterOnIntegerColumnCastsColumnToTextOnPostgres()

    public function testUuidFilterOnNumberColumnCastsColumnToTextOnPostgres(): void
    {
        $conditions = $this->invokeObjectFilters(
            query: ['amount' => self::SUBSCRIPTION_UUID],
            properties: ['amount' => ['type' => 'number']],
            isPostgres: true
        );

        $this->assertCount(1, $conditions);
        $this->assertStringContainsString('"amount"::text', $conditions[0]);
    }//end testUuidFilterOnNumberColumnCastsColumnToTextOnPostgres()

    public function testUuidFilterOnIntegerColumnCastsColumnToCharOnMysql(): void
    {
        $conditions = $this->invokeObjectFilters(
            query: ['subscriptionId' => self::SUBSCRIPTION_UUID],
            properties: ['subscriptionId' => ['type' => 'integer']],
            isPostgres: false
        );

        $this->assertCount(1, $conditions);
        $this->assertStringContainsString('CAST(`subscription_id` AS CHAR)', $conditions[0]);
    }//end testUuidFilterOnIntegerColumnCastsColumnToCharOnMysql()

    // -------------------------------------------------------------------------
    // No regression: genuine numeric filters keep the bare numeric comparison
    // -------------------------------------------------------------------------

    public function testNumericFilterOnIntegerColumnIsNotCast(): void
    {
        $conditions = $this->invokeObjectFilters(
            query: ['count' => '42'],
            properties: ['count' => ['type' => 'integer']],
            isPostgres: true
        );

        $this->assertCount(1, $conditions);
        $this->assertStringNotContainsString('::text', $conditions[0]);
        $this->assertStringContainsString('"count" = ', $conditions[0]);
    }//end testNumericFilterOnIntegerColumnIsNotCast()

    public function testStringColumnFilterIsNeverCast(): void
    {
        // A string column filtered by a UUID is already valid; do not touch it.
        $conditions = $this->invokeObjectFilters(
            query: ['name' => self::SUBSCRIPTION_UUID],
            properties: ['name' => ['type' => 'string']],
            isPostgres: true
        );

        $this->assertCount(1, $conditions);
        $this->assertStringNotContainsString('::text', $conditions[0]);
    }//end testStringColumnFilterIsNeverCast()

    public function testUuidInListFilterOnIntegerColumnCastsColumn(): void
    {
        // IN (...) form must cast too — an array containing a UUID.
        $conditions = $this->invokeObjectFilters(
            query: ['subscriptionId' => [self::SUBSCRIPTION_UUID, 'a1b2c3d4-0000-0000-0000-000000000000']],
            properties: ['subscriptionId' => ['type' => 'integer']],
            isPostgres: true
        );

        $this->assertCount(1, $conditions);
        $this->assertStringContainsString('"subscription_id"::text IN (', $conditions[0]);
    }//end testUuidInListFilterOnIntegerColumnCastsColumn()

    public function testNumericInListFilterOnIntegerColumnIsNotCast(): void
    {
        $conditions = $this->invokeObjectFilters(
            query: ['count' => ['1', '2', '3']],
            properties: ['count' => ['type' => 'integer']],
            isPostgres: true
        );

        $this->assertCount(1, $conditions);
        $this->assertStringNotContainsString('::text', $conditions[0]);
        $this->assertStringContainsString('"count" IN (', $conditions[0]);
    }//end testNumericInListFilterOnIntegerColumnIsNotCast()

    // -------------------------------------------------------------------------
    // buildFilterColumnRef() direct contract
    // -------------------------------------------------------------------------

    public function testColumnRefCastsNumericColumnForUuidValue(): void
    {
        $this->assertSame(
            't."amount"::text',
            $this->invokeColumnRef('t."amount"', 'integer', self::SUBSCRIPTION_UUID, true)
        );
    }//end testColumnRefCastsNumericColumnForUuidValue()

    public function testColumnRefLeavesNumericColumnForNumericValue(): void
    {
        $this->assertSame(
            't."amount"',
            $this->invokeColumnRef('t."amount"', 'integer', '42', true)
        );
    }//end testColumnRefLeavesNumericColumnForNumericValue()

    public function testColumnRefLeavesStringColumnUntouched(): void
    {
        $this->assertSame(
            't."name"',
            $this->invokeColumnRef('t."name"', 'string', self::SUBSCRIPTION_UUID, true)
        );
    }//end testColumnRefLeavesStringColumnUntouched()
}//end class
