<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegisterMapper
 *
 * Tests construction with mocked dependencies. RegisterMapper methods are
 * DB-bound (find, findAll, insert, update, delete, getSchemasByRegisterId)
 * and need integration tests for real coverage.
 * Here we verify construction wiring and the table name.
 */
class RegisterMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private SchemaMapper&MockObject $schemaMapper;
    private IEventDispatcher&MockObject $eventDispatcher;
    private UnifiedObjectMapper&MockObject $objectMapper;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IAppConfig&MockObject $appConfig;
    private RegisterMapper $mapper;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->mapper = new RegisterMapper(
            $this->db,
            $this->schemaMapper,
            $this->eventDispatcher,
            $this->objectMapper,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
            $this->appConfig
        );
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(RegisterMapper::class, $this->mapper);
    }

    public function testGetTableNameReturnsCorrectValue(): void
    {
        $this->assertStringContainsString('openregister_registers', $this->mapper->getTableName());
    }
}
