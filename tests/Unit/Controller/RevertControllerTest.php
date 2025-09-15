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
     * Set up the test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->revertService = $this->createMock(RevertService::class);
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
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';
        $versionId = 2;
        $revertedObject = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $revertedObject->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Reverted Object', 'version' => 2]);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn(['version' => $versionId]);

        $this->revertService->expects($this->once())
            ->method('revert')
            ->with($register, $schema, $id, $versionId, false)
            ->willReturn($revertedObject);

        $response = $this->controller->revert($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['id' => 1, 'name' => 'Reverted Object', 'version' => 2], $response->getData());
    }

    /**
     * Test revert method with object not found
     *
     * @return void
     */
    public function testRevertObjectNotFound(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'non-existent-id';
        $versionId = 1;

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn(['version' => $versionId]);

        $this->revertService->expects($this->once())
            ->method('revert')
            ->with($register, $schema, $id, $versionId, false)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $response = $this->controller->revert($register, $schema, $id);

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
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';
        $versionId = 999;

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn(['version' => $versionId]);

        $this->revertService->expects($this->once())
            ->method('revert')
            ->with($register, $schema, $id, $versionId, false)
            ->willThrowException(new \Exception('Version not found'));

        $response = $this->controller->revert($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test revert method with missing parameters
     *
     * @return void
     */
    public function testRevertMissingParameters(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $response = $this->controller->revert($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'Must specify either datetime, auditTrailId, or version'], $response->getData());
    }
}