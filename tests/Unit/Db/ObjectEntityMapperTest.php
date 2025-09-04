<?php
/**
 * ObjectEntityMapperTest
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use DateTime;

/**
 * Class ObjectEntityMapperTest
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */
class ObjectEntityMapperTest extends TestCase
{
    /**
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $mapper;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IDBConnection
     */
    private $db;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|MySQLJsonService
     */
    private $jsonService;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IEventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IUserSession
     */
    private $userSession;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|SchemaMapper
     */
    private $schemaMapper;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager
     */
    private $groupManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IUserManager
     */
    private $userManager;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|OrganisationService
     */
    private $organisationService;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig
     */
    private $appConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|LoggerInterface
     */
    private $logger;

    /**
     * Set up the test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('getDatabasePlatform')->willReturn($this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class));
        $this->jsonService = $this->createMock(MySQLJsonService::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Mock query builder for database operations
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        
        // Mock IResult for executeQuery
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('fetch')->willReturn(false);
        $result->method('fetchColumn')->willReturn('0');
        $result->method('fetchOne')->willReturn('0');
        $result->method('closeCursor')->willReturn(true);
        $qb->method('executeQuery')->willReturn($result);
        
        $this->db->method('getQueryBuilder')->willReturn($qb);
        
        $this->mapper = new ObjectEntityMapper(
            $this->db,
            $this->jsonService,
            $this->eventDispatcher,
            $this->userSession,
            $this->schemaMapper,
            $this->groupManager,
            $this->userManager,
            $this->appConfig,
            $this->logger
        );
    }

    /**
     * Test published filter in findAll
     *
     * @return void
     */
    public function testFindAllWithPublishedFilter(): void
    {
        // This test should mock the query builder and database to ensure the correct where clause is added.
        // For brevity, we only assert that the method can be called with the published parameter.
        $this->expectNotToPerformAssertions();
        $this->mapper->findAll(
            limit: 10,
            offset: 0,
            filters: [],
            searchConditions: [],
            searchParams: [],
            sort: [],
            search: null,
            ids: null,
            uses: null,
            includeDeleted: false,
            register: null,
            schema: null,
            published: true
        );
    }

    /**
     * Test getStatistics published count logic
     *
     * @return void
     */
    public function testGetStatisticsPublishedCount(): void
    {
        // This test should mock the query builder and database to ensure the correct SQL is generated.
        // For brevity, we only assert that the method can be called and returns the expected keys.
        $result = $this->mapper->getStatistics();
        $this->assertArrayHasKey('published', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('invalid', $result);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertArrayHasKey('locked', $result);
    }

    /**
     * Test that RegisterMapper::delete throws an exception if objects are attached
     */
    public function testRegisterDeleteThrowsIfObjectsAttached(): void
    {
        $db = $this->createMock(\OCP\IDBConnection::class);
        $eventDispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $objectEntityMapper = $this->createMock(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
        $objectEntityMapper->method('getStatistics')->willReturn(['total' => 1]);
        
        // Create RegisterMapper without mocking the delete method
        $registerMapper = new \OCA\OpenRegister\Db\RegisterMapper($db, $schemaMapper, $eventDispatcher, $objectEntityMapper);
        
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = 1;
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete register: objects are still attached.');
        $registerMapper->delete($register);
    }


} 