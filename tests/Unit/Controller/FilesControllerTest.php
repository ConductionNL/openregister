<?php

declare(strict_types=1);

/**
 * FilesControllerTest
 * 
 * Unit tests for the FilesController
 *
 * @category   Test
 * @package    OCA\OpenRegister\Tests\Unit\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the FilesController
 *
 * This test class covers all functionality of the FilesController
 * including file management operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class FilesControllerTest extends TestCase
{
    /**
     * The FilesController instance being tested
     *
     * @var FilesController
     */
    private FilesController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock object service
     *
     * @var MockObject|ObjectService
     */
    private MockObject $objectService;

    /**
     * Mock file service
     *
     * @var MockObject|FileService
     */
    private MockObject $fileService;

    /**
     * Set up test environment before each test
     *
     * This method initializes all mocks and the controller instance
     * for testing purposes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->fileService = $this->createMock(FileService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new FilesController(
            'openregister',
            $this->request,
            $this->objectService,
            $this->fileService
        );
    }

    /**
     * Test page method returns TemplateResponse
     *
     * @return void
     */
    public function testPageReturnsTemplateResponse(): void
    {
        $response = $this->controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    /**
     * Test index method with successful file listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';
        $files = ['file1.txt', 'file2.txt'];
        $formattedFiles = ['formatted' => 'files'];

        $this->fileService
            ->expects($this->once())
            ->method('getFiles')
            ->with($this->equalTo($id))
            ->willReturn($files);

        $this->fileService
            ->expects($this->once())
            ->method('formatFiles')
            ->with($files, [])
            ->willReturn($formattedFiles);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $response = $this->controller->index($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($formattedFiles, $response->getData());
    }

    /**
     * Test index method when object not found
     *
     * @return void
     */
    public function testIndexObjectNotFound(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';

        $this->fileService
            ->expects($this->once())
            ->method('getFiles')
            ->with($this->equalTo($id))
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $response = $this->controller->index($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Object not found', $response->getData()['error']);
    }

    /**
     * Test index method when files folder not found
     *
     * @return void
     */
    public function testIndexFilesFolderNotFound(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';

        $this->fileService
            ->expects($this->once())
            ->method('getFiles')
            ->with($this->equalTo($id))
            ->willThrowException(new \OCP\Files\NotFoundException('Files folder not found'));

        $response = $this->controller->index($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Files folder not found', $response->getData()['error']);
    }

    /**
     * Test index method with general exception
     *
     * @return void
     */
    public function testIndexWithGeneralException(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';

        $this->fileService
            ->expects($this->once())
            ->method('getFiles')
            ->with($this->equalTo($id))
            ->willThrowException(new \Exception('General error'));

        $response = $this->controller->index($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('General error', $response->getData()['error']);
    }

}