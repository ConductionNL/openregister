<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
<<<<<<< HEAD
use Psr\Log\LoggerInterface;
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

/**
 * Unit tests for SchemaMapper
 *
 * Tests construction with mocked dependencies. SchemaMapper methods are
 * almost entirely DB-bound (find, findAll, insert, update, delete, etc.)
 * and require integration tests for meaningful coverage.
 * Here we verify construction wiring and the table name.
 */
class SchemaMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private IEventDispatcher&MockObject $eventDispatcher;
    private PropertyValidatorHandler&MockObject $validator;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IAppConfig&MockObject $appConfig;
<<<<<<< HEAD
    private LoggerInterface&MockObject $logger;
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    private SchemaMapper $mapper;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->validator = $this->createMock(PropertyValidatorHandler::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
<<<<<<< HEAD
        $this->logger = $this->createMock(LoggerInterface::class);
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $this->mapper = new SchemaMapper(
            $this->db,
            $this->eventDispatcher,
            $this->validator,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
<<<<<<< HEAD
            $this->appConfig,
            $this->logger
=======
            $this->appConfig
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        );
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(SchemaMapper::class, $this->mapper);
    }

    public function testGetTableNameReturnsCorrectValue(): void
    {
        $this->assertStringContainsString('openregister_schemas', $this->mapper->getTableName());
    }
}
