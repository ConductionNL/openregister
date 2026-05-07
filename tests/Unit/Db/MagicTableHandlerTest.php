<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MagicTableHandler column-verification logic.
 *
 * Regression coverage for two bugs:
 *
 * 1. array_flip() was called on the result of getExistingTableColumns(), which
 *    returns array<string, array> (column-name keyed map). array_flip() silently
 *    dropped all entries because values are arrays, not scalars. This caused
 *    array_diff_key to report every column as missing and trigger a full
 *    syncTableForRegisterSchema on every single write request.
 *
 * 2. The $tableColumnsVerifiedCache fast path must short-circuit the
 *    information_schema query on repeated calls within the same process.
 */
class MagicTableHandlerTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private IAppConfig&MockObject $appConfig;

    private LoggerInterface&MockObject $logger;

    private MagicMapper&MockObject $magicMapper;

    private MagicTableHandler $handler;

    private Register $register;

    private Schema $schema;

    private const CACHE_KEY = '11_91';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db          = $this->createMock(IDBConnection::class);
        $this->appConfig   = $this->createMock(IAppConfig::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->magicMapper = $this->createMock(MagicMapper::class);

        $this->handler = new MagicTableHandler(
            db: $this->db,
            appConfig: $this->appConfig,
            logger: $this->logger,
            magicMapper: $this->magicMapper
        );

        $this->register = new Register();
        $this->register->setId(11);

        $this->schema = new Schema();
        $this->schema->setId(91);

        $this->magicMapper->method('getCacheKey')->willReturn(self::CACHE_KEY);

        // Table existence: report table as present via DB check so the static
        // tableExistsCache (cleared below) is populated during the first call.
        $this->magicMapper->method('checkTableExistsInDatabase')->willReturn(true);

        // Reset all static caches including $tableColumnsVerifiedCache.
        MagicMapper::clearAllStaticCaches();
    }//end setUp()

    /**
     * Regression: array_diff_key must not report columns as missing when the
     * table is up to date. Before the fix, array_flip() on the column map
     * silently dropped all entries, making this check always return non-empty
     * and triggering a full sync on every write.
     */
    public function testNoSyncWhenTableExistsAndColumnsMatch(): void
    {
        $columnMap = [
            '_uuid'    => ['name' => '_uuid', 'type' => 'string', 'nullable' => false, 'default' => null],
            '_created' => ['name' => '_created', 'type' => 'datetime', 'nullable' => true, 'default' => null],
            'title'    => ['name' => 'title', 'type' => 'string', 'nullable' => true, 'default' => null],
        ];

        $this->magicMapper->method('hasRegisterSchemaChanged')->willReturn(false);
        $this->magicMapper->method('buildTableColumnsFromSchema')->willReturn($columnMap);
        $this->magicMapper->method('getExistingTableColumns')->willReturn($columnMap);

        // syncTableForRegisterSchema must NOT be called — table is up to date.
        $this->magicMapper->expects($this->never())->method('syncTableForRegisterSchema');

        $result = $this->handler->ensureTableForRegisterSchema(
            register: $this->register,
            schema: $this->schema
        );

        $this->assertTrue($result);
        // Verified flag must be set after a passing sanity check.
        $this->assertTrue(MagicMapper::isTableColumnsVerified(cacheKey: self::CACHE_KEY));
    }//end testNoSyncWhenTableExistsAndColumnsMatch()

    /**
     * Regression: when a column IS missing, syncTableForRegisterSchema must be
     * called exactly once (array_diff_key must correctly identify the gap).
     * Uses a partial mock of the handler so syncTableForRegisterSchema is
     * intercepted without hitting the database.
     */
    public function testSyncCalledWhenColumnMissing(): void
    {
        $required = [
            '_uuid' => ['name' => '_uuid', 'type' => 'string', 'nullable' => false, 'default' => null],
            '_tmlo' => ['name' => '_tmlo', 'type' => 'json', 'nullable' => true, 'default' => null],
        ];
        $existing = [
            '_uuid' => ['name' => '_uuid', 'type' => 'string', 'nullable' => false, 'default' => null],
            // _tmlo is missing.
        ];

        $this->magicMapper->method('hasRegisterSchemaChanged')->willReturn(false);
        $this->magicMapper->method('buildTableColumnsFromSchema')->willReturn($required);
        $this->magicMapper->method('getExistingTableColumns')->willReturn($existing);

        // Partial mock: intercept syncTableForRegisterSchema on the handler itself.
        $handler = $this->getMockBuilder(MagicTableHandler::class)
            ->setConstructorArgs([
                'db'          => $this->db,
                'appConfig'   => $this->appConfig,
                'logger'      => $this->logger,
                'magicMapper' => $this->magicMapper,
            ])
            ->onlyMethods(['syncTableForRegisterSchema'])
            ->getMock();

        $handler->expects($this->once())
            ->method('syncTableForRegisterSchema')
            ->willReturn(['success' => true]);

        $result = $handler->ensureTableForRegisterSchema(
            register: $this->register,
            schema: $this->schema
        );

        $this->assertTrue($result);
    }//end testSyncCalledWhenColumnMissing()

    /**
     * Fast path: when the verified flag is set, neither buildTableColumnsFromSchema
     * nor getExistingTableColumns should be called — zero information_schema I/O.
     */
    public function testFastPathSkipsInfoSchemaQueryWhenVerified(): void
    {
        // Pre-set the verified flag as if a previous request already verified.
        MagicMapper::setTableColumnsVerified(cacheKey: self::CACHE_KEY);

        $this->magicMapper->method('hasRegisterSchemaChanged')->willReturn(false);

        $this->magicMapper->expects($this->never())->method('buildTableColumnsFromSchema');
        $this->magicMapper->expects($this->never())->method('getExistingTableColumns');
        $this->magicMapper->expects($this->never())->method('syncTableForRegisterSchema');

        $result = $this->handler->ensureTableForRegisterSchema(
            register: $this->register,
            schema: $this->schema
        );

        $this->assertTrue($result);
    }//end testFastPathSkipsInfoSchemaQueryWhenVerified()

}//end class
