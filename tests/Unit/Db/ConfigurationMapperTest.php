<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigurationMapper
 *
 * Tests construction with mocked dependencies. Most methods in this mapper
 * are DB-heavy (findAll, find, insert, update, delete) and require integration tests.
 * We verify the mapper can be instantiated and its table name is correct.
 */
class ConfigurationMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private ISession&MockObject $session;
    private IEventDispatcher&MockObject $eventDispatcher;
    private ConfigurationMapper $mapper;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->session = $this->createMock(ISession::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->mapper = new ConfigurationMapper(
            $this->db,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
            $this->session,
            $this->eventDispatcher
        );
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(ConfigurationMapper::class, $this->mapper);
    }

    public function testGetTableNameReturnsCorrectValue(): void
    {
        $this->assertStringContainsString('openregister_configurations', $this->mapper->getTableName());
    }
}
