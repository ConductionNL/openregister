<?php

declare(strict_types=1);

/**
 * RevertControllerTest
 * 
 * Unit tests for the RevertController
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

use OCA\OpenRegister\Controller\RevertController;
use OCA\OpenRegister\Service\RevertService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the RevertController
 *
 * This test class covers all functionality of the RevertController
 * including object reversion and version management.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class RevertControllerTest extends TestCase
{
    /**
     * The RevertController instance being tested
     *
     * @var RevertController
     */
    private RevertController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock revert service
     *
     * @var MockObject|RevertService
     */
    private MockObject $revertService;

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
        $this->revertService = $this->createMock(RevertService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new RevertController(
            'openregister',
            $this->request,
            $this->revertService
        );
    }

    /**
     * Test revert method with successful object reversion
     *
     * @return void
     */
    public function testRevertSuccessful(): void
    {
        $objectId = 1;
        $versionId = 2;
        $revertedObject = ['id' => 1, 'name' => 'Reverted Object', 'version' => 2];

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('version_id')
            ->willReturn($versionId);

        $this->revertService->expects($this->once())
            ->method('revertToVersion')
            ->with($objectId, $versionId)
            ->willReturn($revertedObject);

        $response = $this->controller->revert($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($revertedObject, $response->getData());
    }

    /**
     * Test revert method with object not found
     *
     * @return void
     */
    public function testRevertObjectNotFound(): void
    {
        $objectId = 999;
        $versionId = 1;

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('version_id')
            ->willReturn($versionId);

        $this->revertService->expects($this->once())
            ->method('revertToVersion')
            ->willThrowException(new \Exception('Object not found'));

        $response = $this->controller->revert($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $response->getData());
    }

    /**
     * Test revert method with version not found
     *
     * @return void
     */
    public function testRevertVersionNotFound(): void
    {
        $objectId = 1;
        $versionId = 999;

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('version_id')
            ->willReturn($versionId);

        $this->revertService->expects($this->once())
            ->method('revertToVersion')
            ->willThrowException(new \Exception('Version not found'));

        $response = $this->controller->revert($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Version not found'], $response->getData());
    }

    /**
     * Test getVersions method with successful version listing
     *
     * @return void
     */
    public function testGetVersionsSuccessful(): void
    {
        $objectId = 1;
        $versions = [
            ['id' => 1, 'version' => 1, 'created_at' => '2024-01-01'],
            ['id' => 2, 'version' => 2, 'created_at' => '2024-01-02']
        ];

        $this->revertService->expects($this->once())
            ->method('getVersions')
            ->with($objectId)
            ->willReturn($versions);

        $response = $this->controller->getVersions($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($versions, $response->getData());
    }

    /**
     * Test getVersions method with object not found
     *
     * @return void
     */
    public function testGetVersionsObjectNotFound(): void
    {
        $objectId = 999;

        $this->revertService->expects($this->once())
            ->method('getVersions')
            ->with($objectId)
            ->willThrowException(new \Exception('Object not found'));

        $response = $this->controller->getVersions($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $response->getData());
    }

    /**
     * Test getVersion method with successful version retrieval
     *
     * @return void
     */
    public function testGetVersionSuccessful(): void
    {
        $objectId = 1;
        $versionId = 2;
        $version = ['id' => 2, 'version' => 2, 'data' => ['name' => 'Version 2']];

        $this->revertService->expects($this->once())
            ->method('getVersion')
            ->with($objectId, $versionId)
            ->willReturn($version);

        $response = $this->controller->getVersion($objectId, $versionId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($version, $response->getData());
    }

    /**
     * Test getVersion method with version not found
     *
     * @return void
     */
    public function testGetVersionNotFound(): void
    {
        $objectId = 1;
        $versionId = 999;

        $this->revertService->expects($this->once())
            ->method('getVersion')
            ->with($objectId, $versionId)
            ->willThrowException(new \Exception('Version not found'));

        $response = $this->controller->getVersion($objectId, $versionId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Version not found'], $response->getData());
    }

    /**
     * Test compareVersions method with successful version comparison
     *
     * @return void
     */
    public function testCompareVersionsSuccessful(): void
    {
        $objectId = 1;
        $version1Id = 1;
        $version2Id = 2;
        $comparison = [
            'version1' => ['id' => 1, 'version' => 1],
            'version2' => ['id' => 2, 'version' => 2],
            'differences' => [
                'name' => ['old' => 'Old Name', 'new' => 'New Name']
            ]
        ];

        $this->request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['version1_id', null, $version1Id],
                ['version2_id', null, $version2Id]
            ]);

        $this->revertService->expects($this->once())
            ->method('compareVersions')
            ->with($objectId, $version1Id, $version2Id)
            ->willReturn($comparison);

        $response = $this->controller->compareVersions($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($comparison, $response->getData());
    }

    /**
     * Test compareVersions method with missing version IDs
     *
     * @return void
     */
    public function testCompareVersionsMissingVersionIds(): void
    {
        $objectId = 1;

        $this->request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['version1_id', null, null],
                ['version2_id', null, null]
            ]);

        $response = $this->controller->compareVersions($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'Version IDs are required'], $response->getData());
    }

    /**
     * Test createSnapshot method with successful snapshot creation
     *
     * @return void
     */
    public function testCreateSnapshotSuccessful(): void
    {
        $objectId = 1;
        $snapshot = ['id' => 1, 'version' => 3, 'created_at' => '2024-01-03'];

        $this->revertService->expects($this->once())
            ->method('createSnapshot')
            ->with($objectId)
            ->willReturn($snapshot);

        $response = $this->controller->createSnapshot($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($snapshot, $response->getData());
    }

    /**
     * Test createSnapshot method with object not found
     *
     * @return void
     */
    public function testCreateSnapshotObjectNotFound(): void
    {
        $objectId = 999;

        $this->revertService->expects($this->once())
            ->method('createSnapshot')
            ->with($objectId)
            ->willThrowException(new \Exception('Object not found'));

        $response = $this->controller->createSnapshot($objectId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $response->getData());
    }

    /**
     * Test deleteVersion method with successful version deletion
     *
     * @return void
     */
    public function testDeleteVersionSuccessful(): void
    {
        $objectId = 1;
        $versionId = 2;

        $this->revertService->expects($this->once())
            ->method('deleteVersion')
            ->with($objectId, $versionId)
            ->willReturn(true);

        $response = $this->controller->deleteVersion($objectId, $versionId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test deleteVersion method with version not found
     *
     * @return void
     */
    public function testDeleteVersionNotFound(): void
    {
        $objectId = 1;
        $versionId = 999;

        $this->revertService->expects($this->once())
            ->method('deleteVersion')
            ->with($objectId, $versionId)
            ->willThrowException(new \Exception('Version not found'));

        $response = $this->controller->deleteVersion($objectId, $versionId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Version not found'], $response->getData());
    }
}
