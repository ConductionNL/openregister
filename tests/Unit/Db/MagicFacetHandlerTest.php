<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for MagicFacetHandler::buildDateKeyExpr().
 *
 * Verifies that the platform-branched SQL expression returned for
 * date_histogram bucket keys produces the correct dialect-specific SQL
 * for each supported interval on both PostgreSQL and MariaDB/MySQL.
 *
 * This locks in the fix for the bug where TO_CHAR() was used unconditionally,
 * breaking date_histogram facets on MariaDB installations.
 */
class MagicFacetHandlerTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private LoggerInterface&MockObject $logger;

    private MagicFacetHandler $handler;

    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }//end setUp()

    private function buildHandler(AbstractPlatform $platform): MagicFacetHandler
    {
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        return new MagicFacetHandler(
            db: $this->db,
            logger: $this->logger
        );
    }//end buildHandler()

    private function invokeBuildDateKeyExpr(MagicFacetHandler $handler, string $field, string $interval): string
    {
        $method = new ReflectionMethod(MagicFacetHandler::class, 'buildDateKeyExpr');
        $method->setAccessible(true);
        return $method->invoke($handler, $field, $interval);
    }//end invokeBuildDateKeyExpr()

    // -------------------------------------------------------------------------
    // PostgreSQL: unchanged TO_CHAR behaviour
    // -------------------------------------------------------------------------
    public function testPostgresYearUsesToCharWithYYYY(): void
    {
        $handler = $this->buildHandler($this->createMock(PostgreSQLPlatform::class));
        $this->assertSame(
            "TO_CHAR(publicatiedatum, 'YYYY')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'year')
        );
    }//end testPostgresYearUsesToCharWithYYYY()

    public function testPostgresMonthUsesToCharWithYYYYMM(): void
    {
        $handler = $this->buildHandler($this->createMock(PostgreSQLPlatform::class));
        $this->assertSame(
            "TO_CHAR(publicatiedatum, 'YYYY-MM')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'month')
        );
    }//end testPostgresMonthUsesToCharWithYYYYMM()

    public function testPostgresWeekUsesIsoToChar(): void
    {
        $handler = $this->buildHandler($this->createMock(PostgreSQLPlatform::class));
        $this->assertSame(
            "TO_CHAR(publicatiedatum, 'IYYY-IW')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'week')
        );
    }//end testPostgresWeekUsesIsoToChar()

    public function testPostgresQuarterUsesToChar(): void
    {
        $handler = $this->buildHandler($this->createMock(PostgreSQLPlatform::class));
        $this->assertSame(
            "TO_CHAR(publicatiedatum, 'YYYY-\"Q\"Q')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'quarter')
        );
    }//end testPostgresQuarterUsesToChar()

    public function testPostgresDayUsesToCharWithYYYYMMDD(): void
    {
        $handler = $this->buildHandler($this->createMock(PostgreSQLPlatform::class));
        $this->assertSame(
            "TO_CHAR(publicatiedatum, 'YYYY-MM-DD')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'day')
        );
    }//end testPostgresDayUsesToCharWithYYYYMMDD()

    // -------------------------------------------------------------------------
    // MariaDB / MySQL: the bug fix
    // -------------------------------------------------------------------------
    public function testMariadbYearUsesDateFormatWithPercentY(): void
    {
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "DATE_FORMAT(publicatiedatum, '%Y')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'year')
        );
    }//end testMariadbYearUsesDateFormatWithPercentY()

    public function testMariadbMonthUsesDateFormat(): void
    {
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "DATE_FORMAT(publicatiedatum, '%Y-%m')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'month')
        );
    }//end testMariadbMonthUsesDateFormat()

    public function testMariadbDayUsesDateFormat(): void
    {
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "DATE_FORMAT(publicatiedatum, '%Y-%m-%d')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'day')
        );
    }//end testMariadbDayUsesDateFormat()

    public function testMariadbWeekUsesIsoDateFormat(): void
    {
        // ISO year + ISO week — matches PostgreSQL IYYY-IW so bucket keys
        // are stable across databases around year boundaries.
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "DATE_FORMAT(publicatiedatum, '%x-%v')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'week')
        );
    }//end testMariadbWeekUsesIsoDateFormat()

    public function testMariadbQuarterUsesConcat(): void
    {
        // DATE_FORMAT has no quarter specifier; we build the key manually.
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "CONCAT(YEAR(publicatiedatum), '-Q', QUARTER(publicatiedatum))",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'quarter')
        );
    }//end testMariadbQuarterUsesConcat()

    public function testMariadbUnknownIntervalFallsBackToMonth(): void
    {
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "DATE_FORMAT(publicatiedatum, '%Y-%m')",
            $this->invokeBuildDateKeyExpr($handler, 'publicatiedatum', 'hour')
        );
    }//end testMariadbUnknownIntervalFallsBackToMonth()

    // -------------------------------------------------------------------------
    // Qualified field names (UNION sub-queries use table alias `t.`)
    // -------------------------------------------------------------------------
    public function testQualifiedFieldPassesThroughOnPostgres(): void
    {
        $handler = $this->buildHandler($this->createMock(PostgreSQLPlatform::class));
        $this->assertSame(
            "TO_CHAR(t.publicatiedatum, 'YYYY-MM')",
            $this->invokeBuildDateKeyExpr($handler, 't.publicatiedatum', 'month')
        );
    }//end testQualifiedFieldPassesThroughOnPostgres()

    public function testQualifiedFieldPassesThroughOnMariadb(): void
    {
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "DATE_FORMAT(t.publicatiedatum, '%Y-%m')",
            $this->invokeBuildDateKeyExpr($handler, 't.publicatiedatum', 'month')
        );
    }//end testQualifiedFieldPassesThroughOnMariadb()

    public function testQualifiedFieldInQuarterConcatOnMariadb(): void
    {
        $handler = $this->buildHandler($this->createMock(MariaDBPlatform::class));
        $this->assertSame(
            "CONCAT(YEAR(t.publicatiedatum), '-Q', QUARTER(t.publicatiedatum))",
            $this->invokeBuildDateKeyExpr($handler, 't.publicatiedatum', 'quarter')
        );
    }//end testQualifiedFieldInQuarterConcatOnMariadb()
}//end class
