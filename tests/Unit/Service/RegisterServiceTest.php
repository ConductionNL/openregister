<?php
/**
 * RegisterService Unit Test
 *
 * This file contains unit tests for the RegisterService class.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class RegisterServiceTest
 *
 * Unit tests for RegisterService covering:
 * - find() with various ID types and extensions
 * - findAll() with filtering and multitenancy
 * - createFromArray() with organisation assignment
 * - updateFromArray() for existing and non-existent registers
 * - delete() operations
 * - getSchemaObjectCounts() with various schema configurations
 *
 * @package OCA\OpenRegister\Tests\Unit\Service
 */
class RegisterServiceTest extends TestCase
{

    /**
     * Mock register mapper.
     *
     * @var RegisterMapper|MockObject
     */
    private $registerMapper;

    /**
     * Mock schema mapper.
     *
     * @var SchemaMapper|MockObject
     */
    private $schemaMapper;

    /**
     * Mock database connection.
     *
     * @var IDBConnection|MockObject
     */
    private $db;

    /**
     * Mock file service.
     *
     * @var FileService|MockObject
     */
    private $fileService;

    /**
     * Mock organisation service.
     *
     * @var OrganisationService|MockObject
     */
    private $organisationService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * Mock register serializer.
     *
     * @var RegisterSerializer|MockObject
     */
    private $registerSerializer;

    /**
     * The service under test.
     *
     * @var RegisterService
     */
    private RegisterService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->db                  = $this->createMock(IDBConnection::class);
        $this->fileService         = $this->createMock(FileService::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->registerSerializer  = $this->createMock(RegisterSerializer::class);

        $this->service = new RegisterService(
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            db: $this->db,
            fileService: $this->fileService,
            organisationService: $this->organisationService,
            logger: $this->logger,
            registerSerializer: $this->registerSerializer
        );
    }

    /**
     * Helper to create a Register entity with an ID set.
     *
     * @param int         $id           The register ID.
     * @param string|null $title        Optional title.
     * @param string|null $organisation Optional organisation UUID.
     * @param string|null $folder       Optional folder value.
     *
     * @return Register The configured register entity.
     */
    private function createRegister(
        int $id,
        ?string $title = null,
        ?string $organisation = null,
        ?string $folder = null
    ): Register {
        $register = new Register();
        $register->setId($id);
        if ($title !== null) {
            $register->setTitle($title);
        }
        if ($organisation !== null) {
            $register->setOrganisation($organisation);
        }
        if ($folder !== null) {
            $register->setFolder($folder);
        }
        return $register;
    }

    // =========================================================================
    // find() tests
    // =========================================================================

    /**
     * Test find() with an integer ID returns the register.
     *
     * @return void
     */
    public function testFindByIntegerId(): void
    {
        $register = $this->createRegister(id: 1, title: 'Test Register');

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result = $this->service->find(id: 1);

        $this->assertSame($register, $result);
        $this->assertEquals(1, $result->getId());
        $this->assertEquals('Test Register', $result->getTitle());
    }

    /**
     * Test find() with a string ID returns the register.
     *
     * @return void
     */
    public function testFindByStringId(): void
    {
        $register = $this->createRegister(id: 42, title: 'String ID Register');

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result = $this->service->find(id: '42');

        $this->assertSame($register, $result);
    }

    /**
     * Test find() with extensions passes them through to the mapper.
     *
     * @return void
     */
    public function testFindWithExtensions(): void
    {
        $register   = $this->createRegister(id: 1, title: 'Extended Register');
        $extensions = ['schemas', 'objects'];

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result = $this->service->find(id: 1, _extend: $extensions);

        $this->assertSame($register, $result);
    }

    /**
     * Test find() throws DoesNotExistException for non-existent register.
     *
     * @return void
     */
    public function testFindNonExistentThrowsException(): void
    {
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException(msg: 'Register not found'));

        $this->expectException(DoesNotExistException::class);

