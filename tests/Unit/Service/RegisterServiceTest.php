<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Register;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for RegisterService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class RegisterServiceTest extends TestCase
{
    private RegisterService $registerService;
    private RegisterMapper $registerMapper;
    private FileService $fileService;
    private OrganisationService $organisationService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create RegisterService instance
        $this->registerService = new RegisterService(
            $this->registerMapper,
            $this->fileService,
            $this->logger,
            $this->organisationService
        );
    }

    /**
     * Test find method
     */
    public function testFind(): void
    {
        $id = 'test-id';
        $extend = ['test'];

        // Create mock register
        $register = $this->createMock(Register::class);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id, $extend)
            ->willReturn($register);

        $result = $this->registerService->find($id, $extend);

        $this->assertEquals($register, $result);
    }

    /**
     * Test findMultiple method
     */
    public function testFindMultiple(): void
    {
        $ids = ['id1', 'id2'];

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register2 = $this->createMock(Register::class);
        $registers = [$register1, $register2];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('findMultiple')
            ->with($ids)
            ->willReturn($registers);

        $result = $this->registerService->findMultiple($ids);

        $this->assertEquals($registers, $result);
    }

    /**
     * Test findAll method
     */
    public function testFindAll(): void
    {
        $limit = 10;
        $offset = 0;
        $filters = ['test' => 'value'];
        $searchConditions = ['search'];
        $searchParams = ['param'];
        $extend = ['extend'];

        // Create mock registers
        $register1 = $this->createMock(Register::class);
        $register2 = $this->createMock(Register::class);
        $registers = [$register1, $register2];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->with($limit, $offset, $filters, $searchConditions, $searchParams, $extend)
            ->willReturn($registers);

        $result = $this->registerService->findAll($limit, $offset, $filters, $searchConditions, $searchParams, $extend);

        $this->assertEquals($registers, $result);
    }

    /**
     * Test createFromArray method with valid data
     */
    public function testCreateFromArrayWithValidData(): void
    {
        $registerData = [
            'title' => 'Test Register',
            'description' => 'Test Description',
            'version' => '1.0.0'
        ];

        // Create mock register
        $register = $this->getMockBuilder(Register::class)
            ->addMethods(['getOrganisation', 'setOrganisation'])
            ->getMock();
        $register->method('getOrganisation')->willReturn(null);
        $register->method('setOrganisation')->willReturn($register);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->with($registerData)
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->with($register)
            ->willReturn($register);

        // Mock organisation service
        $this->organisationService->expects($this->once())
            ->method('getOrganisationForNewEntity')
            ->willReturn('test-org-uuid');

        $result = $this->registerService->createFromArray($registerData);

        $this->assertEquals($register, $result);
    }

    /**
     * Test createFromArray method with no organisation
     */
    public function testCreateFromArrayWithNoOrganisation(): void
    {
        $registerData = [
            'title' => 'Test Register',
            'description' => 'Test Description'
        ];

        // Create mock register
        $register = $this->getMockBuilder(Register::class)
            ->addMethods(['getOrganisation', 'setOrganisation'])
            ->getMock();
        $register->method('getOrganisation')->willReturn(null);
        $register->method('setOrganisation')->willReturn($register);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->with($registerData)
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->with($register)
            ->willReturn($register);

        // Mock organisation service
        $this->organisationService->expects($this->once())
            ->method('getOrganisationForNewEntity')
            ->willReturn('test-org-uuid');

        $result = $this->registerService->createFromArray($registerData);

        $this->assertEquals($register, $result);
    }

    /**
     * Test updateFromArray method
     */
    public function testUpdateFromArray(): void
    {
        $id = 1;
        $registerData = [
            'title' => 'Updated Register',
            'description' => 'Updated Description'
        ];

        // Create mock register
        $register = $this->createMock(Register::class);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('updateFromArray')
            ->with($id, $registerData)
            ->willReturn($register);

        $result = $this->registerService->updateFromArray($id, $registerData);

        $this->assertEquals($register, $result);
    }

    /**
     * Test delete method
     */
    public function testDelete(): void
    {
        // Create mock register
        $register = $this->createMock(Register::class);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('delete')
            ->with($register)
            ->willReturn($register);

        $result = $this->registerService->delete($register);

        $this->assertEquals($register, $result);
    }

    /**
     * Test getSchemasByRegisterId method
     */
    public function testGetSchemasByRegisterId(): void
    {
        $registerId = 1;
        $schemas = ['schema1', 'schema2'];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('getSchemasByRegisterId')
            ->with($registerId)
            ->willReturn($schemas);

        $result = $this->registerService->getSchemasByRegisterId($registerId);

        $this->assertEquals($schemas, $result);
    }

    /**
     * Test getFirstRegisterWithSchema method
     */
    public function testGetFirstRegisterWithSchema(): void
    {
        $schemaId = 1;
        $registerId = 2;

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('getFirstRegisterWithSchema')
            ->with($schemaId)
            ->willReturn($registerId);

        $result = $this->registerService->getFirstRegisterWithSchema($schemaId);

        $this->assertEquals($registerId, $result);
    }

    /**
     * Test hasSchemaWithTitle method
     */
    public function testHasSchemaWithTitle(): void
    {
        $registerId = 1;
        $schemaTitle = 'Test Schema';
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('hasSchemaWithTitle')
            ->with($registerId, $schemaTitle)
            ->willReturn($schema);

        $result = $this->registerService->hasSchemaWithTitle($registerId, $schemaTitle);

        $this->assertEquals($schema, $result);
    }

    /**
     * Test getIdToSlugMap method
     */
    public function testGetIdToSlugMap(): void
    {
        $map = ['1' => 'slug1', '2' => 'slug2'];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('getIdToSlugMap')
            ->willReturn($map);

        $result = $this->registerService->getIdToSlugMap();

        $this->assertEquals($map, $result);
    }

    /**
     * Test getSlugToIdMap method
     */
    public function testGetSlugToIdMap(): void
    {
        $map = ['slug1' => '1', 'slug2' => '2'];

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('getSlugToIdMap')
            ->willReturn($map);

        $result = $this->registerService->getSlugToIdMap();

        $this->assertEquals($map, $result);
    }
}