<?php

declare(strict_types=1);

/**
 * OpenRegister NamesController Test
 *
 * This file contains tests for the NamesController in the OpenRegister application.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\NamesController;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for NamesController
 *
 * This class tests the ultra-fast object name lookup operations.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class NamesControllerTest extends TestCase
{

    /** @var NamesController */
    private NamesController $controller;

    /** @var MockObject|IRequest */
    private $request;

    /** @var MockObject|ObjectCacheService */
    private $objectCacheService;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->objectCacheService = $this->createMock(ObjectCacheService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new NamesController(
            'openregister',
            $this->request,
            $this->objectCacheService,
            $this->logger
        );
    }

    /**
     * Test constructor
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(NamesController::class, $this->controller);
    }

    /**
     * Test index method with no parameters
     *
     * @return void
     */
    public function testIndexWithNoParameters(): void
    {
        $this->request->method('getParam')->with('ids')->willReturn(null);
        $this->objectCacheService->method('getAllObjectNames')->willReturn([]);
        $this->objectCacheService->method('getStats')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('names', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('cached', $data);
        $this->assertArrayHasKey('execution_time', $data);
        $this->assertArrayHasKey('cache_stats', $data);
    }

    /**
     * Test index method with specific IDs
     *
     * @return void
     */
    public function testIndexWithSpecificIds(): void
    {
        $ids = ['id1', 'id2', 'id3'];
        $this->request->method('getParam')->with('ids')->willReturn(implode(',', $ids));
        
        $expectedNames = [
            'id1' => 'Object 1',
            'id2' => 'Object 2',
            'id3' => 'Object 3'
        ];
        
        $this->objectCacheService->method('getMultipleObjectNames')->with($ids)->willReturn($expectedNames);
        $this->objectCacheService->method('getStats')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('names', $data);
        $this->assertEquals($expectedNames, $data['names']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('cached', $data);
        $this->assertArrayHasKey('execution_time', $data);
        $this->assertArrayHasKey('cache_stats', $data);
    }

    /**
     * Test show method with valid ID
     *
     * @return void
     */
    public function testShowWithValidId(): void
    {
        $id = 'test-id';
        $expectedName = 'Test Object Name';
        
        $this->objectCacheService->method('getSingleObjectName')->with($id)->willReturn($expectedName);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertEquals($id, $data['id']);
        $this->assertEquals($expectedName, $data['name']);
        $this->assertTrue($data['found']);
        $this->assertTrue($data['cached']);
        $this->assertArrayHasKey('execution_time', $data);
    }

    /**
     * Test show method with non-existent ID
     *
     * @return void
     */
    public function testShowWithNonExistentId(): void
    {
        $id = 'non-existent-id';
        
        $this->objectCacheService->method('getSingleObjectName')->with($id)->willReturn(null);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        
        $data = $response->getData();
        $this->assertEquals($id, $data['id']);
        $this->assertNull($data['name']);
        $this->assertFalse($data['found']);
        $this->assertArrayHasKey('execution_time', $data);
    }

    /**
     * Test index method with cache miss
     *
     * @return void
     */
    public function testIndexWithCacheMiss(): void
    {
        $this->request->method('getParam')->with('ids')->willReturn(null);
        $this->objectCacheService->method('getAllObjectNames')->willReturn([]);
        $this->objectCacheService->method('getStats')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('names', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('cached', $data);
        $this->assertArrayHasKey('execution_time', $data);
        $this->assertArrayHasKey('cache_stats', $data);
    }

    /**
     * Test index method with malformed IDs parameter
     *
     * @return void
     */
    public function testIndexWithMalformedIds(): void
    {
        $this->request->method('getParam')->with('ids')->willReturn('invalid,malformed,ids');
        
        $this->objectCacheService->method('getMultipleObjectNames')->willReturn([]);
        $this->objectCacheService->method('getStats')->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('names', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('cached', $data);
        $this->assertArrayHasKey('execution_time', $data);
        $this->assertArrayHasKey('cache_stats', $data);
    }

}