        $this->service->find(id: 999);
    }

    /**
     * Test find() with multitenancy disabled passes the flag through.
     *
     * @return void
     */
    public function testFindWithMultitenancyDisabled(): void
    {
        $register = $this->createRegister(id: 1, title: 'No MT Register');

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result = $this->service->find(id: 1, _multitenancy: false);

        $this->assertSame($register, $result);
    }

    // =========================================================================
    // findAll() tests
    // =========================================================================

    /**
     * Test findAll() with default parameters returns all registers.
     *
     * @return void
     */
    public function testFindAllDefault(): void
    {
        $registers = [
            $this->createRegister(id: 1, title: 'Register A'),
            $this->createRegister(id: 2, title: 'Register B'),
        ];

        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($registers);

        $result = $this->service->findAll();

        $this->assertCount(2, $result);
        $this->assertSame($registers, $result);
    }

    /**
     * Test findAll() with search and filter parameters.
     *
     * @return void
     */
    public function testFindAllWithFilters(): void
    {
        $registers = [
            $this->createRegister(id: 1, title: 'Filtered Register'),
        ];

        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($registers);

        $result = $this->service->findAll(
            limit: 10,
            offset: 0,
            filters: ['organisation' => 'org-uuid']
        );

        $this->assertCount(1, $result);
    }

    /**
     * Test findAll() with multitenancy disabled.
     *
     * @return void
     */
    public function testFindAllWithMultitenancyDisabled(): void
    {
        $registers = [
            $this->createRegister(id: 1, title: 'Register 1'),
            $this->createRegister(id: 2, title: 'Register 2'),
            $this->createRegister(id: 3, title: 'Register 3'),
        ];

        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($registers);

        $result = $this->service->findAll(_multitenancy: false);

        $this->assertCount(3, $result);
    }

    /**
     * Test findAll() returns empty array when no registers match.
     *
     * @return void
     */
    public function testFindAllReturnsEmptyArray(): void
    {
        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->findAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // createFromArray() tests
    // =========================================================================

    /**
     * Test createFromArray() with minimal data creates a register and sets organisation.
     *
     * @return void
     */
    public function testCreateFromArrayMinimalData(): void
    {
        $data     = ['title' => 'New Register'];
        $register = $this->createRegister(id: 1, title: 'New Register');
        // Organisation is null by default, so it should be set from OrganisationService.

        $registerWithOrg = $this->createRegister(
            id: 1,
            title: 'New Register',
            organisation: 'org-uuid-123'
        );

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->equalTo($data))
            ->willReturn($register);

        $this->organisationService->expects($this->once())
            ->method('getOrganisationForNewEntity')
            ->willReturn('org-uuid-123');

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturn($registerWithOrg);

        $this->fileService->expects($this->once())
            ->method('createEntityFolder')
            ->willReturn(null);

        $result = $this->service->createFromArray(data: $data);

        $this->assertEquals(1, $result->getId());
    }

    /**
     * Test createFromArray() with all fields including organisation already set.
     *
     * @return void
     */
    public function testCreateFromArrayWithAllFields(): void
    {
        $data = [
            'title'        => 'Full Register',
            'description'  => 'A fully populated register',
            'organisation' => 'pre-set-org-uuid',
            'version'      => '1.0.0',
        ];

        $register = $this->createRegister(
            id: 5,
            title: 'Full Register',
            organisation: 'pre-set-org-uuid'
        );

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->equalTo($data))
            ->willReturn($register);

        // Organisation is already set, so OrganisationService should NOT be called.
        $this->organisationService->expects($this->never())
            ->method('getOrganisationForNewEntity');

        // update() should NOT be called for organisation assignment.
        $this->registerMapper->expects($this->never())
            ->method('update');

        $this->fileService->expects($this->once())
            ->method('createEntityFolder')
            ->willReturn(null);

        $result = $this->service->createFromArray(data: $data);

        $this->assertEquals(5, $result->getId());
        $this->assertEquals('pre-set-org-uuid', $result->getOrganisation());
    }

    /**
     * Test createFromArray() sets organisation from OrganisationService when empty.
     *
     * @return void
     */
    public function testCreateFromArraySetsOrganisation(): void
    {
        $data     = ['title' => 'Org Register'];
        $register = $this->createRegister(id: 10, title: 'Org Register');
        // Organisation is null.

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($register);

        $this->organisationService->expects($this->once())
            ->method('getOrganisationForNewEntity')
            ->willReturn('auto-org-uuid');

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($reg) {
                return $reg->getOrganisation() === 'auto-org-uuid';
            }))
            ->willReturnArgument(0);

        $this->fileService->expects($this->once())
            ->method('createEntityFolder')
            ->willReturn(null);

        $result = $this->service->createFromArray(data: $data);

        $this->assertEquals('auto-org-uuid', $result->getOrganisation());
    }

    // =========================================================================
    // updateFromArray() tests
    // =========================================================================

    /**
     * Test updateFromArray() updates an existing register.
     *
     * @return void
     */
    public function testUpdateFromArrayExistingRegister(): void
    {
        $data     = ['title' => 'Updated Register'];
        $register = $this->createRegister(
            id: 1,
            title: 'Updated Register',
            organisation: 'org-uuid',
            folder: '12345'
        );

        $this->registerMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->equalTo($data)
            )
            ->willReturn($register);

        // Folder is already a numeric string (valid folder ID), but ensureRegisterFolderExists
        // still runs because folder is a string (the private method checks is_string).
        $this->fileService->expects($this->once())
            ->method('createEntityFolder')
            ->willReturn(null);

        $result = $this->service->updateFromArray(id: 1, data: $data);

        $this->assertSame($register, $result);
        $this->assertEquals('Updated Register', $result->getTitle());
    }

    /**
     * Test updateFromArray() throws exception for non-existent register.
     *
     * @return void
     */
    public function testUpdateFromArrayNonExistentThrowsException(): void
    {
        $this->registerMapper->expects($this->once())
            ->method('updateFromArray')
            ->willThrowException(new DoesNotExistException(msg: 'Register not found'));

        $this->expectException(DoesNotExistException::class);

        $this->service->updateFromArray(id: 999, data: ['title' => 'Ghost']);
    }

    // =========================================================================
    // delete() tests
    // =========================================================================

    /**
     * Test delete() removes an existing register.
     *
     * @return void
     */
    public function testDeleteExistingRegister(): void
    {
        $register = $this->createRegister(id: 1, title: 'Doomed Register');

        $this->registerMapper->expects($this->once())
            ->method('delete')
            ->with($this->equalTo($register))
            ->willReturn($register);

        $result = $this->service->delete(register: $register);

        $this->assertSame($register, $result);
    }

    /**
     * Test delete() returns the deleted register entity.
     *
     * @return void
     */
    public function testDeleteReturnsDeletedEntity(): void
    {
        $register = $this->createRegister(id: 7, title: 'Register to Delete');

        $this->registerMapper->expects($this->once())
            ->method('delete')
            ->willReturn($register);

        $result = $this->service->delete(register: $register);

        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals(7, $result->getId());
        $this->assertEquals('Register to Delete', $result->getTitle());
    }

    // =========================================================================
    // getSchemaObjectCounts() tests
    // =========================================================================

    /**
     * Test getSchemaObjectCounts() returns empty array for empty schemas.
     *
     * @return void
     */
    public function testGetSchemaObjectCountsEmptySchemas(): void
    {
        $result = $this->service->getSchemaObjectCounts(registerId: 1, schemas: []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getSchemaObjectCounts() returns counts per schema for blob storage schemas.
     *
     * @return void
     */
    public function testGetSchemaObjectCountsBlobSchemas(): void
    {
        $schemas = [
            ['id' => 10, 'properties' => []],
            ['id' => 20, 'properties' => []],
        ];

        // Mock query builder for table name resolution.
        $queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $queryBuilder->method('getTableName')
            ->willReturnCallback(function ($name) {
                return '*PREFIX*' . $name;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        // Both tables exist.
        $this->db->method('tableExists')
            ->willReturn(true);

        // Mock the prepared statement and its results.
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->expects($this->once())
            ->method('execute');
        $stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                [
                    'schema_id' => '10',
                    'total'     => '5',
                    'deleted'   => '1',
                    'invalid'   => '0',
                    'locked'    => '0',
                    'size'      => '1024',
                ],
                false
            );
        $stmt->expects($this->once())
            ->method('closeCursor');

        $this->db->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $result = $this->service->getSchemaObjectCounts(registerId: 1, schemas: $schemas);

        $this->assertArrayHasKey(10, $result);
        $this->assertEquals(5, $result[10]['total']);
        $this->assertEquals(1, $result[10]['deleted']);
        $this->assertEquals(1024, $result[10]['size']);
        // Schema 20 was in the UNION query but had no rows returned from mock.
    }

    /**
     * Test getSchemaObjectCounts() handles schemas without ID gracefully.
     *
     * @return void
     */
    public function testGetSchemaObjectCountsSkipsSchemasWithoutId(): void
    {
        $schemas = [
            ['properties' => []],
            ['id' => 10, 'properties' => []],
        ];

        $queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $queryBuilder->method('getTableName')
            ->willReturnCallback(function ($name) {
                return '*PREFIX*' . $name;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        // Table exists for schema 10.
        $this->db->method('tableExists')
            ->willReturn(true);

        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('execute');
        $stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                [
                    'schema_id' => '10',
                    'total'     => '3',
                    'deleted'   => '0',
                    'invalid'   => '0',
                    'locked'    => '0',
                    'size'      => '0',
                ],
                false
            );
        $stmt->method('closeCursor');

        $this->db->method('prepare')
            ->willReturn($stmt);

        $result = $this->service->getSchemaObjectCounts(registerId: 1, schemas: $schemas);

        $this->assertArrayHasKey(10, $result);
        $this->assertEquals(3, $result[10]['total']);
    }

    /**
     * Test getSchemaObjectCounts() handles magic table schemas when table does not exist.
     *
     * @return void
     */
    public function testGetSchemaObjectCountsMagicTableDoesNotExist(): void
    {
        $schemas = [
            [
                'id'         => 30,
                'properties' => [
                    'name' => ['table' => ['column' => 'name']],
                ],
            ],
        ];

        // Table does not exist.
        $this->db->expects($this->once())
            ->method('tableExists')
            ->with('openregister_table_1_30')
            ->willReturn(false);

        $result = $this->service->getSchemaObjectCounts(registerId: 1, schemas: $schemas);

        $this->assertArrayHasKey(30, $result);
        $this->assertEquals(0, $result[30]['total']);
        $this->assertEquals(0, $result[30]['deleted']);
    }

    /**
     * Test getSchemaObjectCounts() handles database exception gracefully.
     *
     * @return void
     */
    public function testGetSchemaObjectCountsHandlesDbException(): void
    {
        $schemas = [
            ['id' => 10, 'properties' => []],
        ];

        $queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $queryBuilder->method('getTableName')
            ->willReturnCallback(function ($name) {
                return '*PREFIX*' . $name;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        $this->db->method('prepare')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->getSchemaObjectCounts(registerId: 1, schemas: $schemas);

        // Should return empty array on exception (error is logged, not thrown).
        $this->assertIsArray($result);
    }
}
