<?php

declare(strict_types=1);

/**
 * TagsControllerTest
 * 
 * Unit tests for the TagsController
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

use OCA\OpenRegister\Controller\TagsController;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the TagsController
 *
 * This test class covers all functionality of the TagsController
 * including tag management operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class TagsControllerTest extends TestCase
{
    /**
     * The TagsController instance being tested
     *
     * @var TagsController
     */
    private TagsController $controller;

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
        $this->controller = new TagsController(
            'openregister',
            $this->request,
            $this->objectService,
            $this->fileService
        );
    }

    /**
     * Test getAllTags method with successful tags listing
     *
     * @return void
     */
    public function testGetAllTagsSuccessful(): void
    {
        $tags = [
            ['id' => 1, 'name' => 'Tag 1', 'color' => '#ff0000'],
            ['id' => 2, 'name' => 'Tag 2', 'color' => '#00ff00']
        ];

        $this->fileService
            ->expects($this->once())
            ->method('getAllTags')
            ->willReturn($tags);

        $response = $this->controller->getAllTags();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($tags, $response->getData());
    }

}